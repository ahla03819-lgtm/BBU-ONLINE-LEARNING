<?php

namespace App\Http\Middleware;

use Illuminate\Http\Request;
use Inertia\Middleware;

class HandleInertiaRequests extends Middleware
{
    protected $rootView = 'app';

    public function share(Request $request): array
    {
        return [...parent::share($request), 'auth' => ['user' => $request->user()?->only('id', 'name', 'email', 'status'), 'roles' => $request->user()?->getRoleNames() ?? [], 'permissions' => $request->user()?->getAllPermissions()->pluck('name') ?? []], 'flash' => ['success' => fn () => $request->session()->get('success')]];
    }
}
