<?php

namespace App\Http\Controllers;

use App\Services\AuditLogger;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class ForcedPasswordChangeController extends Controller
{
    public function show(): Response
    {
        return Inertia::render('Auth/SetPassword');
    }

    public function update(Request $request, AuditLogger $audit): RedirectResponse
    {
        $data = $request->validate(['password' => ['required', 'confirmed', Password::defaults()]]);
        if ($data['password'] === '123456789') {
            throw ValidationException::withMessages(['password' => 'Choose a password other than the temporary password.']);
        }
        $user = $request->user();
        $user->forceFill(['password' => Hash::make($data['password']), 'must_change_password' => false, 'remember_token' => Str::random(60)])->save();
        $request->session()->regenerate();
        $audit->log('user.password-initialized', $user, [], []);

        return to_route('dashboard')->with('success', 'Your password has been updated.');
    }
}
