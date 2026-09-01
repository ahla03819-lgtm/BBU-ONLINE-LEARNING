<?php

namespace App\Http\Controllers;

use App\Http\Requests\MyAccount\UpdateAvatarRequest;
use App\Http\Requests\MyAccount\UpdatePasswordRequest;
use App\Http\Requests\MyAccount\UpdateProfileRequest;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class MyAccountController extends Controller
{
    public function show(Request $request, string $section = 'profile'): Response
    {
        abort_unless(in_array($section, ['profile', 'security', 'notifications', 'appearance'], true), 404);

        $user = $request->user()->loadMissing('studentProfile', 'teacherProfile');
        $context = $user->studentProfile
            ? ['label' => 'Student', 'identifier' => $user->studentProfile->student_number]
            : ($user->teacherProfile ? ['label' => 'Teacher', 'identifier' => $user->teacherProfile->employee_number] : null);

        return Inertia::render('MyAccount/Show', [
            'section' => $section,
            'account' => [
                'name' => $user->name,
                'email' => $user->email,
                'avatar_url' => $user->avatarUrl(),
                'roles' => $user->getRoleNames()->values(),
                'context' => $context,
            ],
        ]);
    }

    public function updateProfile(UpdateProfileRequest $request): RedirectResponse
    {
        $request->user()->update($request->safe()->only('name'));

        return to_route('my-account.profile')->with('success', 'Your profile has been updated.');
    }

    public function updatePassword(UpdatePasswordRequest $request): RedirectResponse
    {
        $user = $request->user();
        if (! Hash::check((string) $request->string('current_password'), $user->password)) {
            throw ValidationException::withMessages(['current_password' => 'The current password is incorrect.']);
        }

        $user->update(['password' => (string) $request->string('password')]);

        return to_route('my-account.show', ['section' => 'security'])->with('success', 'Your password has been updated.');
    }

    public function updateAvatar(UpdateAvatarRequest $request): RedirectResponse
    {
        $user = $request->user();
        $previousPath = $user->avatar_path;
        $file = $request->file('avatar');
        $path = $file->storeAs("user-avatars/{$user->id}", Str::uuid().'.'.$file->extension(), 'public');

        try {
            $user->update(['avatar_path' => $path]);
        } catch (\Throwable $exception) {
            Storage::disk('public')->delete($path);

            throw $exception;
        }

        $this->deleteManagedAvatar($user->id, $previousPath);

        return to_route('my-account.profile')->with('success', 'Your profile photo has been updated.');
    }

    public function destroyAvatar(Request $request): RedirectResponse
    {
        $user = $request->user();
        $previousPath = $user->avatar_path;

        $user->update(['avatar_path' => null]);
        $this->deleteManagedAvatar($user->id, $previousPath);

        return to_route('my-account.profile')->with('success', 'Your profile photo has been removed.');
    }

    private function deleteManagedAvatar(int $userId, ?string $path): void
    {
        if ($path && Str::startsWith($path, "user-avatars/{$userId}/")) {
            Storage::disk('public')->delete($path);
        }
    }
}
