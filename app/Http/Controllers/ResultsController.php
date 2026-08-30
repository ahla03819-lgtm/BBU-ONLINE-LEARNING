<?php

namespace App\Http\Controllers;

use App\Models\AcademicYear;
use App\Models\ClassSubject;
use App\Models\ReportingPeriod;
use App\Models\SchoolClass;
use App\Services\ResultsAccess;
use Inertia\Inertia;
use Inertia\Response;

class ResultsController extends Controller
{
    public function index(ResultsAccess $access): Response
    {
        abort_unless($access->canViewStaffResults(request()->user()), 403);
        $user = request()->user();
        $classes = SchoolClass::query()->with(['academicYear:id,name', 'gradeLevel:id,name'])->when(! $access->isAdministrator($user), fn ($query) => $query->whereHas('classSubjects.teacherAssignments', fn ($assignments) => $assignments->where('current_slot', 1)->whereHas('teacherProfile', fn ($profiles) => $profiles->where('user_id', $user->id))))->orderBy('name')->get();
        $periods = ReportingPeriod::query()->with('academicYear:id,name')->orderBy('sequence')->get();

        return Inertia::render('Results/Index', ['filters' => ['academic_years' => AcademicYear::query()->orderByDesc('starts_on')->get(['id', 'name']), 'academic_year_name' => null], 'reportingPeriods' => $periods->map(fn ($period) => ['id' => $period->id, 'name' => $period->name]), 'classes' => $classes->map(fn ($class) => ['id' => $class->id, 'name' => trim($class->name.' '.$class->section)]), 'subjects' => [], 'results' => [], 'summary' => ['students' => 0, 'ready_for_review' => 0, 'published' => 0, 'needs_attention' => 0], 'capabilities' => ['manage_reporting_periods' => $user->can('create', ReportingPeriod::class)]]);
    }

    public function show(ReportingPeriod $reportingPeriod, SchoolClass $schoolClass, ClassSubject $classSubject, ResultsAccess $access): Response
    {
        abort_unless($access->canViewClassSubject(request()->user(), $schoolClass, $classSubject) && $reportingPeriod->academic_year_id === $schoolClass->academic_year_id, 404);
        $reportingPeriod->load('academicYear:id,name');
        $schoolClass->load('gradeLevel:id,name');
        $classSubject->load('subject:id,name');
        $user = request()->user();

        return Inertia::render('Results/Show', ['context' => ['academic_year' => $reportingPeriod->academicYear->only('id', 'name'), 'reporting_period' => $reportingPeriod->only('id', 'name', 'status'), 'school_class' => ['id' => $schoolClass->id, 'name' => trim($schoolClass->name.' '.$schoolClass->section)], 'subject' => $classSubject->subject->only('id', 'name'), 'index_url' => route('results.index'), 'review_status' => 'No result snapshots exist yet.'], 'students' => [], 'attendanceSummary' => ['label' => 'Not available until results are calculated.'], 'publicationState' => 'unavailable', 'capabilities' => ['review' => false, 'publish' => false, 'correct' => false]]);
    }

    public function mine(ResultsAccess $access): Response
    {
        abort_unless($access->canViewOwnResults(request()->user()), 403);
        $classes = $access->currentStudentClasses(request()->user())->with('academicYear:id,name')->get();
        $yearIds = $classes->pluck('academic_year_id')->unique();
        $periods = ReportingPeriod::query()->whereIn('academic_year_id', $yearIds)->orderBy('sequence')->get();

        return Inertia::render('Results/MyResults', ['academicYear' => $classes->first()?->academicYear?->only('id', 'name') ?? [], 'periods' => $periods->map(fn ($period) => $period->only('id', 'name', 'status')), 'selectedPeriod' => [], 'results' => [], 'attendanceSummary' => ['label' => 'Not available until results are published.']]);
    }

    public function reportCard(ReportingPeriod $reportingPeriod, SchoolClass $schoolClass, ResultsAccess $access): Response
    {
        abort_unless($access->canViewOwnResults(request()->user()) && $reportingPeriod->academic_year_id === $schoolClass->academic_year_id && $access->currentStudentClasses(request()->user())->whereKey($schoolClass)->exists(), 404);
        $student = request()->user()->studentProfile;

        return Inertia::render('Results/ReportCard', ['student' => ['name' => $student?->user?->name], 'academicYear' => $schoolClass->academicYear->only('id', 'name'), 'reportingPeriod' => $reportingPeriod->only('id', 'name', 'status'), 'schoolClass' => ['id' => $schoolClass->id, 'name' => trim($schoolClass->name.' '.$schoolClass->section)], 'results' => [], 'attendanceSummary' => ['label' => 'Not available until results are published.']]);
    }
}
