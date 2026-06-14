<?php

require __DIR__.'/../vendor/autoload.php';
$app = require __DIR__.'/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Models\City;
use App\Models\MatrimonyProfile;
use App\Models\Religion;
use App\Models\SuchakProfileRepresentation;
use App\Models\User;
use Illuminate\Support\Facades\Hash;

$profileId = (int) ($argv[1] ?? 248);
$p = MatrimonyProfile::with(['user', 'religion', 'caste'])->find($profileId);
if (! $p) {
    echo "profile not found\n";
    exit(1);
}

echo "=== Profile #{$p->id} ===\n";
echo "full_name={$p->full_name}\n";
echo "lifecycle_state={$p->lifecycle_state}\n";
echo "profile_photo=".($p->profile_photo ?: 'null')."\n";
echo "location_id=".($p->location_id ?: 'null')."\n";
echo "birth_city_id=".($p->birth_city_id ?: 'null')."\n";
echo "religion_id=".($p->religion_id ?: 'null')." (".($p->religion?->label ?? '').")\n";
echo "caste_id=".($p->caste_id ?: 'null')." (".($p->caste?->label ?? '').")\n";

$rep = SuchakProfileRepresentation::where('matrimony_profile_id', $p->id)->first();
if ($rep) {
    echo "representation_id={$rep->id} status={$rep->representation_status} consent={$rep->consent_status}\n";
}

$hindu = Religion::query()->where('is_active', true)->where(function ($q) {
    $q->where('label', 'like', '%Hindu%')->orWhere('label_en', 'like', '%Hindu%');
})->first(['id', 'label', 'label_en']);
echo 'hindu_religion='.json_encode($hindu, JSON_UNESCAPED_UNICODE)."\n";

$sangli = City::query()->where('name', 'like', '%Sangli%')->first(['id', 'name']);
echo 'sangli_city='.json_encode($sangli)."\n";

echo "\n=== Suchak login users ===\n";
foreach (['2222222222', '2222222223', '2222222224'] as $mobile) {
    $u = User::where('mobile', $mobile)->first();
    if (! $u) {
        echo "{$mobile}: NOT FOUND\n";
        continue;
    }
    $ok = Hash::check('22222222', $u->password);
    $suchak = $u->suchakAccount;
    echo "{$mobile}: uid={$u->id} pwd_ok=".($ok ? 'yes' : 'no');
    if ($suchak) {
        echo " suchak_verified={$suchak->verification_status} public={$suchak->public_status}";
    }
    echo "\n";
}
