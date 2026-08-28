<?php

namespace App\Actions\Notifications;

use App\Models\User;
use App\Models\UserNotification;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Route;
use InvalidArgumentException;
use LogicException;

class StoreUserNotification
{
    private const SENSITIVE_KEYS = ['authorization', 'cookie', 'password', 'secret', 'token'];

    public function handle(
        User $recipient,
        string $type,
        string $deduplicationKey,
        array $context = [],
        ?User $actor = null,
        ?Model $subject = null,
        ?string $routeName = null,
        array $routeParameters = [],
        int $payloadVersion = 1,
    ): UserNotification {
        if (! $recipient->isActive() || ! $recipient->hasVerifiedEmail()) {
            throw new InvalidArgumentException('Notifications require an active, verified recipient.');
        }
        if ($type === '' || mb_strlen($type) > 100 || $deduplicationKey === '' || mb_strlen($deduplicationKey) > 160) {
            throw new InvalidArgumentException('Notification type and deduplication key are required and must fit the schema.');
        }
        if ($payloadVersion < 1 || $payloadVersion > 65535) {
            throw new InvalidArgumentException('Payload version must be between 1 and 65535.');
        }
        if ($routeName !== null && ($routeName === '' || mb_strlen($routeName) > 180 || ! Route::has($routeName))) {
            throw new InvalidArgumentException('Notification routes must be registered server-owned route names.');
        }
        $this->assertSafeStructure($context);
        $this->assertSafeStructure($routeParameters);

        $attributes = [
            'type' => $type,
            'actor_id' => $actor?->id,
            'subject_type' => $subject?->getMorphClass(),
            'subject_id' => $subject?->getKey(),
            'context' => $context ?: null,
            'route_name' => $routeName,
            'route_parameters' => $routeParameters ?: null,
            'payload_version' => $payloadVersion,
        ];

        $notification = UserNotification::query()->firstOrCreate([
            'user_id' => $recipient->id,
            'deduplication_key' => $deduplicationKey,
        ], $attributes);

        if (! $notification->wasRecentlyCreated && (
            $notification->type !== $type
            || $notification->subject_type !== $attributes['subject_type']
            || (string) $notification->subject_id !== (string) $attributes['subject_id']
        )) {
            throw new LogicException('A notification deduplication key was reused for a different event.');
        }

        return $notification;
    }

    private function assertSafeStructure(array $value): void
    {
        array_walk_recursive($value, function (mixed $item, mixed $key) {
            $normalizedKey = strtolower((string) $key);
            if (collect(self::SENSITIVE_KEYS)->contains(fn (string $sensitive) => str_contains($normalizedKey, $sensitive))) {
                throw new InvalidArgumentException('Notification payloads may not contain sensitive fields.');
            }
            if (! is_null($item) && ! is_scalar($item)) {
                throw new InvalidArgumentException('Notification payloads must contain JSON-safe scalar values.');
            }
            if (is_string($item) && preg_match('/^https?:\/\//i', $item)) {
                throw new InvalidArgumentException('Notification payloads may not contain external URLs.');
            }
        });
    }
}
