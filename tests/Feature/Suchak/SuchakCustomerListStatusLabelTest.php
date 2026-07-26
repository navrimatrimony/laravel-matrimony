<?php

namespace Tests\Feature\Suchak;

use App\Models\MatrimonyProfile;
use App\Models\SuchakAccount;
use App\Models\SuchakBiodataIntakeLink;
use App\Models\SuchakProfileRepresentation;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * The customer list used to prettify raw DB enums with
 * ucfirst()/ucwords(str_replace('_', ' ', ...)), which is not translation at
 * all: a Suchak running the app in Marathi read English words like
 * "Intake Uploaded" and "Pending" in the middle of an otherwise Marathi screen.
 *
 * Every status word on this screen now resolves through the one Suchak
 * vocabulary (suchak.labels.*), so these tests pin both languages AND the
 * degradation path for an enum value nobody has translated yet.
 */
class SuchakCustomerListStatusLabelTest extends TestCase
{
    use RefreshDatabase;

    /**
     * A represented customer's status must be Marathi under Accept-Language: mr
     * and English under en — the same row, the same enum, two languages.
     */
    public function test_representation_status_is_translated_in_both_languages(): void
    {
        $profile = MatrimonyProfile::factory()->create();
        $account = $this->accountRepresenting($profile, [
            'representation_status' => SuchakProfileRepresentation::STATUS_CONSENT_PENDING,
        ]);

        $this->assertSame(
            'संमती बाकी',
            $this->rowFor($account, 'mr')['status_label'],
            'A Marathi Suchak must not see an English representation status.'
        );
        $this->assertSame(
            'Consent pending',
            $this->rowFor($account, 'en')['status_label'],
        );
    }

    /**
     * The live defect: `intake_uploaded` rendered as the English "Intake
     * Uploaded". In Marathi it must say what the row actually is — a scanned
     * biodata that still has to become a customer.
     */
    public function test_intake_source_status_is_translated_in_both_languages(): void
    {
        $account = $this->accountWithPendingIntake();

        $this->assertSame(
            'बायोडाटा आला, प्रोफाइल बाकी',
            $this->rowFor($account, 'mr')['status_label'],
            'The pending-intake row must read as Marathi, not "Intake Uploaded".'
        );
        $this->assertSame(
            'Biodata received, profile pending',
            $this->rowFor($account, 'en')['status_label'],
        );

        // The exact string the production bug reported, in either language.
        $this->assertNotSame('Intake Uploaded', $this->rowFor($account, 'mr')['status_label']);
    }

    /**
     * An enum value that predates or postdates the vocabulary must degrade to a
     * neutral "unknown" — never a PHP notice, never blank, and deliberately
     * never the raw English token, because leaking English into a Marathi list
     * is the very bug being fixed here.
     */
    public function test_an_untranslated_status_degrades_to_unknown_not_to_raw_english(): void
    {
        $profile = MatrimonyProfile::factory()->create();
        $account = $this->accountRepresenting($profile);

        // Written past the model so a genuinely unmapped value reaches the
        // presenter, exactly as a future migration would introduce one.
        DB::table('suchak_profile_representations')
            ->where('suchak_account_id', $account->id)
            ->update(['representation_status' => 'awaiting_village_verification']);

        $marathi = $this->rowFor($account, 'mr')['status_label'];

        $this->assertSame('स्थिती अज्ञात', $marathi);
        $this->assertSame('Unknown status', $this->rowFor($account, 'en')['status_label']);

        // The three failure modes this fallback exists to prevent.
        $this->assertNotSame('', $marathi);
        $this->assertStringNotContainsString('Awaiting', $marathi);
        $this->assertStringNotContainsString('awaiting_village_verification', $marathi);
    }

    /**
     * A row with no profile is a scan awaiting conversion, not an empty
     * customer. `completion_percent` deliberately stays a non-null 0 (the app
     * branches on `profile_id`/`kind` instead) — pinned here because turning it
     * null would make every already-installed app read the row as 100%.
     */
    public function test_pending_intake_rows_keep_a_non_null_zero_completion(): void
    {
        $row = $this->rowFor($this->accountWithPendingIntake(), 'mr');

        $this->assertSame('intake_pending', $row['kind']);
        $this->assertNull($row['profile_id']);
        $this->assertSame(0, $row['completion_percent'], 'completion_percent must stay a non-null int.');
        $this->assertSame([], $row['incomplete_sections']);
    }

    /**
     * @param  array<string, mixed>  $representationAttributes
     */
    private function accountRepresenting(MatrimonyProfile $profile, array $representationAttributes = []): SuchakAccount
    {
        $account = $this->verifiedAccount();

        SuchakProfileRepresentation::factory()->create(array_merge([
            'suchak_account_id' => $account->id,
            'matrimony_profile_id' => $profile->id,
        ], $representationAttributes));

        return $account;
    }

    private function accountWithPendingIntake(): SuchakAccount
    {
        $account = $this->verifiedAccount();

        SuchakBiodataIntakeLink::factory()->create([
            'suchak_account_id' => $account->id,
            'matrimony_profile_id' => null,
            'source_status' => SuchakBiodataIntakeLink::STATUS_INTAKE_UPLOADED,
        ]);

        return $account;
    }

    private function verifiedAccount(): SuchakAccount
    {
        return SuchakAccount::factory()->create([
            'user_id' => User::factory()->create()->id,
            'verification_status' => SuchakAccount::VERIFICATION_VERIFIED,
            'public_status' => SuchakAccount::PUBLIC_ACTIVE,
            'registration_completed_at' => now(),
        ]);
    }

    /**
     * The row as the app receives it, in the requested language — asserted
     * through the real endpoint so the locale middleware is part of the test.
     *
     * @return array<string, mixed>
     */
    private function rowFor(SuchakAccount $account, string $locale): array
    {
        Sanctum::actingAs($account->user);

        $rows = $this->getJson('/api/v1/suchak/customers', ['Accept-Language' => $locale])
            ->assertOk()
            ->json('data.customers');

        $this->assertNotEmpty($rows, 'The account should have at least one customer row.');

        return $rows[0];
    }
}
