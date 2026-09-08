<?php

namespace App\Http\Controllers;

use App\Actions\Users\ApproveUser;
use App\Actions\Users\AssignRoleToUser;
use App\Actions\Users\ChangeUserStatus;
use App\Actions\Users\CreateUser;
use App\Actions\Users\EnsureSuperAdminContinuity;
use App\Actions\Users\ResetUserPassword;
use App\Actions\Users\UpdateUser;
use App\Enums\AccountStatus;
use App\Http\Requests\AssignUserRoleRequest;
use App\Http\Requests\CreateUserRequest;
use App\Http\Requests\UpdateUserRequest;
use App\Http\Requests\UpdateUserStatusRequest;
use App\Models\User;
use App\Services\AuditLogger;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

class UserController extends Controller
{
    public function index(Request $request): Response
    {
        $this->authorize('viewAny', User::class);

        $users = User::query()->with('roles:id,name')
            ->when($request->string('search')->trim()->value(), fn ($query, $search) => $query->where(fn ($q) => $q->where('name', 'like', "%{$search}%")->orWhere('email', 'like', "%{$search}%")))
            ->when($request->string('role')->value(), fn ($query, $role) => $query->role($role))
            ->when(in_array($request->string('status')->value(), ['active', 'inactive', 'suspended'], true), fn ($query, $status) => $query->where('status', $status))
            ->when(in_array($request->string('verified')->value(), ['yes', 'no'], true), fn ($query, $verified) => $verified === 'yes' ? $query->whereNotNull('email_verified_at') : $query->whereNull('email_verified_at'))
            ->when(in_array($request->string('approval')->value(), ['approved', 'pending'], true), fn ($q, $approval) => $approval === 'approved' ? $q->whereNotNull('approved_at') : $q->whereNull('approved_at'))
            ->when(in_array($request->string('password_status')->value(), ['normal', 'change-required'], true), fn ($q, $status) => $q->where('must_change_password', $status === 'change-required'))
            ->latest()->paginate(15)->withQueryString()->through(fn (User $user) => ['id' => $user->id, 'name' => $user->name, 'email' => $user->email, 'avatar_url' => $user->avatarUrl(), 'roles' => $user->getRoleNames()->values(), 'status' => $user->status->value, 'approval' => $user->approved_at ? 'approved' : 'pending', 'password_status' => $user->must_change_password ? 'change-required' : 'normal', 'can_approve' => $request->user()->can('approve', $user), 'can_reset_password' => $request->user()->can('resetPassword', $user), 'verified_at' => $user->email_verified_at?->toIso8601String(), 'created_at' => $user->created_at?->toIso8601String()]);

        return Inertia::render('Users/Index', ['users' => $users, 'filters' => $request->only('search', 'role', 'status', 'verified', 'approval', 'password_status'), 'roles' => ['Super Admin', 'Admin', 'Teacher', 'Student']]);
    }

    public function create(): Response
    {
        $this->authorize('create', User::class);

        return Inertia::render('Users/Form', ['statuses' => array_map(fn ($status) => ['name' => $status->name, 'value' => $status->value], AccountStatus::cases()), 'roles' => auth()->user()->hasRole('Super Admin') ? ['Super Admin', 'Admin', 'Teacher', 'Student'] : ['Admin', 'Teacher', 'Student']]);
    }

    public function store(CreateUserRequest $request, CreateUser $action): RedirectResponse
    {
        $action->handle($request->validated());

        return redirect()->route('users.index')->with('success', 'User created and verification sent.');
    }

    public function edit(User $user): Response
    {
        $this->authorize('update', $user);

        return Inertia::render('Users/Form', ['account' => $user->load('roles:id,name'), 'statuses' => array_map(fn ($status) => ['name' => $status->name, 'value' => $status->value], AccountStatus::cases()), 'roles' => auth()->user()->hasRole('Super Admin') ? ['Super Admin', 'Admin', 'Teacher', 'Student'] : ['Admin', 'Teacher', 'Student']]);
    }

    public function update(UpdateUserRequest $request, User $user, UpdateUser $action): RedirectResponse
    {
        $action->handle($user, $request->validated());

        return back()->with('success', 'User updated.');
    }

    public function status(UpdateUserStatusRequest $request, User $user, ChangeUserStatus $action): RedirectResponse
    {
        $action->handle($user, AccountStatus::from($request->validated('status')));

        return back()->with('success', 'Status updated.');
    }

    public function role(AssignUserRoleRequest $request, User $user, AssignRoleToUser $action): RedirectResponse
    {
        $action->handle($user, $request->validated('role'));

        return back()->with('success', 'Role updated.');
    }

    public function resendVerification(User $user): RedirectResponse
    {
        $this->authorize('update', $user);
        if (! $user->hasVerifiedEmail()) {
            $user->sendEmailVerificationNotification();
        }

        return back()->with('success', 'Verification link sent.');
    }

    public function approve(User $user, Request $request, ApproveUser $action): RedirectResponse
    {
        $this->authorize('approve', $user);
        $action->handle($user, $request->user());

        return back()->with('success', 'Account approved.');
    }

    public function resetPassword(User $user, ResetUserPassword $action): RedirectResponse
    {
        $this->authorize('resetPassword', $user);
        $action->handle($user);

        return back()->with('success', 'Password reset. Temporary password: 123456789');
    }

    public function destroy(User $user, EnsureSuperAdminContinuity $guard, AuditLogger $audit): RedirectResponse
    {
        $this->authorize('delete', $user);
        DB::transaction(function () use ($user, $guard, $audit) {
            $guard->deleting($user);
            $audit->log('user.deleted', $user, $user->only('name', 'email', 'status'), []);
            $user->delete();
        });

        return redirect()->route('users.index')->with('success', 'User deleted.');
    }
}
