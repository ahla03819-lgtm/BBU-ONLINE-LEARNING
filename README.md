# BBU ONLINE LEARNING

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

## Avatar storage

Profile photos use Laravel's public storage disk. Run the following after cloning, moving, or deploying the project, and verify that `public/storage` points to `storage/app/public`:

```shell
php artisan storage:link
```

If `public/storage` is stale or broken, recreate it with Laravel:

```shell
php artisan storage:unlink
php artisan storage:link
ls -ld public/storage
```

Full avatar recovery requires restoring both the database and `storage/app/public/user-avatars`. Restoring only one leaves user records and their profile photos out of sync.

## LiveKit meeting runtime

Configure the browser WebSocket endpoint as `LIVEKIT_URL=wss://...`, the backend Room Service endpoint as `LIVEKIT_API_URL=https://...`, and provide `LIVEKIT_API_KEY` plus the backend-only `LIVEKIT_API_SECRET` for LiveKit Cloud or a compatible self-hosted server. Never add the API endpoint, key, or secret to a `VITE_` variable. The participant token remains in browser memory only and expires after five minutes; an established LiveKit connection is not terminated merely because its original join token expires.

For local meeting development, run the HTTP server, queue worker, scheduler, Reverb, and Vite as separate supervised processes:

```shell
php artisan serve
php artisan queue:work
php artisan schedule:work
php artisan reverb:start
npm run dev
```

LiveKit must be reachable from the browser at `LIVEKIT_URL`, while Laravel must reach the HTTPS Room Service API at `LIVEKIT_API_URL`. LiveKit webhooks must target `POST /integrations/livekit/webhook`. Camera and microphone permissions are requested only after an explicit action in the meeting lobby. A real two-user media test requires valid LiveKit credentials, HTTPS or localhost browser media access, and separate Teacher and Student browser sessions.

Local meeting development requires each long-running process in a separate terminal:

```bash
php artisan serve
php artisan queue:work
php artisan schedule:work
php artisan reverb:start
npm run dev
```

After deployment, synchronize and verify meeting RBAC without doing permission work on web requests:

```bash
php artisan db:seed --class=RolePermissionSeeder --force
php artisan meetings:verify-permissions
```

LiveKit Cloud must deliver signed webhooks to a publicly reachable HTTPS `POST /integrations/livekit/webhook` endpoint for attendance sessions to be recorded. A Cloudflare quick tunnel is suitable only for local testing because its hostname is temporary. Production must use a stable public HTTPS endpoint. Keep `LIVEKIT_API_KEY` and `LIVEKIT_API_SECRET` backend-only, never expose them through `VITE_` variables, and never commit real credentials.

## Message attachments and reactions

Messages support up to five private attachments, 10 MB per file and 25 MB combined. Supported formats are JPEG, PNG, WebP, PDF, DOCX, XLSX, PPTX, TXT, and CSV. Files are validated on the server, stored under generated private object keys on `MESSAGE_ATTACHMENT_DISK` (the private `local` disk by default), and delivered only through relationship-scoped authorized routes. They are never exposed through `public/storage` or permanent public URLs. Production may select a private S3-compatible disk after installing and configuring the appropriate Laravel filesystem adapter.

Attachment-only messages are supported. Attachment objects are immutable and retained with hidden messages; ordinary hidden-message payloads expose no attachment metadata. Administrators require the explicit `attachments.view-hidden` permission to access retained hidden binaries. Malware scanning is intentionally deferred and must be added as production infrastructure before treating uploads as scanned content.

Old orphaned objects can be inspected with `php artisan attachments:reconcile-orphans --dry-run` and removed with `php artisan attachments:reconcile-orphans`. The command ignores fresh objects; its minimum age defaults to 24 hours and can be configured with `MESSAGE_ATTACHMENT_ORPHAN_HOURS` or the `--hours` option.

Reactions use the fixed catalog `like`, `love`, `laugh`, `surprised`, `sad`, and `celebrate`. HTTP and the database remain authoritative; Reverb delivers versioned aggregate-count updates without reactor identities. Run Reverb and a queue worker as described above for live synchronization.

## Coursework deployment

Coursework uses private storage for submission attachments and immutable database rows for submitted revisions and grade history. After deployment, run `php artisan db:seed --class=RolePermissionSeeder --force` followed by `php artisan coursework:verify-permissions`. The optional `COURSEWORK_ATTACHMENT_DISK` defaults to the private `local` disk. Inspect stale orphaned objects with `php artisan coursework:reconcile-attachments --dry-run` before running the command without `--dry-run`.

## Authentication and account policy

The first-party web client uses Laravel sessions. There is no public registration. Administrators create accounts and the application sends Laravel's email-verification notification. Protected application routes require an active and verified account. Configure a real mail service through environment variables outside Git.

Production must use HTTPS, `APP_DEBUG=false`, `SESSION_SECURE_COOKIE=true`, protected secrets, supervised workers, and an appropriately restricted database account.

## Authorization

`RolePermissionSeeder` creates Super Admin, Admin, Teacher, and Student roles. Permissions are coarse capabilities. Future resources must additionally use Laravel policies and resource relationships. A Teacher role never implies access to all classes. The final active Super Admin is protected by transactional server-side actions.

## Audit foundation

Account creation, identity/status changes, role changes, deletion, and initial Super Admin creation write append-oriented audit records. Passwords, hashes, reset tokens, session identifiers, and secrets are excluded. Audit records have no update or delete application workflow.

## Branch workflow

Phase 1 is developed on `feature/phase-1-foundation-auth-rbac`. Changes require review and passing CI before any owner-approved merge to `main`.
