<?php

namespace App\Actions\Collaboration;

use App\Enums\ChannelStatus;
use App\Enums\ChannelType;
use App\Models\Channel;
use App\Models\SchoolClass;
use App\Services\AuditLogger;
use Illuminate\Support\Facades\DB;

class ProvisionDefaultChannels
{
    public function __construct(private AuditLogger $audit) {}

    /** @return array{created:int, existing:int} */
    public function handle(SchoolClass $class, ?int $creatorId = null): array
    {
        return DB::transaction(function () use ($class, $creatorId) {
            SchoolClass::query()->whereKey($class->id)->lockForUpdate()->firstOrFail();
            $created = 0;
            foreach ([[1, 'General', 'general', ChannelType::General], [2, 'Announcements', 'announcements', ChannelType::Announcement]] as [$slot, $name, $slug, $type]) {
                $channel = Channel::query()->firstOrCreate(
                    ['school_class_id' => $class->id, 'default_slot' => $slot],
                    ['class_subject_id' => null, 'name' => $name, 'slug' => $slug, 'type' => $type, 'status' => ChannelStatus::Active, 'created_by' => $creatorId],
                );
                if ($channel->wasRecentlyCreated) {
                    $created++;
                    $this->audit->log('channel.created', $channel, [], $channel->only('school_class_id', 'name', 'slug', 'type', 'status'));
                }
            }
            if ($created > 0) {
                $this->audit->log('channel.defaults-provisioned', $class, [], ['created' => $created]);
            }

            return ['created' => $created, 'existing' => 2 - $created];
        });
    }
}
