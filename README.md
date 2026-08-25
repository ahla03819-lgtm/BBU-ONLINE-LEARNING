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

## Message attachments and reactions

Messages support up to five private attachments, 10 MB per file and 25 MB combined. Supported formats are JPEG, PNG, WebP, PDF, DOCX, XLSX, PPTX, TXT, and CSV. Files are validated on the server, stored under generated private object keys on `MESSAGE_ATTACHMENT_DISK` (the private `local` disk by default), and delivered only through relationship-scoped authorized routes. They are never exposed through `public/storage` or permanent public URLs. Production may select a private S3-compatible disk after installing and configuring the appropriate Laravel filesystem adapter.

Attachment-only messages are supported. Attachment objects are immutable and retained with hidden messages; ordinary hidden-message payloads expose no attachment metadata. Administrators require the explicit `attachments.view-hidden` permission to access retained hidden binaries. Malware scanning is intentionally deferred and must be added as production infrastructure before treating uploads as scanned content.

Old orphaned objects can be inspected with `php artisan attachments:reconcile-orphans --dry-run` and removed with `php artisan attachments:reconcile-orphans`. The command ignores fresh objects; its minimum age defaults to 24 hours and can be configured with `MESSAGE_ATTACHMENT_ORPHAN_HOURS` or the `--hours` option.

Reactions use the fixed catalog `like`, `love`, `laugh`, `surprised`, `sad`, and `celebrate`. HTTP and the database remain authoritative; Reverb delivers versioned aggregate-count updates without reactor identities. Run Reverb and a queue worker as described above for live synchronization.

## Authentication and account policy

The first-party web client uses Laravel sessions. There is no public registration. Administrators create accounts and the application sends Laravel's email-verification notification. Protected application routes require an active and verified account. Configure a real mail service through environment variables outside Git.

Production must use HTTPS, `APP_DEBUG=false`, `SESSION_SECURE_COOKIE=true`, protected secrets, supervised workers, and an appropriately restricted database account.

## Authorization

`RolePermissionSeeder` creates Super Admin, Admin, Teacher, and Student roles. Permissions are coarse capabilities. Future resources must additionally use Laravel policies and resource relationships. A Teacher role never implies access to all classes. The final active Super Admin is protected by transactional server-side actions.

## Audit foundation

Account creation, identity/status changes, role changes, deletion, and initial Super Admin creation write append-oriented audit records. Passwords, hashes, reset tokens, session identifiers, and secrets are excluded. Audit records have no update or delete application workflow.

## Branch workflow

Phase 1 is developed on `feature/phase-1-foundation-auth-rbac`. Changes require review and passing CI before any owner-approved merge to `main`.
