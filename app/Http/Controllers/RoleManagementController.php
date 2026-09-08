<?php

namespace App\Http\Controllers;

use App\Actions\Users\UpdateRolePermissions;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

class RoleManagementController extends Controller
{
    private const CORE_ROLES = ['Super Admin', 'Admin', 'Teacher', 'Student'];

    public function index(Request $request): Response
    {
        abort_unless($request->user()->can('users.view'), 403);
        $roles = Role::query()->whereIn('name', self::CORE_ROLES)->withCount(['permissions', 'users'])->orderBy('name')->get()
            ->map(fn (Role $role) => ['id' => $role->id, 'name' => $role->name, 'permission_count' => $role->permissions_count, 'user_count' => $role->users_count, 'permissions' => $role->permissions()->pluck('name')->values()]);

        return Inertia::render('Roles/Index', ['roles' => $roles, 'permissionGroups' => $request->user()->hasRole('Super Admin') ? $this->permissionGroups() : [], 'canManagePermissions' => $request->user()->hasRole('Super Admin')]);
    }

    public function update(Request $request, Role $role, UpdateRolePermissions $action): RedirectResponse
    {
        abort_unless($request->user()->hasRole('Super Admin') && in_array($role->name, self::CORE_ROLES, true), 403);
        $validated = $request->validate(['permissions' => ['array'], 'permissions.*' => ['string', Rule::exists('permissions', 'name')]]);
        $action->handle($role, $validated['permissions'] ?? []);

        return back()->with('success', "{$role->name} permissions updated.");
    }

    private function permissionGroups(): array
    {
        return Permission::query()->orderBy('name')->pluck('name')->groupBy(fn (string $name) => ucfirst(str($name)->before('.')->replace('-', ' ')))->map(fn ($permissions, $domain) => ['domain' => $domain, 'permissions' => $permissions->values()])->values();
    }
}
