<?php

namespace Tests\Feature\Admin;

use App\Models\AdminAuditLog;
use App\Models\AdminSetting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CommunicationPolicyMessagingTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_can_update_messaging_policy_with_reason_and_audit_is_created(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);

        $payload = [
            'reason' => 'Enable chat policy changes for testing.',
            'allow_messaging' => '1',
            'messaging_mode' => 'free_chat_with_reply_gate',
            // Force a real change vs defaults so controller stores + sets success.
            'max_consecutive_messages_without_reply' => 3,
            'reply_gate_cooling_hours' => 25,
            'max_messages_per_day_per_sender' => 20,
            'max_messages_per_week_per_sender' => 100,
            'max_messages_per_month_per_sender' => 300,
            'max_new_conversations_per_day' => 10,
            'allow_image_messages' => '1',
            'image_messages_audience' => 'paid_only',

            // keep existing required contact policy fields
            'contact_request_mode' => 'mutual_only',
            'reject_cooldown_days' => 90,
            'pending_expiry_days' => 7,
            'max_requests_per_day_per_sender' => 0,
            'grant_approve_once' => 1,
            'grant_approve_7_days' => 1,
            'grant_approve_30_days' => 1,
            'scope_email' => 1,
            'scope_phone' => 1,
            'scope_whatsapp' => 1,
        ];

        $this->actingAs($admin)
            ->put(route('admin.communication-policy.update'), $payload)
            ->assertRedirect()
            ->assertSessionHas('success');

        $this->assertSame('free_chat_with_reply_gate', AdminSetting::getValue('communication_messaging_mode'));
        $this->assertSame('3', (string) AdminSetting::getValue('communication_max_consecutive_messages_without_reply'));

        $this->assertDatabaseHas('admin_audit_logs', [
            'action_type' => 'communication_policy_update',
            'entity_type' => 'communication_policy',
        ]);

        $log = AdminAuditLog::query()->latest('id')->first();
        $this->assertNotNull($log);
        $this->assertStringContainsString('max_consecutive_messages_without_reply', (string) $log->reason);
    }

    public function test_invalid_messaging_policy_values_are_rejected(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);

        $payload = [
            'reason' => 'Trying invalid values.',
            'allow_messaging' => '1',
            'messaging_mode' => 'free_chat_with_reply_gate',
            'max_consecutive_messages_without_reply' => 0, // invalid
            'reply_gate_cooling_hours' => 24,
            'max_messages_per_day_per_sender' => 20,
            'max_messages_per_week_per_sender' => 100,
            'max_messages_per_month_per_sender' => 300,
            'max_new_conversations_per_day' => 10,
            'allow_image_messages' => '1',
            'image_messages_audience' => 'paid_only',

            'contact_request_mode' => 'mutual_only',
            'reject_cooldown_days' => 90,
            'pending_expiry_days' => 7,
            'max_requests_per_day_per_sender' => 0,
            'grant_approve_once' => 1,
            'grant_approve_7_days' => 1,
            'grant_approve_30_days' => 1,
            'scope_email' => 1,
            'scope_phone' => 1,
            'scope_whatsapp' => 1,
        ];

        $this->actingAs($admin)
            ->put(route('admin.communication-policy.update'), $payload)
            ->assertRedirect()
            ->assertSessionHasErrors('max_consecutive_messages_without_reply');
    }
}

