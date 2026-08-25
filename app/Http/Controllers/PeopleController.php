<?php

namespace App\Http\Controllers;

use App\Actions\People\AssignTeacherToClass;
use App\Actions\People\AssignTeacherToClassSubject;
use App\Actions\People\EndEnrollment;
use App\Actions\People\EnrollStudent;
use App\Actions\People\SaveProfile;
use App\Actions\People\TransferStudent;
use App\Enums\ClassSubjectStatus;
use App\Http\Requests\People\AssignTeacherRequest;
use App\Http\Requests\People\EndEnrollmentRequest;
use App\Http\Requests\People\EnrollStudentRequest;
use App\Http\Requests\People\SaveStudentProfileRequest;
use App\Http\Requests\People\SaveTeacherProfileRequest;
use App\Http\Requests\People\TransferStudentRequest;
use App\Models\ClassSubject;
use App\Models\Enrollment;
use App\Models\SchoolClass;
use App\Models\StudentProfile;
use App\Models\TeacherProfile;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class PeopleController extends Controller
{
    public function index(): Response
    {
        $this->authorize('viewAny', StudentProfile::class);
        $students = StudentProfile::query()->with(['user:id,name,email', 'enrollments' => fn ($q) => $q->with('schoolClass.academicYear')->latest('enrolled_on')]);
        if (auth()->user()->hasRole('Teacher')) {
            $uid = auth()->id();
            $students->whereHas('enrollments.schoolClass', fn ($q) => $q->whereHas('teacherAssignments', fn ($a) => $a->where('current_slot', 1)->whereHas('teacherProfile', fn ($t) => $t->where('user_id', $uid)))->orWhereHas('classSubjects.teacherAssignments', fn ($a) => $a->where('current_slot', 1)->whereHas('teacherProfile', fn ($t) => $t->where('user_id', $uid))));
        } elseif (auth()->user()->hasRole('Student')) {
            $students->where('user_id', auth()->id());
        }

        $canManage = auth()->user()->can('students.create') || auth()->user()->can('teachers.create');
        $classes = SchoolClass::query()->with('academicYear:id,name');
        if (auth()->user()->hasRole('Teacher')) {
            $uid = auth()->id();
            $classes->where(fn ($query) => $query
                ->whereHas('teacherAssignments', fn ($assignments) => $assignments->where('current_slot', 1)->whereHas('teacherProfile', fn ($teachers) => $teachers->where('user_id', $uid)))
                ->orWhereHas('classSubjects.teacherAssignments', fn ($assignments) => $assignments->where('current_slot', 1)->whereHas('teacherProfile', fn ($teachers) => $teachers->where('user_id', $uid))));
        } elseif (auth()->user()->hasRole('Student')) {
            $classes->whereHas('enrollments', fn ($enrollments) => $enrollments->where('current_slot', 1)
                ->whereHas('studentProfile', fn ($profiles) => $profiles->where('user_id', auth()->id())));
        }

        return Inertia::render('People/Index', [
            'students' => $students->orderBy('student_number')->get(),
            'teachers' => $canManage ? TeacherProfile::query()->with('user:id,name,email')->orderBy('employee_number')->get() : collect(),
            'users' => $canManage ? User::query()->whereDoesntHave('teacherProfile')->whereDoesntHave('studentProfile')->orderBy('name')->get(['id', 'name', 'email']) : collect(),
            'classes' => $classes->orderBy('name')->get(),
            'classSubjects' => auth()->user()->can('teachers.assign-subject') ? ClassSubject::query()->where('status', ClassSubjectStatus::Active)->with(['schoolClass:id,name', 'subject:id,name'])->get() : collect(),
        ]);
    }

    public function storeTeacher(SaveTeacherProfileRequest $r, SaveProfile $a): RedirectResponse
    {
        $p = new TeacherProfile;
        $p->setRelation('user', User::findOrFail($r->validated('user_id')));
        $a->handle($p, $r->validated(), 'teacher-profile', 'Teacher');

        return back()->with('success', 'Teacher profile created.');
    }

    public function updateTeacher(SaveTeacherProfileRequest $r, TeacherProfile $teacherProfile, SaveProfile $a): RedirectResponse
    {
        $teacherProfile->setRelation('user', User::findOrFail($r->validated('user_id')));
        $a->handle($teacherProfile, $r->validated(), 'teacher-profile', 'Teacher');

        return back()->with('success', 'Teacher profile updated.');
    }

    public function storeStudent(SaveStudentProfileRequest $r, SaveProfile $a): RedirectResponse
    {
        $p = new StudentProfile;
        $p->setRelation('user', User::findOrFail($r->validated('user_id')));
        $a->handle($p, $r->validated(), 'student-profile', 'Student');

        return back()->with('success', 'Student profile created.');
    }

    public function updateStudent(SaveStudentProfileRequest $r, StudentProfile $studentProfile, SaveProfile $a): RedirectResponse
    {
        $studentProfile->setRelation('user', User::findOrFail($r->validated('user_id')));
        $a->handle($studentProfile, $r->validated(), 'student-profile', 'Student');

        return back()->with('success', 'Student profile updated.');
    }

    public function enroll(EnrollStudentRequest $r, StudentProfile $studentProfile, EnrollStudent $a): RedirectResponse
    {
        try {
            $a->handle($studentProfile, SchoolClass::findOrFail($r->validated('school_class_id')), $r->validated('enrolled_on'));
        } catch (\DomainException $e) {
            throw ValidationException::withMessages(['school_class_id' => $e->getMessage()]);
        }

        return back()->with('success', 'Student enrolled.');
    }

    public function end(EndEnrollmentRequest $r, Enrollment $enrollment, EndEnrollment $a): RedirectResponse
    {
        try {
            $a->handle($enrollment, $r->validated('ended_on'), $r->validated('end_reason'));
        } catch (\DomainException $e) {
            throw ValidationException::withMessages(['ended_on' => $e->getMessage()]);
        }

        return back()->with('success', 'Enrollment ended.');
    }

    public function transfer(TransferStudentRequest $r, Enrollment $enrollment, TransferStudent $a): RedirectResponse
    {
        try {
            $a->handle($enrollment, SchoolClass::findOrFail($r->validated('school_class_id')), $r->validated('transferred_on'), $r->validated('reason'));
        } catch (\DomainException $e) {
            throw ValidationException::withMessages(['school_class_id' => $e->getMessage()]);
        }

        return back()->with('success', 'Student transferred.');
    }

    public function assignClass(AssignTeacherRequest $r, SchoolClass $schoolClass, AssignTeacherToClass $a): RedirectResponse
    {
        $a->handle(TeacherProfile::findOrFail($r->validated('teacher_profile_id')), $schoolClass, $r->validated('starts_on'));

        return back()->with('success', 'Class teacher assigned.');
    }

    public function assignSubject(AssignTeacherRequest $r, ClassSubject $classSubject, AssignTeacherToClassSubject $a): RedirectResponse
    {
        $a->handle(TeacherProfile::findOrFail($r->validated('teacher_profile_id')), $classSubject, $r->validated('starts_on'));

        return back()->with('success', 'Subject teacher assigned.');
    }
}
