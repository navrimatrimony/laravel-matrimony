<?php

require __DIR__.'/../vendor/autoload.php';
$app = require __DIR__.'/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Models\MatrimonyProfile;
use App\Models\SuchakConsent;
use App\Models\SuchakProfileRepresentation;
use App\Models\User;
use App\Modules\Suchak\Services\SuchakConsentService;
use App\Services\Profile\ProfileCanonicalResidenceService;
use Illuminate\Support\Facades\Hash;

$profileId = (int) ($argv[1] ?? 248);
$profile = MatrimonyProfile::findOrFail($profileId);
$rep = SuchakProfileRepresentation::where('matrimony_profile_id', $profileId)->firstOrFail();
$suchakUser = User::findOrFail($rep->suchakAccount->user_id);

echo "Completing profile #{$profileId} for search visibility test...\n";

// Fix test suchak password if requested
$u211 = User::where('mobile', '2222222222')->first();
if ($u211 && ! Hash::check('22222222', $u211->password)) {
    $u211->password = Hash::make('22222222');
    $u211->save();
    echo "Fixed password for 2222222222\n";
}

// Canonical residence (Sangli id=40)
ProfileCanonicalResidenceService::upsertSelfCurrent($profileId, 40, 'Flat 101, Wonder Residency, Sangli', true, false);
$profile->birth_city_id = 40;
if (! $profile->religion_id) {
    $profile->religion_id = 4; // Hindu
}
if (! $profile->profile_photo) {
    $profile->profile_photo = 'pending/e2e-test-photo.jpg';
}
$profile->lifecycle_state = 'active';
$profile->is_suspended = false;
$profile->save();

echo "profile lifecycle={$profile->lifecycle_state} location_id={$profile->location_id} birth_city={$profile->birth_city_id} religion={$profile->religion_id} photo={$profile->profile_photo}\n";

/** @var SuchakConsentService $consentService */
$consentService = app(SuchakConsentService::class);

if ($rep->consent_status !== SuchakProfileRepresentation::CONSENT_ACCEPTED) {
    $result = $consentService->requestConsent(
        $rep,
        $suchakUser,
        [
            'consent_type' => SuchakConsent::TYPE_ONE_YEAR,
            'consent_channel' => SuchakConsent::CHANNEL_OFFLINE_PROOF,
            'consent_given_by_name' => 'सौ. सुनीता परीक्षण गायकवाड',
            'relationship_to_candidate' => 'mother',
            'consent_mobile_number' => '9819988776',
        ],
        '127.0.0.1',
        'suchak-e2e-script',
    );
    $consent = $result['consent'];
    $consentService->acceptManualProof(
        $consent,
        $suchakUser,
        [
            'consent_given_by_name' => 'सौ. सुनीता परीक्षण गायकवाड',
            'relationship_to_candidate' => 'mother',
            'consent_mobile_number' => '9819988776',
            'evidence_note' => 'E2E test: parent consent captured during suchak manual registration.',
        ],
        '127.0.0.1',
        'suchak-e2e-script',
    );
    echo "Consent accepted for representation #{$rep->id}\n";
}

$rep->refresh();
echo "representation status={$rep->representation_status} consent={$rep->consent_status} publicly_visible=".($rep->isPubliclyVisible() ? 'yes' : 'no')."\n";

$searchCount = SuchakProfileRepresentation::query()
    ->publiclyRoutable()
    ->where('suchak_account_id', '!=', (int) $rep->suchak_account_id)
    ->whereHas('matrimonyProfile', fn ($q) => $q->whereKey($profileId))
    ->count();
echo "Cross-suchak search visibility (excluding owner): {$searchCount} (should be 0 for own profile)\n";

$visibleToOthers = SuchakProfileRepresentation::query()
    ->publiclyRoutable()
    ->whereHas('matrimonyProfile', fn ($q) => $q->whereKey($profileId))
    ->count();
echo "Total publicly routable for profile #{$profileId}: {$visibleToOthers}\n";
