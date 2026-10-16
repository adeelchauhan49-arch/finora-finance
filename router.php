<?php
declare(strict_types=1);

$requestPath = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
$requestPath = is_string($requestPath) ? $requestPath : '/';

for ($decodePass = 0; $decodePass < 3; $decodePass++) {
    $decodedPath = rawurldecode($requestPath);
    if ($decodedPath === $requestPath) {
        break;
    }
    $requestPath = $decodedPath;
}

$pathSegments = explode('/', str_replace('\\', '/', $requestPath));
$blockedFiles = ['composer.json', 'composer.lock', 'setup.md', 'starter.py', 'router.php'];
$blockedDirectories = ['vendor', 'database', 'tests'];

foreach ($pathSegments as $segment) {
    $segment = strtolower($segment);
    if (preg_match('/^\.env(?:\..*)?$/', $segment) === 1
        || str_starts_with($segment, '.git')
        || in_array($segment, $blockedFiles, true)
        || in_array($segment, $blockedDirectories, true)) {
        http_response_code(403);
        header('Content-Type: text/plain; charset=utf-8');
        echo 'Forbidden';
        return true;
    }
}

return false;
