<?php

namespace Tests\Unit;

use App\Notifications\PlanExpiringSoonNotification;
use App\Support\NotificationQueue;
use Tests\TestCase;

class NotificationQueueTest extends TestCase
{
    public function test_mail_queue_routes_when_enabled(): void
    {
        config([
            'notifications.queue.mail_enabled' => true,
            'notifications.queue.name' => 'notifications',
            'notifications.queue.connection' => 'database',
        ]);

        $notification = new PlanExpiringSoonNotification('gold', 3, '2026-06-01');

        $this->assertSame(['mail' => 'database'], $notification->viaConnections());
        $this->assertSame(['mail' => 'notifications'], $notification->viaQueues());
        $this->assertTrue(NotificationQueue::mailEnabled());
    }

    public function test_mail_queue_skipped_when_disabled(): void
    {
        config(['notifications.queue.mail_enabled' => false]);

        $notification = new PlanExpiringSoonNotification('gold', 3, '2026-06-01');

        $this->assertSame([], $notification->viaConnections());
        $this->assertSame([], $notification->viaQueues());
    }
}
