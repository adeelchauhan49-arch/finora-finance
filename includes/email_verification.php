<?php
declare(strict_types=1);

function email_verification_generic_message(): string
{
    return 'If this email can be verified, a code has been sent.';
}

function email_verification_send_email(string $email, string $name, string $otp): bool
{
    $autoload = __DIR__ . '/../vendor/autoload.php';
    if (!is_file($autoload)) {
        error_log('Email verification send: failed.');
        return false;
    }
    require_once $autoload;
    if (!class_exists(\PHPMailer\PHPMailer\PHPMailer::class)) {
        error_log('Email verification send: failed.');
        return false;
    }

    $host = (string) config_value('SMTP_HOST', '');
    $username = (string) config_value('SMTP_USERNAME', '');
    $password = (string) config_value('SMTP_PASSWORD', '');
    $fromAddress = (string) config_value('SMTP_FROM_ADDRESS', $username);
    if ($host === '' || $username === '' || $password === '' || !filter_var($fromAddress, FILTER_VALIDATE_EMAIL)) {
        error_log('Email verification send: failed.');
        return false;
    }

    try {
        $mailer = new \PHPMailer\PHPMailer\PHPMailer(true);
        $mailer->isSMTP();
        $mailer->Host = $host;
        $mailer->SMTPAuth = true;
        $mailer->Username = $username;
        $mailer->Password = $password;
        $mailer->SMTPSecure = (string) config_value('SMTP_ENCRYPTION', 'tls');
        $mailer->Port = (int) config_value('SMTP_PORT', '587');
        $mailer->CharSet = 'UTF-8';
        $mailer->Timeout = 10;
        $mailer->setFrom($fromAddress, 'Finora Finance');
        $mailer->addReplyTo($fromAddress, 'Finora Finance');
        $mailer->addAddress($email, $name);
        $domain = substr(strrchr($fromAddress, '@'), 1);
        $mailer->MessageID = '<' . bin2hex(random_bytes(16)) . '@' . $domain . '>';
        $mailer->Subject = "Finora: your verification code is {$otp}";
        $mailer->isHTML(true);
        $mailer->Body = '<html><body><p>Use this code to verify your Finora account.</p><p>' . $otp . '</p><p>This code expires in 10 minutes.</p><p>If you didn\'t request this, you can ignore this email.</p></body></html>';
        $mailer->AltBody = "Use this code to verify your Finora account.\n{$otp}\nThis code expires in 10 minutes.\nIf you didn't request this, you can ignore this email.";
        if (!$mailer->send()) {
            error_log('Email verification send: failed.');
            return false;
        }
        error_log('Email verification send: succeeded.');
        return true;
    } catch (Throwable $exception) {
        error_log('Email verification send: failed.');
        return false;
    }
}

function email_verification_generic_response(float $startedAt): never
{
    $minimumResponseSeconds = 1.0;
    $remaining = $minimumResponseSeconds - (microtime(true) - $startedAt);
    if ($remaining > 0) {
        usleep((int) ceil($remaining * 1000000));
    }
    json_response([
        'ok' => true,
        'message' => email_verification_generic_message(),
        'cooldown_seconds' => 60,
    ], 200);
}

function email_verification_user_for_target(PDO $connection, string $email, bool $lock = false): ?array
{
    $sql = 'SELECT id, name, email, pending_email, email_verified_at FROM users
            WHERE email = :email OR pending_email = :pending_email
            ORDER BY (pending_email = :email_order) DESC LIMIT 1';
    if ($lock && $connection->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql') {
        $sql .= ' FOR UPDATE';
    }
    $statement = $connection->prepare($sql);
    $statement->execute(['email' => $email, 'pending_email' => $email, 'email_order' => $email]);
    $user = $statement->fetch(PDO::FETCH_ASSOC);
    return is_array($user) ? $user : null;
}

function email_verification_send_for_user(
    PDO $connection,
    int $userId,
    string $email,
    ?callable $deliver = null,
    ?int $now = null
): array {
    $now ??= time();
    $email = strtolower(trim($email));
    if ($userId < 1 || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        return ['sent' => false, 'reason' => 'invalid'];
    }

    $driver = $connection->getAttribute(PDO::ATTR_DRIVER_NAME);
    $ownsTransaction = !$connection->inTransaction();
    if ($ownsTransaction) {
        $connection->beginTransaction();
    }
    try {
        $userSql = 'SELECT id, name, email, pending_email FROM users WHERE id = :user_id LIMIT 1';
        if ($driver === 'mysql') {
            $userSql .= ' FOR UPDATE';
        }
        $userStatement = $connection->prepare($userSql);
        $userStatement->execute(['user_id' => $userId]);
        $user = $userStatement->fetch(PDO::FETCH_ASSOC);
        if (!$user || ($user['email'] !== $email && $user['pending_email'] !== $email)) {
            if ($ownsTransaction) {
                $connection->commit();
            }
            return ['sent' => false, 'reason' => 'unavailable'];
        }

        $history = $connection->prepare(
            'SELECT created_at FROM email_verification_otps
             WHERE target_email = :email AND created_at >= :hour_ago ORDER BY id DESC'
        );
        $hourAgo = date('Y-m-d H:i:s', $now - 3600);
        $history->execute(['email' => $email, 'hour_ago' => $hourAgo]);
        $recent = $history->fetchAll(PDO::FETCH_COLUMN);
        if ($recent) {
            $lastSent = strtotime((string) $recent[0]);
            if ($lastSent !== false && $now - $lastSent < 60) {
                if ($ownsTransaction) {
                    $connection->commit();
                }
                return ['sent' => false, 'reason' => 'cooldown'];
            }
        }
        if (count($recent) >= 5) {
            if ($ownsTransaction) {
                $connection->commit();
            }
            return ['sent' => false, 'reason' => 'hourly_limit'];
        }

        $connection->prepare(
            'UPDATE email_verification_otps SET invalidated_at = CURRENT_TIMESTAMP
             WHERE user_id = :user_id AND target_email = :email AND consumed_at IS NULL AND invalidated_at IS NULL'
        )->execute(['user_id' => $userId, 'email' => $email]);
        $otp = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);
        $insert = $connection->prepare(
            'INSERT INTO email_verification_otps (user_id, target_email, otp_hash, expires_at, attempts, created_at)
             VALUES (:user_id, :email, :otp_hash, :expires_at, 0, :created_at)'
        );
        $insert->execute([
            'user_id' => $userId,
            'email' => $email,
            'otp_hash' => password_hash($otp, PASSWORD_DEFAULT),
            'expires_at' => date('Y-m-d H:i:s', $now + 600),
            'created_at' => date('Y-m-d H:i:s', $now),
        ]);
        $otpId = (int) $connection->lastInsertId();
        if ($ownsTransaction) {
            $connection->commit();
        }

        $deliver ??= 'email_verification_send_email';
        $sent = $deliver($email, (string) $user['name'], $otp);
        if (!$sent) {
            $connection->prepare(
                'UPDATE email_verification_otps SET invalidated_at = CURRENT_TIMESTAMP WHERE id = :id AND user_id = :user_id'
            )->execute(['id' => $otpId, 'user_id' => $userId]);
            error_log('Email verification OTP delivery failed; response kept generic.');
        }
        return ['sent' => $sent, 'reason' => $sent ? 'sent' : 'delivery_failed'];
    } catch (Throwable $exception) {
        if ($ownsTransaction && $connection->inTransaction()) {
            $connection->rollBack();
        }
        error_log('Email verification OTP request failed.');
        return ['sent' => false, 'reason' => 'unavailable'];
    }
}

function email_verification_check_code(PDO $connection, int $userId, string $email, string $otp, ?int $now = null): array
{
    $now ??= time();
    $email = strtolower(trim($email));
    if ($userId < 1 || !filter_var($email, FILTER_VALIDATE_EMAIL) || !preg_match('/^\d{6}$/', $otp)) {
        return ['verified' => false, 'reason' => 'invalid'];
    }
    $driver = $connection->getAttribute(PDO::ATTR_DRIVER_NAME);
    $ownsTransaction = !$connection->inTransaction();
    if ($ownsTransaction) {
        $connection->beginTransaction();
    }
    try {
        $sql = 'SELECT id, otp_hash, expires_at, attempts FROM email_verification_otps
                WHERE user_id = :user_id AND target_email = :email AND consumed_at IS NULL AND invalidated_at IS NULL
                ORDER BY id DESC LIMIT 1';
        if ($driver === 'mysql') {
            $sql .= ' FOR UPDATE';
        }
        $statement = $connection->prepare($sql);
        $statement->execute(['user_id' => $userId, 'email' => $email]);
        $record = $statement->fetch(PDO::FETCH_ASSOC);
        if (!$record) {
            if ($ownsTransaction) {
                $connection->commit();
            }
            return ['verified' => false, 'reason' => 'invalid'];
        }
        $expiry = strtotime((string) $record['expires_at']);
        if ($expiry === false || $expiry < $now) {
            $connection->prepare('UPDATE email_verification_otps SET invalidated_at = CURRENT_TIMESTAMP WHERE id = :id')
                ->execute(['id' => $record['id']]);
            if ($ownsTransaction) {
                $connection->commit();
            }
            return ['verified' => false, 'reason' => 'expired'];
        }
        if ((int) $record['attempts'] >= 5) {
            $connection->prepare('UPDATE email_verification_otps SET invalidated_at = CURRENT_TIMESTAMP WHERE id = :id')
                ->execute(['id' => $record['id']]);
            if ($ownsTransaction) {
                $connection->commit();
            }
            return ['verified' => false, 'reason' => 'too_many_attempts'];
        }
        if (!password_verify($otp, (string) $record['otp_hash'])) {
            $attempts = (int) $record['attempts'] + 1;
            $connection->prepare(
                'UPDATE email_verification_otps SET attempts = :attempts, invalidated_at = CASE WHEN CAST(:lock_attempts AS INTEGER) >= 5 THEN CURRENT_TIMESTAMP ELSE invalidated_at END WHERE id = :id'
            )->execute(['attempts' => $attempts, 'lock_attempts' => $attempts, 'id' => $record['id']]);
            if ($ownsTransaction) {
                $connection->commit();
            }
            return ['verified' => false, 'reason' => $attempts >= 5 ? 'too_many_attempts' : 'incorrect'];
        }

        $user = email_verification_user_for_target($connection, $email, $driver === 'mysql');
        if (!$user || (int) $user['id'] !== $userId) {
            if ($ownsTransaction) {
                $connection->commit();
            }
            return ['verified' => false, 'reason' => 'invalid'];
        }
        if ($user['pending_email'] === $email) {
            $connection->prepare(
                'UPDATE users SET email = :email, pending_email = NULL, email_verified_at = COALESCE(email_verified_at, CURRENT_TIMESTAMP) WHERE id = :user_id'
            )->execute(['email' => $email, 'user_id' => $userId]);
        } elseif ($user['email'] === $email && $user['email_verified_at'] === null) {
            $connection->prepare('UPDATE users SET email_verified_at = CURRENT_TIMESTAMP WHERE id = :user_id')
                ->execute(['user_id' => $userId]);
        } else {
            if ($ownsTransaction) {
                $connection->commit();
            }
            return ['verified' => false, 'reason' => 'invalid'];
        }
        $connection->prepare('UPDATE email_verification_otps SET consumed_at = CURRENT_TIMESTAMP WHERE id = :id')
            ->execute(['id' => $record['id']]);
        if ($ownsTransaction) {
            $connection->commit();
        }
        $refreshed = $connection->prepare('SELECT id, name, email FROM users WHERE id = :user_id LIMIT 1');
        $refreshed->execute(['user_id' => $userId]);
        $verifiedUser = $refreshed->fetch(PDO::FETCH_ASSOC);
        return ['verified' => true, 'reason' => 'verified', 'user' => $verifiedUser ?: null];
    } catch (Throwable $exception) {
        if ($ownsTransaction && $connection->inTransaction()) {
            $connection->rollBack();
        }
        error_log('Email verification code check failed.');
        return ['verified' => false, 'reason' => 'unavailable'];
    }
}

function email_verification_stage_email(PDO $connection, int $userId, string $email): bool
{
    $email = strtolower(trim($email));
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        return false;
    }
    $statement = $connection->prepare(
        'SELECT id FROM users WHERE id <> :user_id AND (email = :email OR pending_email = :pending_email) LIMIT 1'
    );
    $statement->execute(['user_id' => $userId, 'email' => $email, 'pending_email' => $email]);
    if ($statement->fetchColumn()) {
        return false;
    }
    $statement = $connection->prepare(
        'UPDATE users SET pending_email = :email WHERE id = :user_id AND email <> :same_email'
    );
    $statement->execute(['email' => $email, 'user_id' => $userId, 'same_email' => $email]);
    return true;
}
