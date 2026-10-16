<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/config.php';

header('Content-Type: application/javascript; charset=utf-8');
header('Cache-Control: no-store');
echo 'window.FINORA_SUPABASE_CONFIG = ' . json_encode([
    'url' => rtrim((string) config_value('SUPABASE_URL', ''), '/'),
    'publishableKey' => (string) config_value('SUPABASE_PUBLISHABLE_KEY', ''),
], JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_SLASHES) . ';';
