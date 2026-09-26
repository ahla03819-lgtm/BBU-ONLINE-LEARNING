<?php

namespace Tests\Feature\PhaseC;

use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class CalendarIndexTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
    }

    public function test_authenticated_authorized_user_can_view_calendar_index(): void
    {
        $user = User::factory()->create();
        $user->assignRole('Teacher');

        $this->actingAs($user)
            ->get(route('calendar.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->component('Calendar/Index'));
    }
}
