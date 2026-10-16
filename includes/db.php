<?php
declare(strict_types=1);

require_once __DIR__ . '/config.php';

function db(): PDO
{
    static $connection;

    if ($connection instanceof PDO) {
        return $connection;
    }

    $databaseUrl = trim((string) config_value('SUPABASE_DB_URL', ''));
    if ($databaseUrl === '') {
        throw new RuntimeException('SUPABASE_DB_URL is not configured.');
    }
    $parts = parse_url($databaseUrl);
    if (!is_array($parts)
        || !isset($parts['scheme'], $parts['host'], $parts['user'], $parts['pass'], $parts['path'])
        || !in_array(strtolower((string) $parts['scheme']), ['postgres', 'postgresql'], true)) {
        throw new RuntimeException('SUPABASE_DB_URL must be a PostgreSQL connection URL.');
    }
    $query = [];
    parse_str((string) ($parts['query'] ?? ''), $query);
    $sslMode = (string) ($query['sslmode'] ?? 'require');
    if (!in_array($sslMode, ['require', 'verify-ca', 'verify-full'], true)) {
        throw new RuntimeException('SUPABASE_DB_URL has an unsupported sslmode.');
    }
    $database = ltrim((string) $parts['path'], '/');
    if ($database === '') {
        throw new RuntimeException('SUPABASE_DB_URL must include a database name.');
    }

    $connection = new PDO(
        'pgsql:host=' . $parts['host']
            . ';port=' . (int) ($parts['port'] ?? 5432)
            . ';dbname=' . $database
            . ';sslmode=' . $sslMode,
        rawurldecode((string) $parts['user']),
        rawurldecode((string) $parts['pass']),
        [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => true,
        ]
    );

    $authUser = $GLOBALS['finoraSupabaseAuthUser'] ?? null;
    if (is_array($authUser) && isset($authUser['id'])) {
        $claims = json_encode([
            'sub' => (string) $authUser['id'],
            'role' => 'authenticated',
            'email' => (string) ($authUser['email'] ?? ''),
        ], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        $claimsStatement = $connection->prepare("SELECT set_config('request.jwt.claims', :claims, false)");
        $claimsStatement->execute(['claims' => $claims]);
        $subjectStatement = $connection->prepare("SELECT set_config('request.jwt.claim.sub', :subject, false)");
        $subjectStatement->execute(['subject' => (string) $authUser['id']]);
        $connection->exec('SET ROLE finora_api');
    }

    return $connection;
}

function json_response(array $payload, int $status = 200)
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($payload, JSON_UNESCAPED_SLASHES);
    exit;
}

function request_body(): array
{
    $input = json_decode(file_get_contents('php://input'), true);
    $data = is_array($input) ? $input : $_POST;
    $GLOBALS['finoraRequestData'] = $data;
    return $data;
}
