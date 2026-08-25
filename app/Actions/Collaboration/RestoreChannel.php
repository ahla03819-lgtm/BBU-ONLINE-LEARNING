<?php

namespace App\Actions\Collaboration;

use App\Enums\ChannelStatus;
use App\Models\Channel;
use App\Services\AuditLogger;
use Illuminate\Support\Facades\DB;

class RestoreChannel
{
    public function __construct(private AuditLogger $audit) {}

    public function handle(Channel $channel): Channel
    {
        return DB::transaction(function () use ($channel) {
            $locked = Channel::query()->lockForUpdate()->findOrFail($channel->id);
            if ($locked->status === ChannelStatus::Active) {
                return $locked;
            }$before = $locked->only('status', 'archived_at', 'archived_by');
            $locked->update(['status' => ChannelStatus::Active, 'archived_at' => null, 'archived_by' => null]);
            $this->audit->log('channel.restored', $locked, $before, $locked->only('status'));

            return $locked;
        });
    }
}
