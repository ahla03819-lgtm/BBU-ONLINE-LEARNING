<?php

namespace App\Policies;

use App\Models\MessageAttachment;
use App\Models\User;
use App\Services\CollaborationAccess;

class MessageAttachmentPolicy
{
    public function __construct(private CollaborationAccess $access) {}

    public function view(User $user, MessageAttachment $attachment): bool
    {
        if (! $user->can('attachments.download') || ! $user->can('view', $attachment->message)) {
            return false;
        }
        if (! $attachment->message->isHidden()) {
            return true;
        }

        return $user->can('attachments.view-hidden') && $this->access->isAdministrator($user);
    }
}
