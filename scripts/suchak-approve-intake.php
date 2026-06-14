<?php

require __DIR__.'/../vendor/autoload.php';
$app = require __DIR__.'/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Models\BiodataIntake;
use App\Models\MasterMaritalStatus;
use App\Models\User;
use App\Modules\Suchak\Services\SuchakIntakeApplyService;

$id = (int) ($argv[1] ?? 500);
$intake = BiodataIntake::findOrFail($id);
$user = User::findOrFail((int) $intake->uploaded_by);
$snapshot = is_array($intake->parsed_json) ? $intake->parsed_json : [];
$core = is_array($snapshot['core'] ?? null) ? $snapshot['core'] : [];

$neverMarriedId = MasterMaritalStatus::query()->where('key', 'never_married')->value('id');
if ($neverMarriedId) {
    $core['marital_status_id'] = (int) $neverMarriedId;
    $core['marital_status'] = 'never_married';
}
$snapshot['core'] = $core;

$addresses = is_array($snapshot['addresses'] ?? null) ? $snapshot['addresses'] : [];
if ($addresses !== []) {
    $addresses[0]['location_id'] = $addresses[0]['location_id'] ?? 40; // Sangli
    $addresses[0]['type'] = $addresses[0]['type'] ?? 'current';
    $snapshot['addresses'] = $addresses;
}

$result = app(SuchakIntakeApplyService::class)->approveAndApply(
    $intake,
    $user,
    $snapshot,
    '127.0.0.1',
    'suchak-e2e-approve',
);

echo json_encode($result, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT)."\n";

if (! empty($result['profile_id'])) {
    $p = App\Models\MatrimonyProfile::find($result['profile_id']);
    echo "profile lifecycle=".($p->lifecycle_state ?? '')." location=".($p->location_id ?? 'null')."\n";
    $rep = App\Models\SuchakProfileRepresentation::where('matrimony_profile_id', $p->id)->latest('id')->first();
    if ($rep) {
        echo "representation #{$rep->id} status={$rep->representation_status} consent={$rep->consent_status}\n";
    }
}
