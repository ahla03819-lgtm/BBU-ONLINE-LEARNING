<?php

namespace App\Http\Controllers;

use App\Actions\Academics\SaveAcademicResource;
use App\Actions\Academics\SaveAcademicYear;
use App\Actions\Academics\SyncClassSubjects;
use App\Enums\AcademicYearStatus;
use App\Enums\SchoolClassStatus;
use App\Http\Requests\Academics\SaveAcademicYearRequest;
use App\Http\Requests\Academics\SaveGradeLevelRequest;
use App\Http\Requests\Academics\SaveSchoolClassRequest;
use App\Http\Requests\Academics\SaveSubjectRequest;
use App\Http\Requests\Academics\SyncClassSubjectsRequest;
use App\Models\AcademicYear;
use App\Models\GradeLevel;
use App\Models\SchoolClass;
use App\Models\Subject;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;

class AcademicController extends Controller
{
    public function index(): Response
    {
        $this->authorize('viewAny', AcademicYear::class);
        $query = SchoolClass::query()->with(['academicYear:id,name', 'gradeLevel:id,name', 'classSubjects.subject:id,code,name', 'teacherAssignments' => fn ($q) => $q->where('current_slot', 1)->with('teacherProfile.user:id,name')]);
        if (auth()->user()->hasRole('Teacher')) {
            $uid = auth()->id();
            $query->where(fn ($q) => $q->whereHas('teacherAssignments', fn ($a) => $a->where('current_slot', 1)->whereHas('teacherProfile', fn ($t) => $t->where('user_id', $uid)))->orWhereHas('classSubjects.teacherAssignments', fn ($a) => $a->where('current_slot', 1)->whereHas('teacherProfile', fn ($t) => $t->where('user_id', $uid))));
        } elseif (auth()->user()->hasRole('Student')) {
            $query->whereHas('enrollments', fn ($enrollments) => $enrollments->where('current_slot', 1)
                ->whereHas('studentProfile', fn ($students) => $students->where('user_id', auth()->id())));
        }

        return Inertia::render('Academics/Index', ['academicYears' => AcademicYear::query()->orderByDesc('starts_on')->get(), 'gradeLevels' => GradeLevel::query()->orderBy('sequence')->get(), 'subjects' => Subject::query()->orderBy('name')->get(), 'schoolClasses' => $query->orderBy('name')->get(), 'yearStatuses' => array_column(AcademicYearStatus::cases(), 'value'), 'classStatuses' => array_column(SchoolClassStatus::cases(), 'value')]);
    }

    public function storeYear(SaveAcademicYearRequest $r, SaveAcademicYear $a): RedirectResponse
    {
        $a->handle(null, $r->validated());

        return back()->with('success', 'Academic year created.');
    }

    public function updateYear(SaveAcademicYearRequest $r, AcademicYear $academicYear, SaveAcademicYear $a): RedirectResponse
    {
        $a->handle($academicYear, $r->validated());

        return back()->with('success', 'Academic year updated.');
    }

    public function storeGrade(SaveGradeLevelRequest $r, SaveAcademicResource $a): RedirectResponse
    {
        $a->handle(new GradeLevel, $r->validated(), 'grade-level');

        return back()->with('success', 'Grade level created.');
    }

    public function updateGrade(SaveGradeLevelRequest $r, GradeLevel $gradeLevel, SaveAcademicResource $a): RedirectResponse
    {
        $a->handle($gradeLevel, $r->validated(), 'grade-level');

        return back()->with('success', 'Grade level updated.');
    }

    public function storeSubject(SaveSubjectRequest $r, SaveAcademicResource $a): RedirectResponse
    {
        $a->handle(new Subject, $r->validated(), 'subject');

        return back()->with('success', 'Subject created.');
    }

    public function updateSubject(SaveSubjectRequest $r, Subject $subject, SaveAcademicResource $a): RedirectResponse
    {
        $a->handle($subject, $r->validated(), 'subject');

        return back()->with('success', 'Subject updated.');
    }

    public function storeClass(SaveSchoolClassRequest $r, SaveAcademicResource $a): RedirectResponse
    {
        $a->handle(new SchoolClass, $r->validated(), 'school-class');

        return back()->with('success', 'Class created.');
    }

    public function updateClass(SaveSchoolClassRequest $r, SchoolClass $schoolClass, SaveAcademicResource $a): RedirectResponse
    {
        $a->handle($schoolClass, $r->validated(), 'school-class');

        return back()->with('success', 'Class updated.');
    }

    public function syncSubjects(SyncClassSubjectsRequest $r, SchoolClass $schoolClass, SyncClassSubjects $a): RedirectResponse
    {
        $a->handle($schoolClass, $r->validated('subject_ids', []));

        return back()->with('success', 'Class subjects updated.');
    }
}
