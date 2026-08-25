<?php

namespace App\Actions\Collaboration;

use App\Enums\ChannelStatus;
use App\Enums\ChannelType;
use App\Models\Channel;
use App\Models\ClassSubject;
use App\Models\SchoolClass;
use App\Services\AuditLogger;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class ProvisionSubjectChannel
{
    public function __construct(private AuditLogger $audit) {}

    public function handle(ClassSubject $classSubject, ?int $creatorId = null): Channel
    {
        return DB::transaction(function () use ($classSubject, $creatorId) {
            SchoolClass::query()->whereKey($classSubject->school_class_id)->lockForUpdate()->firstOrFail();
            $classSubject->loadMissing('subject');
            $channel = Channel::query()->where('class_subject_id', $classSubject->id)->first();
            if ($channel) {
                if ($channel->status === ChannelStatus::Archived) {
                    $before = $channel->only('status', 'archived_at', 'archived_by');
                    $channel->update(['status' => ChannelStatus::Active, 'archived_at' => null, 'archived_by' => null]);
                    $this->audit->log('channel.restored', $channel, $before, $channel->only('status'));
                }

                return $channel;
            }
            $base = Str::slug($classSubject->subject->code ?: $classSubject->subject->name);
            $slug = $base;
            $suffix = 2;
            while (Channel::query()->where('school_class_id', $classSubject->school_class_id)->where('slug', $slug)->exists()) {
                $slug = $base.'-'.$suffix++;
            }
            $channel = Channel::query()->create(['school_class_id' => $classSubject->school_class_id, 'class_subject_id' => $classSubject->id, 'name' => $classSubject->subject->name, 'slug' => $slug, 'type' => ChannelType::Subject, 'status' => ChannelStatus::Active, 'created_by' => $creatorId]);
            $this->audit->log('channel.subject-provisioned', $channel, [], $channel->only('school_class_id', 'class_subject_id', 'name', 'slug', 'type'));

            return $channel;
        });
    }
}
