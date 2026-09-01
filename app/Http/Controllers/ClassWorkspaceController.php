<?php

namespace App\Http\Controllers;

use App\Enums\AcademicYearStatus;
use App\Enums\SchoolClassStatus;
use App\Models\AcademicYear;
use App\Models\GradeLevel;
use App\Models\SchoolClass;
use App\Services\AttendanceAccess;
use App\Services\CollaborationAccess;
use App\Services\CourseworkAccess;
use App\Services\MeetingAccess;
use App\Services\ResultsAccess;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class ClassWorkspaceController extends Controller
{
    public function index(Request $request, CollaborationAccess $access): Response
    {
        $user = $request->user();
        abort_unless($user->can('classes.view'), 403);

        $classes = $access->classesFor($user)
            ->with([
                'academicYear:id,name,status',
                'gradeLevel:id,name',
                'teacherAssignments' => fn ($assignments) => $assignments->where('current_slot', 1)->with('teacherProfile.user:id,name'),
            ])
            ->withCount(['enrollments as current_enrollments_count' => fn ($enrollments) => $enrollments->where('current_slot', 1)])
            ->orderBy('name')
            ->orderBy('section')
            ->get();

        return Inertia::render('Classes/Index', [
            'classes' => $classes->map(fn (SchoolClass $schoolClass) => $this->classCard($schoolClass, $this->canViewMembers($user)))->values(),
            'canCreateClass' => $user->can('create', SchoolClass::class),
            'canJoinClass' => $user->can('joinByCode', SchoolClass::class),
            'createClassOptions' => $user->can('create', SchoolClass::class) ? [
                'academicYears' => AcademicYear::query()->where('status', AcademicYearStatus::Active->value)->orderByDesc('starts_on')->get(['id', 'name']),
                'gradeLevels' => GradeLevel::query()->where('is_active', true)->orderBy('sequence')->get(['id', 'name']),
                'statuses' => array_column(SchoolClassStatus::cases(), 'value'),
            ] : null,
        ]);
    }

    public function show(Request $request, SchoolClass $schoolClass, CollaborationAccess $collaboration, CourseworkAccess $coursework, MeetingAccess $meetings, AttendanceAccess $attendance, ResultsAccess $results): Response
    {
        $user = $request->user();
        abort_unless($user->can('classes.view') && $collaboration->canAccessClass($user, $schoolClass), 403);

        $schoolClass->load([
            'academicYear:id,name,status',
            'gradeLevel:id,name',
            'classSubjects.subject:id,code,name',
            'teacherAssignments' => fn ($assignments) => $assignments->where('current_slot', 1)->with('teacherProfile.user:id,name'),
        ]);
        $schoolClass->loadCount(['enrollments as current_enrollments_count' => fn ($enrollments) => $enrollments->where('current_slot', 1)]);

        $canViewPosts = $user->can('channels.view') && $collaboration->canAccessClass($user, $schoolClass);
        $canViewCoursework = $user->can('assignments.view') && $coursework->canAccessClass($user, $schoolClass);
        $canViewMeetings = $user->can('meetings.view') && $meetings->canAccessClass($user, $schoolClass);
        $canViewAttendance = $user->can('attendance.view') && $attendance->canViewRegister($user, $schoolClass);
        $canViewOwnAttendance = $user->can('attendance.view-own') && $user->hasRole('Student');
        $canViewResults = $user->can('results.view') || $user->can('results.view-own');
        $canManageJoinCode = $user->can('manageJoinCode', $schoolClass);
        $canViewMembers = $user->can('viewMembers', $schoolClass);

        return Inertia::render('Classes/Show', [
            'schoolClass' => [
                ...$this->classCard($schoolClass, $this->canViewMembers($user)),
                'subjects' => $schoolClass->classSubjects
                    ->filter(fn ($classSubject) => $classSubject->status->value === 'active')
                    ->map(fn ($classSubject) => $classSubject->subject->only('id', 'code', 'name'))
                    ->values(),
                'canManage' => $user->can('update', $schoolClass),
                'joinCode' => $canManageJoinCode ? [
                    'code' => $schoolClass->join_code,
                    'enabled' => (bool) $schoolClass->join_code_enabled,
                    'updateUrl' => route('school-classes.join-code.update', $schoolClass),
                ] : null,
            ],
            'tabs' => [
                ['label' => 'Overview', 'href' => route('classes.show', $schoolClass), 'active' => true, 'icon' => 'home'],
                ['label' => 'Posts', 'href' => $canViewPosts ? route('collaboration.classes.show', $schoolClass) : null, 'icon' => 'messages'],
                ['label' => 'Coursework', 'href' => $canViewCoursework ? route('coursework.index', $schoolClass) : null, 'icon' => 'clipboard'],
                ['label' => 'Members', 'href' => $canViewMembers ? route('classes.members', $schoolClass) : null, 'icon' => 'users'],
                ['label' => 'Meetings', 'href' => $canViewMeetings ? route('meetings.index', $schoolClass) : null, 'icon' => 'video'],
                ['label' => 'Attendance', 'href' => $canViewAttendance ? route('attendance.index', ['school_class_id' => $schoolClass->id]) : ($canViewOwnAttendance ? route('attendance.mine') : null), 'icon' => 'calendar'],
                ['label' => $user->hasRole('Student') ? 'My results' : 'Results', 'href' => $canViewResults ? route($user->hasRole('Student') ? 'results.mine' : 'results.index') : null, 'icon' => 'chart'],
            ],
        ]);
    }

    public function members(Request $request, SchoolClass $schoolClass): Response
    {
        $user = $request->user();
        abort_unless($user->can('viewMembers', $schoolClass), 403);

        $search = trim((string) $request->query('search', ''));
        abort_if(mb_strlen($search) > 100, 422);

        $teachers = $schoolClass->teacherAssignments()
            ->where('current_slot', 1)
            ->with('teacherProfile.user:id,name,status,email_verified_at,avatar_path')
            ->get()
            ->map(fn ($assignment) => $assignment->teacherProfile?->user)
            ->filter();
        $students = $schoolClass->enrollments()
            ->where('current_slot', 1)
            ->with('studentProfile.user:id,name,status,email_verified_at,avatar_path')
            ->get()
            ->map(fn ($enrollment) => $enrollment->studentProfile?->user)
            ->filter();

        $members = collect($teachers->map(fn ($member) => $this->member($member, 'Teacher', $user->id, $schoolClass))->all())
            ->merge($students->map(fn ($member) => $this->member($member, 'Student', $user->id, $schoolClass))->all())
            ->unique('id')
            ->filter(fn (array $member) => $search === '' || str_contains(mb_strtolower($member['name']), mb_strtolower($search)))
            ->sortBy([['isCurrentUser', 'desc'], ['name', 'asc']])
            ->values();

        return Inertia::render('Classes/Members', [
            'schoolClass' => [
                'id' => $schoolClass->id,
                'name' => $schoolClass->name,
                'section' => $schoolClass->section,
                'workspaceUrl' => route('classes.show', $schoolClass),
            ],
            'members' => $members,
            'search' => $search,
        ]);
    }

    private function classCard(SchoolClass $schoolClass, bool $canViewMembers): array
    {
        return [
            'id' => $schoolClass->id,
            'name' => $schoolClass->name,
            'section' => $schoolClass->section,
            'status' => $schoolClass->status->value,
            'academicYear' => $schoolClass->academicYear?->only('id', 'name', 'status'),
            'gradeLevel' => $schoolClass->gradeLevel?->only('id', 'name'),
            'teacher' => $schoolClass->teacherAssignments->first()?->teacherProfile?->user?->only('id', 'name'),
            'memberCount' => $canViewMembers ? $schoolClass->current_enrollments_count : null,
            'workspaceUrl' => route('classes.show', $schoolClass),
        ];
    }

    private function canViewMembers($user): bool
    {
        return $user->can('students.view') && ! $user->hasRole('Student');
    }

    private function member($user, string $role, int $currentUserId, SchoolClass $schoolClass): array
    {
        return [
            'id' => $user->id,
            'name' => $user->name,
            'role' => $role,
            'avatarUrl' => $user->avatarUrl(),
            'isCurrentUser' => $user->id === $currentUserId,
            'chatUrl' => $user->id === $currentUserId ? null : route('classes.members.chat', ['schoolClass' => $schoolClass, 'user' => $user]),
        ];
    }
}
