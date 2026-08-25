<?php

namespace App\Actions\Collaboration;

use App\Enums\ChannelStatus;
use App\Models\Channel;
use App\Services\AuditLogger;
use App\Services\CollaborationAccess;
use Illuminate\Support\Facades\DB;

class ArchiveChannel
{
    public function __construct(private AuditLogger $audit, private CollaborationAccess $access) {}

    public function handle(Channel $channel): Channel
    {
        return DB::transaction(function () use ($channel) {
            $locked = Channel::query()->lockForUpdate()->findOrFail($channel->id);
            if ($locked->isDefault() && $this->access->isAcademicallyActive($locked->schoolClass)) {
                throw new \DomainException('Default channels cannot be archived while their class is active.');
            }
            if ($locked->status === ChannelStatus::Archived) {
                return $locked;
            }$before = $locked->only('status', 'archived_at', 'archived_by');
            $locked->update(['status' => ChannelStatus::Archived, 'archived_at' => now(), 'archived_by' => auth()->id()]);
            $this->audit->log('channel.archived', $locked, $before, $locked->only('status', 'archived_at', 'archived_by'));

            return $locked;
        });
    }
}
