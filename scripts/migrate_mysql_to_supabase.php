<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/db.php';

$apply = in_array('--apply', $argv, true);
$sourceHost = (string) config_value('LEGACY_DB_HOST', '');
$sourceName = (string) config_value('LEGACY_DB_NAME', '');
$sourceUser = (string) config_value('LEGACY_DB_USER', '');
$sourcePassword = (string) config_value('LEGACY_DB_PASS', '');

if ($sourceHost === '' || $sourceName === '' || $sourceUser === '') {
    fwrite(STDERR, "Set LEGACY_DB_HOST, LEGACY_DB_NAME, LEGACY_DB_USER, and LEGACY_DB_PASS in your private .env.\n");
    exit(2);
}

$source = new PDO(
    'mysql:host=' . $sourceHost . ';dbname=' . $sourceName . ';charset=utf8mb4',
    $sourceUser,
    $sourcePassword,
    [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => true,
    ]
);

$sourceTables = array_flip($source->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN));
$tablesToCopy = [
    'transactions',
    'financial_profile',
    'investment_plan',
    'savings_goals',
    'ai_insights',
    'expense_predictions',
    'subscriptions',
    'notifications',
    'financial_health',
    'user_category_rules',
    'pro_subscriptions',
    'savings_goal_contributions',
    'jarvis_action_audit',
];
$report = ['users' => (int) $source->query('SELECT COUNT(*) FROM users')->fetchColumn()];
foreach ($tablesToCopy as $table) {
    if (isset($sourceTables[$table])) {
        $report[$table] = (int) $source->query('SELECT COUNT(*) FROM `' . $table . '`')->fetchColumn();
    } else {
        $report[$table] = 'source table missing; skipped';
    }
}

fwrite(STDOUT, "MySQL source preview (no destination writes):\n");
foreach ($report as $table => $count) {
    fwrite(STDOUT, sprintf("  %-30s %s\n", $table, (string) $count));
}
fwrite(STDOUT, "Legacy password hashes are not copied. Verified users must use Supabase password recovery to set a new password; unverified users must complete Supabase email confirmation.\n");

if (!$apply) {
    fwrite(STDOUT, "\nDry run only. Review counts, configure Supabase Auth email templates and SMTP, then rerun with --apply to create accounts and copy data.\n");
    exit(0);
}

$serviceKey = (string) config_value('SUPABASE_SERVICE_ROLE_KEY', '');
$supabaseUrl = rtrim((string) config_value('SUPABASE_URL', ''), '/');
if ($serviceKey === '' || $supabaseUrl === '') {
    fwrite(STDERR, "Set SUPABASE_URL and SUPABASE_SERVICE_ROLE_KEY in your private .env before applying the migration.\n");
    exit(2);
}
if (!function_exists('curl_init')) {
    fwrite(STDERR, "The PHP cURL extension is required to create Supabase Auth accounts.\n");
    exit(2);
}

fwrite(STDOUT, "\nThis will create/link Supabase Auth accounts and copy all listed source rows to the hosted Supabase database.\nType IMPORT to continue: ");
if (trim((string) fgets(STDIN)) !== 'IMPORT') {
    fwrite(STDOUT, "Cancelled; no destination changes were made.\n");
    exit(0);
}

$destination = db();
$destination->beginTransaction();
$userIdMap = [];
$tableIdMaps = [];
$imported = [];
$migrationEmails = [];
$destinationColumns = static function (PDO $connection, string $table): array {
    $statement = $connection->prepare(
        "SELECT column_name FROM information_schema.columns WHERE table_schema = 'public' AND table_name = :table"
    );
    $statement->execute(['table' => $table]);
    $columns = $statement->fetchAll(PDO::FETCH_COLUMN);
    if (!$columns) {
        throw new RuntimeException('Target table is missing from the Supabase migration: ' . $table);
    }
    return array_flip($columns);
};
$supabaseAdminRequest = static function (string $path, array $payload) use ($supabaseUrl, $serviceKey): array {
    $curl = curl_init($supabaseUrl . '/auth/v1/admin/' . $path);
    if ($curl === false) {
        throw new RuntimeException('Could not initialize the Supabase Auth admin request.');
    }
    curl_setopt_array($curl, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES),
        CURLOPT_HTTPHEADER => [
            'apikey: ' . $serviceKey,
            'Authorization: Bearer ' . $serviceKey,
            'Content-Type: application/json',
            'Accept: application/json',
        ],
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_TIMEOUT => 30,
    ]);
    $body = curl_exec($curl);
    $status = (int) curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
    $curlError = curl_error($curl);
    curl_close($curl);
    if (!is_string($body)) {
        throw new RuntimeException('Supabase Auth admin request failed: ' . $curlError);
    }
    $response = json_decode($body, true);
    if ($status < 200 || $status >= 300 || !is_array($response)) {
        throw new RuntimeException('Supabase Auth admin request failed with HTTP ' . $status . '. Check project Auth settings and account conflicts.');
    }
    return $response;
};
$createAuthUser = static function (array $legacyUser) use ($supabaseAdminRequest): string {
    $payload = [
        'email' => strtolower((string) $legacyUser['email']),
        'password' => bin2hex(random_bytes(32)),
        'email_confirm' => $legacyUser['email_verified_at'] !== null,
        'user_metadata' => ['name' => (string) $legacyUser['name']],
    ];
    $response = $supabaseAdminRequest('users', $payload);
    if (!isset($response['id'])) {
        throw new RuntimeException('Supabase Auth account creation returned no user ID. Resolve duplicate emails or Auth configuration before retrying.');
    }
    return (string) $response['id'];
};

try {
    $sourceUsers = $source->query('SELECT id, name, email, pending_email, email_verified_at FROM users ORDER BY id');
    $findDestinationUser = $destination->prepare(
        'SELECT id, auth_user_id, email_verified_at, legacy_mysql_id FROM users WHERE email = :email OR legacy_mysql_id = :legacy_id LIMIT 1'
    );
        $linkLegacyId = $destination->prepare(
            'UPDATE users SET legacy_mysql_id = :legacy_id, pending_email = :pending_email WHERE id = :id AND (legacy_mysql_id IS NULL OR legacy_mysql_id = :same_legacy_id)'
    );
    foreach ($sourceUsers as $legacyUser) {
        $legacyId = (int) $legacyUser['id'];
        $findDestinationUser->execute([
            'email' => strtolower((string) $legacyUser['email']),
            'legacy_id' => $legacyId,
        ]);
        $existing = $findDestinationUser->fetch();
        if ($existing) {
            if ($existing['legacy_mysql_id'] !== null && (int) $existing['legacy_mysql_id'] !== $legacyId) {
                throw new RuntimeException('A legacy user ID is already linked to a different Supabase account.');
            }
            if ($existing['email_verified_at'] === null || $legacyUser['email_verified_at'] === null) {
                throw new RuntimeException('An unverified or conflicting existing email needs manual account resolution before migration.');
            }
            $localUserId = (int) $existing['id'];
            $authUserId = (string) $existing['auth_user_id'];
        } else {
            $authUserId = $createAuthUser($legacyUser);
            $lookup = $destination->prepare('SELECT id FROM users WHERE auth_user_id = :auth_user_id LIMIT 1');
            $lookup->execute(['auth_user_id' => $authUserId]);
            $localUserId = (int) $lookup->fetchColumn();
            if ($localUserId < 1) {
                throw new RuntimeException('Supabase Auth account was created but the database trigger did not create its Finora profile.');
            }
        }
        $linkLegacyId->execute([
            'legacy_id' => $legacyId,
            'pending_email' => $legacyUser['pending_email'],
            'id' => $localUserId,
            'same_legacy_id' => $legacyId,
        ]);
        if ($linkLegacyId->rowCount() !== 1) {
            throw new RuntimeException('Could not link legacy user ID ' . $legacyId . ' to its Supabase identity.');
        }
        $userIdMap[$legacyId] = $localUserId;
        $emailType = $legacyUser['email_verified_at'] !== null ? 'recovery' : 'signup';
        $emailLink = $supabaseAdminRequest('generate_link', [
            'type' => $emailType,
            'email' => strtolower((string) $legacyUser['email']),
            'redirect_to' => rtrim((string) config_value('SUPABASE_SITE_URL', ''), '/'),
        ]);
        if (!isset($emailLink['action_link'])) {
            throw new RuntimeException('Supabase did not generate an account migration email link.');
        }
        $migrationEmails[] = [
            'email' => (string) $legacyUser['email'],
            'name' => (string) $legacyUser['name'],
            'type' => $emailType,
            'link' => (string) $emailLink['action_link'],
        ];
    }

    foreach ($tablesToCopy as $table) {
        if (!isset($sourceTables[$table])) {
            continue;
        }
        $targetColumns = $destinationColumns($destination, $table);
        $rows = $source->query('SELECT * FROM `' . $table . '` ORDER BY id');
        $idMap = [];
        $count = 0;
        foreach ($rows as $row) {
            $legacyRowId = isset($row['id']) ? (int) $row['id'] : null;
            if (isset($row['user_id'])) {
                $legacyOwner = (int) $row['user_id'];
                if (!isset($userIdMap[$legacyOwner])) {
                    throw new RuntimeException('Found a row with no migrated owner in ' . $table . '.');
                }
                $row['user_id'] = $userIdMap[$legacyOwner];
            }
            if ($table === 'savings_goal_contributions') {
                $legacyGoalId = (int) $row['goal_id'];
                if (!isset($tableIdMaps['savings_goals'][$legacyGoalId])) {
                    throw new RuntimeException('A savings contribution refers to a goal that was not migrated.');
                }
                $row['goal_id'] = $tableIdMaps['savings_goals'][$legacyGoalId];
            }
            unset($row['id']);
            $row = array_intersect_key($row, $targetColumns);
            if (!$row) {
                continue;
            }
            $columnNames = array_keys($row);
            $quotedColumns = implode(', ', array_map(static fn (string $column): string => '"' . $column . '"', $columnNames));
            $placeholders = implode(', ', array_fill(0, count($columnNames), '?'));
            $sql = 'INSERT INTO public."' . $table . '" (' . $quotedColumns . ') VALUES (' . $placeholders . ')';
            if ($table === 'financial_profile') {
                $updates = array_values(array_diff($columnNames, ['user_id']));
                if ($updates) {
                    $assignments = implode(', ', array_map(static fn (string $column): string => '"' . $column . '" = EXCLUDED."' . $column . '"', $updates));
                    $sql .= ' ON CONFLICT (user_id) DO UPDATE SET ' . $assignments;
                } else {
                    $sql .= ' ON CONFLICT (user_id) DO NOTHING';
                }
                $sql .= ' RETURNING id';
            } elseif ($legacyRowId !== null) {
                $sql .= ' RETURNING id';
            }
            $insert = $destination->prepare($sql);
            $insert->execute(array_values($row));
            if ($legacyRowId !== null && $insert->columnCount() > 0) {
                $newRowId = (int) $insert->fetchColumn();
                if ($newRowId > 0) {
                    $idMap[$legacyRowId] = $newRowId;
                }
            }
            $count += 1;
        }
        if ($idMap) {
            $tableIdMaps[$table] = $idMap;
        }
        $imported[$table] = $count;
    }
    $destination->commit();
} catch (Throwable $exception) {
    if ($destination->inTransaction()) {
        $destination->rollBack();
    }
    fwrite(STDERR, "Migration stopped: " . $exception->getMessage() . "\n");
    fwrite(STDERR, "Supabase Auth accounts created before the failure cannot be rolled back; rerun only after resolving the reported conflict and checking those accounts.\n");
    exit(1);
}

fwrite(STDOUT, "\nDatabase copy committed. Imported rows:\n");
foreach ($imported as $table => $count) {
    fwrite(STDOUT, sprintf("  %-30s %d\n", $table, $count));
}
if (!is_file(__DIR__ . '/../vendor/autoload.php')) {
    fwrite(STDERR, "Database data is imported, but migration links were not emailed because Composer dependencies are missing.\n");
    exit(1);
}
require_once __DIR__ . '/../vendor/autoload.php';
$failedEmails = [];
foreach ($migrationEmails as $item) {
    try {
        $mailer = new \PHPMailer\PHPMailer\PHPMailer(true);
        $mailer->isSMTP();
        $mailer->Host = (string) config_value('SMTP_HOST', '');
        $mailer->SMTPAuth = true;
        $mailer->Username = (string) config_value('SMTP_USERNAME', '');
        $mailer->Password = (string) config_value('SMTP_PASSWORD', '');
        $mailer->SMTPSecure = (string) config_value('SMTP_ENCRYPTION', 'tls');
        $mailer->Port = (int) config_value('SMTP_PORT', '587');
        $mailer->CharSet = 'UTF-8';
        $mailer->Timeout = 15;
        $fromAddress = (string) config_value('SMTP_FROM_ADDRESS', $mailer->Username);
        $mailer->setFrom($fromAddress, (string) config_value('SMTP_FROM_NAME', 'Finora'));
        $mailer->addAddress($item['email'], $item['name']);
        $mailer->isHTML(true);
        $subject = $item['type'] === 'recovery' ? 'Set your new Finora password' : 'Verify your Finora email';
        $action = $item['type'] === 'recovery' ? 'set a new password' : 'verify your email address';
        $link = htmlspecialchars($item['link'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $mailer->Subject = $subject;
        $mailer->Body = '<p>Hello ' . htmlspecialchars($item['name'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . ',</p><p>We moved Finora to Supabase. Use this secure link to ' . $action . ' and access your migrated data.</p><p><a href="' . $link . '">Continue to Finora</a></p><p>If you did not request this, contact Finora support.</p>';
        $mailer->AltBody = "Hello {$item['name']},\n\nWe moved Finora to Supabase. Use this link to {$action} and access your migrated data:\n{$item['link']}\n";
        $mailer->send();
    } catch (Throwable $exception) {
        $failedEmails[] = $item['email'];
    }
}
if ($failedEmails) {
    fwrite(STDERR, "\nData imported. Some account migration emails could not be sent; regenerate Supabase Auth recovery/confirmation links for: " . implode(', ', $failedEmails) . "\n");
    exit(1);
}
fwrite(STDOUT, "\nData and account migration links completed. Legacy password hashes were not copied; verified users set a new password via recovery, and unverified users still need to confirm their email.\n");
