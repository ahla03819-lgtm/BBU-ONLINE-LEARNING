<?php

declare(strict_types=1);

/**
 * Refuse to let the database-queue regression fall back to a developer's .env.
 * Values are supplied only by an explicitly provisioned disposable environment.
 */
$databaseEnvironment = [
    'BBU_DBQ_TEST_HOST' => 'DB_HOST',
    'BBU_DBQ_TEST_PORT' => 'DB_PORT',
    'BBU_DBQ_TEST_DATABASE' => 'DB_DATABASE',
    'BBU_DBQ_TEST_USERNAME' => 'DB_USERNAME',
    'BBU_DBQ_TEST_PASSWORD' => 'DB_PASSWORD',
];

$missing = [];
$values = [];

foreach ($databaseEnvironment as $source => $target) {
    $value = getenv($source);

    if ($value === false || trim($value) === '') {
        $missing[] = $source;
        continue;
    }

    $values[$target] = $value;
}

if ($missing !== []) {
    fwrite(STDERR, 'Database-queue regression requires explicit disposable database variables: '.implode(', ', $missing).PHP_EOL);
    exit(2);
}

foreach ($values as $target => $value) {
    putenv("{$target}={$value}");
    $_ENV[$target] = $value;
    $_SERVER[$target] = $value;
}

putenv('DB_URL=');
$_ENV['DB_URL'] = '';
$_SERVER['DB_URL'] = '';
