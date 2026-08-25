<?php

namespace Tests\Feature\Phase3;

use App\Actions\Collaboration\CreateAnnouncement;
use App\Actions\Collaboration\PublishDueAnnouncements;
use App\Enums\AnnouncementStatus;
use App\Enums\ChannelType;
use App\Models\Announcement;
use App\Models\AuditLog;
use App\Models\Channel;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AnnouncementLifecycleTest extends TestCase
{
    use RefreshDatabase;

    public function test_scheduled_publication_is_due_ordered_idempotent_and_audited(): void
    {
        $channel = Channel::factory()->create(['type' => ChannelType::Announcement, 'default_slot' => 2, 'slug' => 'announcements']);
        $first = Announcement::factory()->create(['channel_id' => $channel, 'status' => AnnouncementStatus::Scheduled, 'publish_at' => now()->subMinute()]);
        $second = Announcement::factory()->create(['channel_id' => $channel, 'status' => AnnouncementStatus::Scheduled, 'publish_at' => now()->subMinute()]);
        $action = app(PublishDueAnnouncements::class);
        $this->assertSame(2, $action->handle(1));
        $this->assertSame(0, $action->handle(1));
        $this->assertSame(AnnouncementStatus::Published, $first->fresh()->status);
        $this->assertSame(AnnouncementStatus::Published, $second->fresh()->status);
        $this->assertDatabaseCount('audit_logs', 2);
    }

    public function test_publish_due_console_command_is_repeatable(): void
    {
        $channel = Channel::factory()->create(['type' => ChannelType::Announcement, 'default_slot' => 2, 'slug' => 'announcements']);
        Announcement::factory()->create(['channel_id' => $channel, 'status' => AnnouncementStatus::Scheduled, 'publish_at' => now()->subMinute()]);

        $this->artisan('announcements:publish-due --chunk=1')->expectsOutput('Published 1 due announcements.')->assertSuccessful();
        $this->artisan('announcements:publish-due --chunk=1')->expectsOutput('Published 0 due announcements.')->assertSuccessful();
    }

    public function test_expired_announcements_are_excluded_from_active_feed_but_retained(): void
    {
        $channel = Channel::factory()->create(['type' => ChannelType::Announcement, 'default_slot' => 2, 'slug' => 'announcements']);
        Announcement::factory()->published()->create(['channel_id' => $channel, 'expires_at' => now()->subMinute()]);
        Announcement::factory()->published()->create(['channel_id' => $channel, 'expires_at' => now()->addDay()]);
        $this->assertSame(1, Announcement::query()->activeFeed()->count());
        $this->assertSame(2, Announcement::count());
    }

    public function test_announcement_body_is_never_written_to_audit_metadata(): void
    {
        $actor = User::factory()->create();
        $this->actingAs($actor);
        $channel = Channel::factory()->create(['type' => ChannelType::Announcement, 'default_slot' => 2, 'slug' => 'announcements']);
        app(CreateAnnouncement::class)->handle($channel, ['title' => 'Safe title', 'body' => 'SECRET BODY CONTENT']);
        $this->assertStringNotContainsString('SECRET BODY CONTENT', AuditLog::query()->get()->toJson());
    }
}
