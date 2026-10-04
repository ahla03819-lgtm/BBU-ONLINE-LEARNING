<?php

namespace App\Models;

use App\Enums\AccountStatus;
use App\Support\Locale;
use Database\Factories\UserFactory;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Spatie\Permission\Traits\HasRoles;

#[Fillable(['name', 'email', 'password', 'status', 'avatar_path', 'locale', 'approved_at', 'approved_by', 'must_change_password'])]
#[Hidden(['password', 'remember_token'])]
class User extends Authenticatable implements MustVerifyEmail
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, HasRoles, Notifiable;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'status' => AccountStatus::class,
            'last_login_at' => 'datetime',
            'approved_at' => 'datetime',
            'must_change_password' => 'boolean',
        ];
    }

    public function isActive(): bool
    {
        return $this->status === AccountStatus::Active;
    }

    /**
     * The interface language this account should be presented in.
     */
    public function preferredLocale(): string
    {
        return Locale::normalize($this->locale);
    }

    /**
     * Return the authoritative account role used for role-aware presentation.
     *
     * Profiles describe a person's academic relationship; they must not
     * override the privileges granted to their account.
     */
    public function effectiveRole(): string
    {
        foreach (['Super Admin', 'Admin', 'Teacher', 'Student'] as $role) {
            if ($this->hasRole($role)) {
                return $role;
            }
        }

        return 'Student';
    }

    public function avatarUrl(): ?string
    {
        // A managed path whose file has gone (worktree switch, manual cleanup)
        // must degrade to the caller's initials fallback instead of emitting a
        // URL that is guaranteed to fail on every page load.
        if (! $this->ownsManagedAvatarPath($this->avatar_path)) {
            return null;
        }

        if (! Storage::disk('public')->exists($this->avatar_path)) {
            return null;
        }

        return Storage::disk('public')->url($this->avatar_path);
    }

    public function managedAvatarDirectory(): string
    {
        return "user-avatars/{$this->id}";
    }

    public function ownsManagedAvatarPath(mixed $path): bool
    {
        if (! is_string($path) || $path === '' || str_contains($path, '..') || str_contains($path, chr(92))) {
            return false;
        }

        $directory = $this->managedAvatarDirectory().'/';

        if (! Str::startsWith($path, $directory)) {
            return false;
        }

        $filename = Str::after($path, $directory);

        return $filename !== '' && ! str_contains($filename, '/');
    }

    public function teacherProfile(): HasOne
    {
        return $this->hasOne(TeacherProfile::class);
    }

    public function conversationMemberships(): HasMany
    {
        return $this->hasMany(ConversationMember::class);
    }

    public function studentProfile(): HasOne
    {
        return $this->hasOne(StudentProfile::class);
    }

    public function createdChannels(): HasMany
    {
        return $this->hasMany(Channel::class, 'created_by');
    }

    public function announcements(): HasMany
    {
        return $this->hasMany(Announcement::class, 'author_id');
    }

    public function messages(): HasMany
    {
        return $this->hasMany(Message::class, 'sender_id');
    }

    public function channelReadStates(): HasMany
    {
        return $this->hasMany(ChannelReadState::class);
    }

    public function uploadedMessageAttachments(): HasMany
    {
        return $this->hasMany(MessageAttachment::class, 'uploaded_by');
    }

    public function messageReactions(): HasMany
    {
        return $this->hasMany(MessageReaction::class);
    }

    public function createdMeetings(): HasMany
    {
        return $this->hasMany(Meeting::class, 'created_by');
    }

    public function hostedMeetings(): HasMany
    {
        return $this->hasMany(Meeting::class, 'host_user_id');
    }

    public function meetingParticipations(): HasMany
    {
        return $this->hasMany(MeetingParticipant::class);
    }

    public function removedMeetingParticipants(): HasMany
    {
        return $this->hasMany(MeetingParticipant::class, 'removed_by');
    }

    public function createdAssignments(): HasMany
    {
        return $this->hasMany(Assignment::class, 'created_by');
    }

    public function gradedAssignments(): HasMany
    {
        return $this->hasMany(AssignmentGrade::class, 'graded_by');
    }

    public function userNotifications(): HasMany
    {
        return $this->hasMany(UserNotification::class);
    }

    public function openedAttendanceRegisters(): HasMany
    {
        return $this->hasMany(AttendanceRegister::class, 'opened_by');
    }

    public function finalizedAttendanceRegisters(): HasMany
    {
        return $this->hasMany(AttendanceRegister::class, 'finalized_by');
    }

    public function recordedAttendanceRecords(): HasMany
    {
        return $this->hasMany(AttendanceRecord::class, 'recorded_by');
    }

    public function correctedAttendanceRecordRevisions(): HasMany
    {
        return $this->hasMany(AttendanceRecordRevision::class, 'corrected_by');
    }
}
