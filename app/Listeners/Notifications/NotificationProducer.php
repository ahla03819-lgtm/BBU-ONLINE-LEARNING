<?php

namespace App\Listeners\Notifications;

use App\Actions\Notifications\StoreUserNotification;
use App\Events\UserNotificationCreated;
use App\Models\User;
use App\Services\NotificationRecipientResolver;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;

abstract class NotificationProducer
{
    public bool $afterCommit = true;

    public int $tries = 3;

    public function __construct(
        protected NotificationRecipientResolver $recipients,
        protected StoreUserNotification $store,
    ) {}

    /** @param Collection<int, User> $recipients */
    protected function storeFor(
        Collection $recipients,
        string $type,
        string $deduplicationKey,
        array $context = [],
        ?User $actor = null,
        ?Model $subject = null,
        ?string $routeName = null,
        array $routeParameters = [],
        int $payloadVersion = 1,
    ): void {
        foreach ($recipients as $recipient) {
            $notification = $this->store->handle($recipient, $type, $deduplicationKey, $context, $actor, $subject, $routeName, $routeParameters, $payloadVersion);
            if ($notification->wasRecentlyCreated) {
                UserNotificationCreated::dispatch($notification);
            }
        }
    }
}
