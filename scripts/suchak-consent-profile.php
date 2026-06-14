<?php

require __DIR__.'/../vendor/autoload.php';
$app = require __DIR__.'/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Models\MatrimonyProfile;
use App\Models\SuchakConsent;
use App\Models\SuchakProfileRepresentation;
use App\Models\User;
use App\Modules\Suchak\Services\SuchakConsentService;

$profileId = (int) ($argv[1] ?? 251);
$profile = MatrimonyProfile::findOrFail($profileId);
$rep = SuchakProfileRepresentation::where('matrimony_profile_id', $profileId)->latest('id')->firstOrFail();
$user = User::findOrFail($rep->suchakAccount->user_id);
$consentService = app(SuchakConsentService::class);

if ($rep->consent_status !== SuchakProfileRepresentation::CONSENT_ACCEPTED) {
    $result = $consentService->requestConsent($rep, $user, [
        'consent_type' => SuchakConsent::TYPE_ONE_YEAR,
        'consent_channel' => SuchakConsent::CHANNEL_OFFLINE_PROOF,
        'consent_given_by_name' => 'श्री. रामराव परीक्षण शिंदे',
        'relationship_to_candidate' => 'father',
        'consent_mobile_number' => '9811122233',
    ], '127.0.0.1', 'suchak-e2e');
    $consentService->acceptManualProof($result['consent'], $user, [
        'consent_given_by_name' => 'श्री. रामराव परीक्षण शिंदे',
        'relationship_to_candidate' => 'father',
        'consent_mobile_number' => '9811122233',
        'evidence_note' => 'E2E intake flow: father consent recorded at Suchak office.',
    ], '127.0.0.1', 'suchak-e2e');
}

$rep->refresh();
echo "profile #{$profileId} name={$profile->full_name}\n";
echo "lifecycle={$profile->lifecycle_state} location={$profile->location_id}\n";
echo "rep status={$rep->representation_status} consent={$rep->consent_status} public=".($rep->isPubliclyVisible()?'yes':'no')."\n";
