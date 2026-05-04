<?php

namespace Tests\Feature;

use App\Http\Controllers\OnboardingController;
use App\Http\Controllers\ProfileWizardController;
use App\Models\AdminSetting;
use App\Models\EducationDegree;
use App\Models\MatrimonyProfile;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use ReflectionClass;
use Tests\TestCase;

/**
 * Audits whether onboarding step 4 POST fields appear in the education-career snapshot core
 * (MutationService applies {@code snapshot.core} to {@code matrimony_profiles}).
 */
class RegistrationPersistenceAuditTest extends TestCase
{
    use RefreshDatabase;

    public function test_step4_snapshot_core_contains_working_with_and_profession_after_hydrate(): void
    {
        AdminSetting::setValue('mobile_verification_mode', 'off');

        $this->seed(\Database\Seeders\MasterLookupSeeder::class);
        $this->seed(\Database\Seeders\EducationSeeder::class);
        $this->seed(\Database\Seeders\EducationCareerTemporarySeeder::class);

        $user = User::factory()->create();
        $profile = MatrimonyProfile::factory()->create(['user_id' => $user->id]);

        $wwId = DB::table('working_with_types')->where('slug', 'private_company')->value('id');
        $profId = DB::table('professions')
            ->where('slug', 'software-professional')
            ->where('working_with_type_id', $wwId)
            ->value('id');
        $degreeCode = EducationDegree::query()->value('code');

        $this->assertNotNull($wwId);
        $this->assertNotNull($profId);
        $this->assertNotNull($degreeCode);

        $request = Request::create(route('matrimony.onboarding.store', ['step' => 4]), 'POST', [
            'highest_education' => $degreeCode,
            'working_with_type_id' => (string) $wwId,
            'profession_id' => (string) $profId,
            'company_name' => 'Audit Company',
        ]);
        $request->setUserResolver(static fn () => $user);

        $onboarding = app(OnboardingController::class);
        $hydrateMethod = (new ReflectionClass(OnboardingController::class))->getMethod('hydrateEducationCareerContext');
        $hydrateMethod->setAccessible(true);
        $hydrateMethod->invoke($onboarding, $request, $profile);

        $educationService = app(\App\Services\EducationService::class);
        $educationService->mergeMultiselectEducationIntoRequest($request);

        $this->assertTrue($request->filled('working_with_type_id'), 'Hydrate + merge must leave posted working_with_type_id.');
        $this->assertTrue($request->filled('profession_id'), 'Hydrate + merge must leave posted profession_id.');
        $this->assertSame((string) $wwId, (string) $request->input('working_with_type_id'));

        $wizard = app(ProfileWizardController::class);
        $buildMethod = (new ReflectionClass(ProfileWizardController::class))->getMethod('buildEducationCareerSnapshot');
        $buildMethod->setAccessible(true);
        /** @var array<string, mixed> $snapshot */
        $snapshot = $buildMethod->invoke($wizard, $request, $profile);

        $core = $snapshot['core'] ?? [];
        $this->assertSame((int) $wwId, (int) ($core['working_with_type_id'] ?? 0), 'Snapshot core should carry posted working_with_type_id.');
        $this->assertSame((int) $profId, (int) ($core['profession_id'] ?? 0), 'Snapshot core should carry posted profession_id.');
        $this->assertSame('Audit Company', $core['company_name'] ?? null);
    }

    public function test_manual_snapshot_applies_working_with_columns_on_matrimony_profiles(): void
    {
        $this->seed(\Database\Seeders\MasterLookupSeeder::class);
        $this->seed(\Database\Seeders\EducationCareerTemporarySeeder::class);

        $user = User::factory()->create();
        $profile = MatrimonyProfile::factory()->create(['user_id' => $user->id]);

        $wwId = (int) DB::table('working_with_types')->where('slug', 'private_company')->value('id');
        $profId = (int) DB::table('professions')
            ->where('slug', 'software-professional')
            ->where('working_with_type_id', $wwId)
            ->value('id');

        app(\App\Services\MutationService::class)->applyManualSnapshot($profile, [
            'core' => [
                'working_with_type_id' => $wwId,
                'profession_id' => $profId,
                'company_name' => 'Direct mutation',
            ],
        ], (int) $user->id, 'manual');

        $profile->refresh();
        $this->assertSame($wwId, (int) $profile->working_with_type_id);
        $this->assertSame($profId, (int) $profile->profession_id);
        $this->assertSame('Direct mutation', $profile->company_name);
    }
}
