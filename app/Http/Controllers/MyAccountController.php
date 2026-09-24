<?php

namespace App\Http\Controllers;

use App\Http\Requests\MyAccount\UpdateAvatarRequest;
use App\Http\Requests\MyAccount\UpdatePasswordRequest;
use App\Http\Requests\MyAccount\UpdateProfileRequest;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
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
        $path = $file->storeAs($user->managedAvatarDirectory(), Str::uuid().'.'.$file->extension(), 'public');

        if (! $user->ownsManagedAvatarPath($path)) {
            Log::warning('Avatar storage write failed.', ['user_id' => $user->id]);

            throw ValidationException::withMessages(['avatar' => 'We could not save your profile photo. Please try again.']);
        }

        try {
            if (! $user->update(['avatar_path' => $path])) {
                throw new \RuntimeException('Avatar database update failed.');
            }
        } catch (\Throwable $exception) {
            $this->deleteStoredAvatar($user, $path, 'new avatar after database failure');
            Log::error('Avatar database update failed after storage write.', ['user_id' => $user->id, 'exception' => $exception]);

            throw ValidationException::withMessages(['avatar' => 'We could not save your profile photo. Please try again.']);
        }

        $this->deleteStoredAvatar($user, $previousPath, 'previous avatar after replacement');

        return to_route('my-account.profile')->with('success', 'Your profile photo has been updated.');
    }

    public function destroyAvatar(Request $request): RedirectResponse
    {
        $user = $request->user();
        $previousPath = $user->avatar_path;

        if (! $user->ownsManagedAvatarPath($previousPath)) {
            $user->update(['avatar_path' => null]);

            return to_route('my-account.profile')->with('success', 'Your profile photo has been removed.');
        }

        try {
            if (! $user->update(['avatar_path' => null])) {
                throw new \RuntimeException('Avatar database update failed.');
            }

            if (! Storage::disk('public')->delete($previousPath)) {
                throw new \RuntimeException('Avatar storage deletion failed.');
            }
        } catch (\Throwable $exception) {
            if ($user->fresh()?->avatar_path !== $previousPath) {
                try {
                    if (! $user->update(['avatar_path' => $previousPath])) {
                        throw new \RuntimeException('Avatar path restore failed.');
                    }
                } catch (\Throwable $restoreException) {
                    Log::critical('Avatar path could not be restored after deletion failure.', ['user_id' => $user->id, 'exception' => $restoreException]);
                }
            }

            Log::error('Avatar removal failed.', ['user_id' => $user->id, 'exception' => $exception]);

            throw ValidationException::withMessages(['avatar' => 'We could not remove your profile photo. Please try again.']);
        }

        return to_route('my-account.profile')->with('success', 'Your profile photo has been removed.');
    }

    private function deleteStoredAvatar($user, mixed $path, string $context): void
    {
        if ($user->ownsManagedAvatarPath($path) && ! Storage::disk('public')->delete($path)) {
            Log::warning('Avatar storage cleanup failed.', ['user_id' => $user->id, 'context' => $context]);
        }
    }
}
