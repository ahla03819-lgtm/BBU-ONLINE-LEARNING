<?php

namespace App\Actions\Academics;

use App\Models\SchoolClass;
use App\Services\AuditLogger;
use Illuminate\Support\Facades\DB;

class SyncClassSubjects
{
    public function __construct(private AuditLogger $audit) {}

    public function handle(SchoolClass $class, array $subjectIds): void
    {
        DB::transaction(function () use ($class, $subjectIds) {
            $before = $class->classSubjects()->pluck('subject_id')->all();
            foreach (array_diff($subjectIds, $before) as $id) {
                $class->classSubjects()->create(['subject_id' => $id]);
            } $removing = $class->classSubjects()->whereNotIn('subject_id', $subjectIds)->get();
            foreach ($removing as $item) {
                if ($item->teacherAssignments()->where('current_slot', 1)->exists()) {
                    throw new \DomainException('End the current teacher assignment before removing this subject.');
                }$item->delete();
            } $this->audit->log('class.subjects-synced', $class, ['subject_ids' => $before], ['subject_ids' => $subjectIds]);
        });
    }
}
