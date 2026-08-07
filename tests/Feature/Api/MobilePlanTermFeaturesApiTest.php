<?php

namespace Tests\Feature\Api;

use App\Models\City;
use App\Models\MasterGender;
use App\Models\MatrimonyProfile;
use App\Models\PlanTerm;
use App\Models\User;
use App\Services\Profile\ProfileCanonicalResidenceService;
use Database\Seeders\MasterLookupSeeder;
use Database\Seeders\MinimalLocationSeeder;
use Database\Seeders\SubscriptionPlansSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Mobile plan catalog: each term carries final feature lines (Laravel SSOT). Clients must not multiply.
 */
class MobilePlanTermFeaturesApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(MinimalLocationSeeder::class);
        $this->seed(MasterLookupSeeder::class);
        $this->seed(SubscriptionPlansSeeder::class);
        ProfileCanonicalResidenceService::forgetCachedMasters();
    }

    public function test_each_term_includes_final_features_scaled_for_that_term(): void
    {
        $user = User::factory()->create(['is_admin' => false]);
        $genderId = MasterGender::query()->where('key', 'male')->where('is_active', true)->value('id');
        $this->assertNotNull($genderId);

        $profile = MatrimonyProfile::factory()->for($user)->create([
            'gender_id' => $genderId,
            'lifecycle_state' => 'draft',
        ]);

        $tbl = $profile->getTable();
        $leafId = (int) City::query()->where('name', 'Pune City')->firstOrFail()->id;
        if (Schema::hasColumn($tbl, 'location_id')) {
            DB::table($tbl)->where('id', $profile->id)->update(['location_id' => $leafId]);
        } else {
            ProfileCanonicalResidenceService::upsertSelfCurrent((int) $profile->id, $leafId, null, true, false);
        }
        $profile->update([
            'lifecycle_state' => 'active',
            'is_suspended' => false,
        ]);

        Sanctum::actingAs($user);

        $response = $this->getJson('/api/v1/plans');
        $response->assertOk();

        $plans = collect($response->json('plans') ?? []);
        $basic = $plans->firstWhere('slug', 'basic_male');
        $this->assertIsArray($basic);

        $terms = collect($basic['terms'] ?? []);
        $this->assertGreaterThanOrEqual(2, $terms->count());

        $monthly = $terms->firstWhere('billing_key', PlanTerm::BILLING_MONTHLY);
        $yearly = $terms->firstWhere('billing_key', PlanTerm::BILLING_YEARLY);
        $this->assertIsArray($monthly);
        $this->assertIsArray($yearly);
        $this->assertIsArray($monthly['features'] ?? null);
        $this->assertIsArray($yearly['features'] ?? null);
        $this->assertNotEmpty($monthly['features']);
        $this->assertNotEmpty($yearly['features']);

        $monthlyContact = collect($monthly['features'])->first(
            fn ($line): bool => is_string($line) && str_contains(mb_strtolower($line), 'contact')
        );
        $yearlyContact = collect($yearly['features'])->first(
            fn ($line): bool => is_string($line) && str_contains(mb_strtolower($line), 'contact')
        );
        $this->assertIsString($monthlyContact);
        $this->assertIsString($yearlyContact);
        $this->assertNotSame($monthlyContact, $yearlyContact);
        $this->assertMatchesRegularExpression('/\b60\b/', $monthlyContact);
        // lifetime 60 × 12 duration, then +20% bonus → 864
        $this->assertMatchesRegularExpression('/\b864\b/', $yearlyContact);

        $this->assertIsArray($basic['features'] ?? null);
        $this->assertNotEmpty($basic['features']);
    }
}
