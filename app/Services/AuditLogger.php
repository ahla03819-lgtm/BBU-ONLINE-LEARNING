<?php

namespace App\Services;

use App\Models\AuditLog;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Arr;
use Illuminate\Support\Str;

class AuditLogger
{
    private const SENSITIVE = ['password', 'password_confirmation', 'remember_token', 'token'];

    public function log(string $action, ?Model $target = null, array $before = [], array $after = []): AuditLog
    {
        $request = app()->runningInConsole() ? null : request();

        return AuditLog::query()->create([
            'actor_id' => auth()->id(), 'action' => $action,
            'target_type' => $target?->getMorphClass(), 'target_id' => $target?->getKey(),
            'request_id' => $request?->headers->get('X-Request-ID', (string) Str::uuid()),
            'ip_address' => $request?->ip(), 'user_agent' => $request?->userAgent(),
            'before' => Arr::except($before, self::SENSITIVE), 'after' => Arr::except($after, self::SENSITIVE),
        ]);
    }
}
