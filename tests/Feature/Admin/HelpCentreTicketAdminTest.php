<?php

namespace Tests\Feature\Admin;

use App\Models\HelpCentreTicket;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class HelpCentreTicketAdminTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_can_view_help_centre_ticket_queue(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);
        $member = User::factory()->create();

        HelpCentreTicket::query()->create([
            'user_id' => $member->id,
            'ticket_code' => 'HC-ABC123',
            'query_text' => 'Payment issue',
            'normalized_query' => 'payment issue',
            'intent' => 'payment_help',
            'escalated' => false,
            'status' => 'auto_resolved',
            'bot_reply' => 'Try refreshing.',
        ]);

        $this->actingAs($admin)
            ->get(route('admin.help-centre.tickets.index'))
            ->assertOk()
            ->assertSee('Help centre tickets');
    }

    public function test_admin_can_mark_ticket_resolved(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);
        $member = User::factory()->create();

        $ticket = HelpCentreTicket::query()->create([
            'user_id' => $member->id,
            'ticket_code' => 'HC-OPEN12',
            'query_text' => 'Specific unknown issue',
            'normalized_query' => 'specific unknown issue',
            'intent' => 'escalation',
            'escalated' => true,
            'status' => 'open',
            'bot_reply' => 'Escalated.',
        ]);

        $this->actingAs($admin)
            ->post(route('admin.help-centre.tickets.resolve', $ticket))
            ->assertRedirect()
            ->assertSessionHas('success');

        $this->assertDatabaseHas('help_centre_tickets', [
            'id' => $ticket->id,
            'status' => 'resolved',
        ]);
        $this->assertDatabaseHas('help_centre_ticket_workflows', [
            'help_centre_ticket_id' => $ticket->id,
        ]);
    }

    public function test_admin_can_assign_ticket_and_add_note_from_detail_page(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);
        $admin2 = User::factory()->create(['is_admin' => true]);
        $member = User::factory()->create();

        $ticket = HelpCentreTicket::query()->create([
            'user_id' => $member->id,
            'ticket_code' => 'HC-DET123',
            'query_text' => 'Need callback support',
            'normalized_query' => 'need callback support',
            'intent' => 'escalation',
            'escalated' => true,
            'status' => 'open',
            'bot_reply' => 'Escalated.',
        ]);

        $this->actingAs($admin)
            ->get(route('admin.help-centre.tickets.show', $ticket))
            ->assertOk()
            ->assertSee('Internal notes');

        $this->actingAs($admin)
            ->post(route('admin.help-centre.tickets.assign', $ticket), [
                'assigned_admin_id' => $admin2->id,
                'priority' => 'high',
            ])
            ->assertRedirect()
            ->assertSessionHas('success');

        $this->assertDatabaseHas('help_centre_ticket_workflows', [
            'help_centre_ticket_id' => $ticket->id,
            'assigned_admin_id' => $admin2->id,
            'priority' => 'high',
        ]);

        $this->actingAs($admin)
            ->post(route('admin.help-centre.tickets.notes', $ticket), [
                'note' => 'Called user and requested screenshot proof.',
            ])
            ->assertRedirect()
            ->assertSessionHas('success');

        $this->assertDatabaseHas('help_centre_ticket_notes', [
            'help_centre_ticket_id' => $ticket->id,
            'admin_user_id' => $admin->id,
            'note' => 'Called user and requested screenshot proof.',
        ]);
    }
}
