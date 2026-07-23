<?php

namespace Tests\Feature\Suchak;

use App\Models\SuchakAccount;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Both languages already live in the master tables and translation files. The
 * meta endpoint used to hardcode English, so a Suchak working in Marathi saw
 * "Candidate self" and "Parent / guardian" mid-sentence on a Marathi screen.
 */
class SuchakManualProfileMetaLocaleTest extends TestCase
{
    use RefreshDatabase;

    private function actingSuchak(): void
    {
        $user = User::factory()->create();
        SuchakAccount::factory()->create([
            'user_id' => $user->id,
            'verification_status' => SuchakAccount::VERIFICATION_VERIFIED,
            'public_status' => SuchakAccount::PUBLIC_ACTIVE,
            'registration_completed_at' => now(),
        ]);
        Sanctum::actingAs($user);
    }

    public function test_meta_returns_marathi_labels_when_marathi_is_requested(): void
    {
        $this->actingSuchak();

        $data = $this->getJson('/api/v1/suchak/manual-profiles/meta?locale=mr')
            ->assertOk()
            ->json('data');

        $this->assertSame('mr', $data['locale']);

        $options = $data['registering_for_options'];
        $this->assertSame('पालक / पालकप्रतिनिधी', $options['parent_guardian']);
        $this->assertSame('नातेवाईक', $options['relative']);

        // A Suchak never fills this in for themselves, so "self" must read as
        // the candidate, not "Myself".
        $this->assertSame('उमेदवार स्वतः', $options['self']);
        $this->assertNotSame('Candidate self', $options['self']);

        $this->assertSame('हा मोबाइल कोणाचा?', $data['consent_relation_label']);
        $this->assertNotEmpty($data['consent_relation_hint']);
    }

    public function test_meta_still_returns_english_by_default(): void
    {
        $this->actingSuchak();

        $data = $this->getJson('/api/v1/suchak/manual-profiles/meta')
            ->assertOk()
            ->json('data');

        $this->assertSame('en', $data['locale']);
        $this->assertSame('Candidate themselves', $data['registering_for_options']['self']);
        $this->assertSame('Whose mobile is this?', $data['consent_relation_label']);
    }
}
