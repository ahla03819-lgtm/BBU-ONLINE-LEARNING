<?php

namespace App\Actions\Collaboration;

use App\Models\Channel;
use App\Services\AuditLogger;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class UpdateChannel
{
    public function __construct(private AuditLogger $audit) {}

    public function handle(Channel $channel, array $data): Channel
    {
        return DB::transaction(function () use ($channel, $data) {
            $before = $channel->only('name', 'slug', 'description');
            $channel->update(['name' => $data['name'], 'slug' => Str::slug($data['slug'] ?? $data['name']), 'description' => $data['description'] ?? null]);
            $this->audit->log('channel.updated', $channel, $before, $channel->only('name', 'slug', 'description'));

            return $channel;
        });
    }
}
