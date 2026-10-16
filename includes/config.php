<?php
declare(strict_types=1);

$composerAutoload = __DIR__ . '/../vendor/autoload.php';
if (is_file($composerAutoload)) {
    require_once $composerAutoload;
    if (class_exists(\Dotenv\Dotenv::class)) {
        \Dotenv\Dotenv::createImmutable(dirname(__DIR__))->safeLoad();
    }
}

function config_value(string $key, ?string $default = null): ?string
{
    $value = $_ENV[$key] ?? $_SERVER[$key] ?? getenv($key);
    return $value === false || $value === null ? $default : (string) $value;
}

function finora_pro_enabled(): bool
{
    return filter_var(config_value('FINORA_PRO_ENABLED', 'false'), FILTER_VALIDATE_BOOLEAN);
}