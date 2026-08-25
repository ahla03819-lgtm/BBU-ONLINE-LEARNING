# EDWAY School

Laravel 13 modular-monolith foundation using PHP 8.3+, React, Inertia, Tailwind, MySQL, PHPUnit, and Spatie Laravel Permission.

## Local setup

1. Copy `.env.example` to `.env` and configure a MySQL database.
2. Run `composer install` and `php artisan key:generate`.
3. Run `npm install`.
4. Run `php artisan migrate --seed`.
5. Create the initial production administrator interactively with `php artisan edway:create-super-admin`. Never seed or script a production password.
6. Run `composer run dev`, or run the Laravel and Vite development processes separately.

## Realtime messaging

Phase 4 uses MySQL as the message source of truth and Laravel Reverb only for realtime delivery. Configure the `REVERB_*` and `VITE_REVERB_*` values documented in `.env.example`. Never expose `REVERB_APP_SECRET` through a `VITE_` variable.

For local realtime development, run these supervised processes in addition to the web and Vite processes:

```shell
php artisan reverb:start
php artisan queue:work
```

The initial deployment target is one Reverb node and does not require Redis. Production must use TLS, a restricted `REVERB_ALLOWED_ORIGINS` value, supervised Reverb and queue workers, and deployment-specific credentials. Redis should be introduced before horizontal Reverb scaling. If realtime delivery is interrupted, clients recover missed messages from the authorized HTTP timeline using message-ID cursors.

Tests: `php artisan test`. Production frontend: `npm run build`.

## Authentication and account policy

The first-party web client uses Laravel sessions. There is no public registration. Administrators create accounts and the application sends Laravel's email-verification notification. Protected application routes require an active and verified account. Configure a real mail service through environment variables outside Git.

Production must use HTTPS, `APP_DEBUG=false`, `SESSION_SECURE_COOKIE=true`, protected secrets, supervised workers, and an appropriately restricted database account.

## Authorization

`RolePermissionSeeder` creates Super Admin, Admin, Teacher, and Student roles. Permissions are coarse capabilities. Future resources must additionally use Laravel policies and resource relationships. A Teacher role never implies access to all classes. The final active Super Admin is protected by transactional server-side actions.

## Audit foundation

Account creation, identity/status changes, role changes, deletion, and initial Super Admin creation write append-oriented audit records. Passwords, hashes, reset tokens, session identifiers, and secrets are excluded. Audit records have no update or delete application workflow.

## Branch workflow

Phase 1 is developed on `feature/phase-1-foundation-auth-rbac`. Changes require review and passing CI before any owner-approved merge to `main`.
