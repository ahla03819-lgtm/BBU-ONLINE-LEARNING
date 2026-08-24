<?php

namespace App\Http\Controllers;

use App\Actions\Users\AssignRoleToUser;
use App\Actions\Users\ChangeUserStatus;
use App\Actions\Users\CreateUser;
use App\Actions\Users\EnsureSuperAdminContinuity;
use App\Actions\Users\UpdateUser;
use App\Enums\AccountStatus;
use App\Http\Requests\AssignUserRoleRequest;
use App\Http\Requests\CreateUserRequest;
use App\Http\Requests\UpdateUserRequest;
use App\Http\Requests\UpdateUserStatusRequest;
use App\Models\User;
use App\Services\AuditLogger;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

class UserController extends Controller
{
    public function index(): Response
    {
        $this->authorize('viewAny', User::class);

        return Inertia::render('Users/Index', ['users' => User::query()->with('roles:id,name')->latest()->paginate(15)]);
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
