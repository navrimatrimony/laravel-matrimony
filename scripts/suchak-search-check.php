<?php

require __DIR__.'/../vendor/autoload.php';
$app = require __DIR__.'/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Models\SuchakAccount;
use App\Models\User;
use App\Modules\Suchak\Services\SuchakCrossSearchService;

$viewerMobile = $argv[1] ?? '2222222222';
$user = User::where('mobile', $viewerMobile)->firstOrFail();
$account = $user->suchakAccount;
if (! $account) {
    echo "No suchak account for {$viewerMobile}\n";
    exit(1);
}

/** @var SuchakCrossSearchService $search */
$search = app(SuchakCrossSearchService::class);

$results = $search->search($account, [
    'gender_id' => 2,
    'age_min' => 25,
    'age_max' => 30,
]);

echo "Viewer: {$viewerMobile} suchak #{$account->id}\n";
echo "Total results: {$results->total()}\n";

foreach ($results->items() as $row) {
    $ref = $row['candidate_reference'] ?? '?';
    $repId = $row['representation']['id'] ?? null;
    $profileId = null;
    if ($repId) {
        $profileId = \App\Models\SuchakProfileRepresentation::find($repId)?->matrimony_profile_id;
    }
    echo "- ref={$ref} rep={$repId} profile=#{$profileId}\n";
}

$target = collect($results->items())->contains(function ($r) {
    $repId = $r['representation']['id'] ?? null;
    if (! $repId) {
        return false;
    }

    return (int) \App\Models\SuchakProfileRepresentation::find($repId)?->matrimony_profile_id === 248;
});
echo $target ? "FOUND profile #248 in masked search\n" : "Profile #248 NOT in search results\n";
