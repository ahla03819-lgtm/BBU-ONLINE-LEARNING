<?php

namespace App\Actions\Classes;

use App\Actions\People\EnrollStudent;
use App\Models\SchoolClass;
use App\Models\User;
use App\Services\AuditLogger;
use App\Services\ClassJoinCode;
use App\Services\CollaborationAccess;

class JoinSchoolClassByCode
{
    public function __construct(private ClassJoinCode $codes, private CollaborationAccess $access, private EnrollStudent $enroll, private AuditLogger $audit) {}

    public function handle(User $user, string $enteredCode): SchoolClass
    {
        $student = $user->studentProfile()->first();
        if (! $student) {
            throw new \DomainException('Your account is not eligible to join a class. Contact BBU administration for assistance.');
        }

        $code = $this->codes->normalize($enteredCode);
        $schoolClass = SchoolClass::query()->with('academicYear')->where('join_code', $code)->first();
        if (! $schoolClass) {
            throw new \DomainException('The class code is not valid. Check the code and try again.');
        }
        if (! $schoolClass->join_code_enabled) {
            throw new \DomainException('This class is not currently accepting join codes.');
        }
        if (! $this->access->isAcademicallyActive($schoolClass)) {
            throw new \DomainException('This class is not available to join.');
        }

        if ($student->enrollments()->where('school_class_id', $schoolClass->id)->where('current_slot', 1)->exists()) {
            throw new \DomainException('You are already a member of this class.');
        }

        $enrollment = $this->enroll->handle($student, $schoolClass, now()->toDateString());
        $this->audit->log('school-class.joined-by-code', $enrollment, [], ['school_class_id' => $schoolClass->id]);

        return $schoolClass;
    }
}
