<?php

namespace Tests\Feature;

use App\Jobs\Engagement\RunInactiveRemindersJob;
use App\Jobs\Engagement\RunNewMatchDigestJob;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class EngagementNotificationQueueTest extends TestCase
{
    use RefreshDatabase;

    public function test_inactive_reminders_command_queues_batch_job_when_enabled(): void
    {
        config(['notifications.queue.engagement_batches' => true]);
        Queue::fake();

        $this->artisan('engagement:inactive-reminders')
            ->assertSuccessful()
            ->expectsOutputToContain('queued');

        Queue::assertPushed(RunInactiveRemindersJob::class);
    }

    public function test_new_match_digest_command_queues_batch_job_when_enabled(): void
    {
        config(['notifications.queue.engagement_batches' => true]);
        Queue::fake();

        $this->artisan('engagement:new-match-digest')
            ->assertSuccessful()
            ->expectsOutputToContain('queued');

        Queue::assertPushed(RunNewMatchDigestJob::class);
    }

    public function test_engagement_commands_run_sync_when_queue_disabled(): void
    {
        config(['notifications.queue.engagement_batches' => false]);
        Queue::fake();

        $this->artisan('engagement:inactive-reminders')->assertSuccessful();
        $this->artisan('engagement:new-match-digest')->assertSuccessful();

        Queue::assertNothingPushed();
    }
}
