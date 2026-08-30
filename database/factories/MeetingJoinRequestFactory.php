<?php

namespace Database\Factories;

use App\Enums\MeetingJoinRequestStatus;
use App\Models\Meeting;
use App\Models\MeetingJoinRequest;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

class MeetingJoinRequestFactory extends Factory
{
    protected $model = MeetingJoinRequest::class;

    public function definition(): array
    {
        return ['meeting_id' => Meeting::factory(), 'requester_user_id' => User::factory(), 'status' => MeetingJoinRequestStatus::Pending, 'requested_at' => now()];
    }

    public function admitted(?User $by = null): static
    {
        return $this->state(['status' => MeetingJoinRequestStatus::Admitted, 'decided_at' => now(), 'decided_by' => $by?->id]);
    }

    public function denied(?User $by = null): static
    {
        return $this->state(['status' => MeetingJoinRequestStatus::Denied, 'decided_at' => now(), 'decided_by' => $by?->id]);
    }
}
