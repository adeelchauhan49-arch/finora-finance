<?php
declare(strict_types=1);

function transaction_time_is_valid(string $time): bool
{
    return preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $time) === 1;
}
