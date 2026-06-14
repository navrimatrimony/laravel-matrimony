<?php
require __DIR__.'/../vendor/autoload.php';
$app = require __DIR__.'/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

$p = App\Models\MatrimonyProfile::with(['user'])->find(248);
if (!$p) { echo "profile not found\n"; exit(1); }
echo "id={$p->id}\n";
echo "full_name={$p->full_name}\n";
echo "lifecycle_state={$p->lifecycle_state}\n";
echo "is_suspended=".($p->is_suspended?'1':'0')."\n";
echo "gender_id={$p->gender_id}\n";
echo "dob={$p->date_of_birth}\n";
echo "birth_city_id={$p->birth_city_id}\n";
echo "religion_id={$p->religion_id}\n";
echo "caste_id={$p->caste_id}\n";
echo "father_name={$p->father_name}\n";
echo "father_extra_info={$p->father_extra_info}\n";
echo "mother_name={$p->mother_name}\n";
echo "complexion_id={$p->complexion_id}\n";
echo "blood_group_id={$p->blood_group_id}\n";
echo "diet_id={$p->diet_id}\n";
echo "mother_tongue_id={$p->mother_tongue_id}\n";
echo "user_mobile=".($p->user?->mobile ?? '')."\n";

$rep = App\Models\SuchakProfileRepresentation::where('matrimony_profile_id', 248)->first();
echo "representation_id=".($rep?->id ?? '')." status=".($rep?->representation_status ?? '')."\n";

$addrs = DB::table('profile_addresses')->where('profile_id', 248)->get(['address_scope','address_line','location_id']);
echo "addresses=".$addrs->toJson(JSON_UNESCAPED_UNICODE)."\n";
