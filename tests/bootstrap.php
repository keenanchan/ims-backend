<?php

require __DIR__.'/../vendor/autoload.php';

// Force test environment variables before Laravel bootstraps.
// We set all three — putenv(), $_ENV, and $_SERVER — so that
// both Dotenv's immutability checks and Laravel's env() helper
// see 'sqlite' before the main .env is ever read.
$testEnv = [
    'APP_ENV' => 'testing',
    'DB_CONNECTION' => 'sqlite',
    'DB_DATABASE' => ':memory:',
    'JWT_SECRET' => 'YzvcZsadYTCpMIqoOzYH9146lMXMv6fhxj1t0SdjuXIuTje0igY0uU900xBIzIT3',
    'SESSION_DRIVER' => 'array',
];

foreach ($testEnv as $key => $value) {
    putenv("{$key}={$value}");
    $_ENV[$key] = $value;
    $_SERVER[$key] = $value;
}
