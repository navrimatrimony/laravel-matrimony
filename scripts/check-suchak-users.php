<?php
require __DIR__.'/../vendor/autoload.php';
$app = require __DIR__.'/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

$ids = ['2222222222','2222222223','2222222224'];
foreach ($ids as $id) {
    $u = App\Models\User::query()->where('mobile', $id)->first();
    echo "mobile={$id} user_id=".($u?->id ?? 'NONE')." name=".($u?->name ?? '')."\n";
    if ($u) {
        $acc = App\Models\SuchakAccount::where('user_id', $u->id)->first();
        echo "  suchak_id=".($acc?->id ?? 'NONE')." verification=".($acc?->verification_status ?? '')." public=".($acc?->public_status ?? '')."\n";
        echo "  password_check=".(\Illuminate\Support\Facades\Hash::check('22222222', (string)$u->password) ? 'yes' : 'no')."\n";
    }
}

// list any suchak accounts with mobile starting 222222
$users = App\Models\User::query()->where('mobile', 'like', '222222%')->orderBy('mobile')->get(['id','mobile','name']);
echo "\nAll 222222* users:\n";
foreach ($users as $u) {
    $acc = App\Models\SuchakAccount::where('user_id', $u->id)->first();
    echo "{$u->mobile} uid={$u->id} suchak=".($acc?->id ?? '-')." ver=".($acc?->verification_status ?? '-')."\n";
}
