# Screen-share database-queue regression

## Purpose

`tests/Isolated/DatabaseQueue/DatabaseQueueScreenShareWebhookRegressionTest.php`
preserves the worker-backed webhook regression that was previously verified in a
disposable Guest-F2.1 Candidate Revision 03 runtime. It supplements, and does
not replace, the synchronous test in
`tests/Feature/Phase7/MeetingScreenShareApprovalTest.php`.

It intentionally sits outside `tests/Feature`: the default PHPUnit suite and
CI run `php artisan test`, which recursively collects that directory with an
in-memory SQLite/synchronous-queue configuration. This regression instead
requires a dedicated disposable MariaDB schema with database queue/cache.
Run it only through `phpunit.database-queue.xml`.

Origin checksum: `62bc33e62e06abd71883e5f790e57cdd3a4c6a577519be45a23dcb6d9f134d3b`.
The two regression cases and their assertions are preserved from that origin.
The only test-infrastructure addition is a pre-bootstrap sentinel that refuses
direct/default-config invocation before Laravel can access a database.

## Coverage

- `test_duplicate_delivery_persists_one_job_and_worker_transitions_once`
- `test_worker_failure_is_durable_and_reconciler_retries_once`

The tests bind `DatabaseQueueWebhookFakeRoomManager`; they do not call LiveKit.
They require a current StudentProfile, active enrollment, matching open
attendance session and provider presence, and a valid signed webhook payload.
The queue worker is bounded to eight jobs and stops when empty.

## Safe execution

Provision a new disposable MariaDB schema and a dedicated local database user.
Do not use a MAMP, shared, or production database. Export the following values
only for that disposable schema; do not save them in tracked files:

```text
BBU_DBQ_TEST_HOST
BBU_DBQ_TEST_PORT
BBU_DBQ_TEST_DATABASE
BBU_DBQ_TEST_USERNAME
BBU_DBQ_TEST_PASSWORD
```

Run forward-only migrations with those values mapped to Laravel's `DB_*`
variables, then run:

```text
php vendor/bin/phpunit --configuration=phpunit.database-queue.xml
```

`tests/bootstrap-database-queue.php` refuses to run if any of the five
explicit variables is missing, preventing fallback to an active `.env` database.
The PHPUnit configuration forces testing, database queue/cache, null broadcast,
and the dedicated `webhook-regression` queue. It contains no hostname, port,
database name, username, or password.

Use a fresh schema for every run. Before running PHPUnit, apply forward-only
migrations only to that schema. The isolated class also requires the
`BBU_DBQ_TEST_CONFIG=1` sentinel supplied by this configuration; direct/default
invocation fails before Laravel bootstraps.

After the run, stop and remove only the specifically named disposable database
container created for this test. Do not target shared database containers,
application queue workers, schedulers, or other development services.

## Discovery verification

The default suite must not list this class:

```text
php vendor/bin/phpunit --configuration=phpunit.xml --list-tests
```

The dedicated configuration must list exactly its two methods:

```text
php vendor/bin/phpunit --configuration=phpunit.database-queue.xml --list-tests
```

## Evidence and limitations

Historical disposable verification: 2 tests, 41 assertions, exit code 0.
Fresh isolated verification on 2026-10-25: 2 tests, 41 assertions, exit code 0.
The regression must remain covered by a local Git commit before it is protected
by repository history; later integration still requires separate authorization.
Candidate Revision 03 remains frozen and is not modified by this test
preservation.
