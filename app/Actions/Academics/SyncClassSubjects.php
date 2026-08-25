<?php

namespace App\Actions\Academics;

use App\Actions\Collaboration\ArchiveChannel;
use App\Actions\Collaboration\ProvisionSubjectChannel;
use App\Enums\ClassSubjectStatus;
use App\Models\SchoolClass;
use App\Services\AuditLogger;
use Illuminate\Support\Facades\DB;

class SyncClassSubjects
{
    public function __construct(private AuditLogger $audit, private ProvisionSubjectChannel $provisionSubjectChannel, private ArchiveChannel $archiveChannel) {}

    public function handle(SchoolClass $class, array $subjectIds, string $effectiveOn): void
    {
        DB::transaction(function () use ($class, $subjectIds, $effectiveOn) {
            $before = $class->classSubjects()->where('status', ClassSubjectStatus::Active)->pluck('subject_id')->all();
            foreach (array_diff($subjectIds, $before) as $id) {
                $classSubject = $class->classSubjects()->where('subject_id', $id)->first();
                if ($classSubject) {
                    $prior = $classSubject->only('status', 'archived_at', 'archived_by');
                    $classSubject->update(['status' => ClassSubjectStatus::Active, 'archived_at' => null, 'archived_by' => null]);
                    $this->audit->log('class-subject.restored', $classSubject, $prior, $classSubject->only('status'));
                } else {
                    $classSubject = $class->classSubjects()->create(['subject_id' => $id, 'status' => ClassSubjectStatus::Active]);
                }
                $this->provisionSubjectChannel->handle($classSubject, auth()->id());
            }
            $removing = $class->classSubjects()->where('status', ClassSubjectStatus::Active)->whereNotIn('subject_id', $subjectIds)->get();
            foreach ($removing as $item) {
                $item->teacherAssignments()->where('current_slot', 1)->get()->each(function ($assignment) use ($effectiveOn) {
                    if ($assignment->starts_on->isAfter($effectiveOn)) {
                        throw new \DomainException('The effective date cannot precede the current subject assignment.');
                    }
                    $prior = $assignment->toArray();
                    $assignment->update(['ends_on' => $effectiveOn, 'current_slot' => null]);
                    $this->audit->log('teacher-class-subject.ended', $assignment, $prior, $assignment->fresh()->toArray());
                });
                $prior = $item->only('status', 'archived_at', 'archived_by');
                $item->update(['status' => ClassSubjectStatus::Archived, 'archived_at' => now(), 'archived_by' => auth()->id()]);
                if ($item->channel) {
                    $this->archiveChannel->handle($item->channel);
                }
                $this->audit->log('class-subject.archived', $item, $prior, $item->only('status', 'archived_at', 'archived_by'));
            }
            $this->audit->log('class.subjects-synced', $class, ['subject_ids' => $before], ['subject_ids' => $subjectIds]);
        });
    }
}
