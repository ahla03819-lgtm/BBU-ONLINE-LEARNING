<?php

namespace App\Actions\Collaboration;

use App\Enums\ChannelStatus;
use App\Enums\ChannelType;
use App\Models\Channel;
use App\Models\SchoolClass;
use App\Services\AuditLogger;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class CreateChannel
{
    public function __construct(private AuditLogger $audit) {}

    public function handle(SchoolClass $class, array $data): Channel
    {
        return DB::transaction(function () use ($class, $data) {
            $channel = Channel::query()->create(['school_class_id' => $class->id, 'name' => $data['name'], 'slug' => Str::slug($data['slug'] ?? $data['name']), 'description' => $data['description'] ?? null, 'type' => ChannelType::Custom, 'status' => ChannelStatus::Active, 'created_by' => auth()->id()]);
            $this->audit->log('channel.created', $channel, [], $channel->only('school_class_id', 'name', 'slug', 'description', 'type', 'status'));

            return $channel;
        });
    }
}
