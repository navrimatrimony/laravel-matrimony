<?php

require __DIR__.'/../vendor/autoload.php';
$app = require __DIR__.'/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Models\User;
use App\Modules\Suchak\Services\SuchakSourceLinkService;

$mobile = $argv[1] ?? '2222222223';
$user = User::where('mobile', $mobile)->firstOrFail();
$account = $user->suchakAccount;
if (! $account) {
    echo "No suchak account\n";
    exit(1);
}

$text = <<<'TXT'
बायोडाटा
मुलीचे नाव :- कु. परीक्षण इंटेक वधू शिंदे
जन्म दिनांक :- 15/06/1998
जन्म स्थळ :- सांगली
धर्म :- हिंदू
जात :- मराठा
वैवाहिक स्थिती :- अविवाहित
शिक्षण :- B.E. Computer
नोकरी :- Software Engineer, Pune
उंची :- 163 cm
वर्ण :- गोरा
रक्त गट :- B+
आहार :- शाकाहारी
पित्याचे नाव :- श्री. रामराव परीक्षण शिंदे
आईचे नाव :- सौ. सीता परीक्षण शिंदे
घरचा पत्ता :- Flat 12, Green Valley, सांगली
मोबाईल :- 9811122233
TXT;

/** @var SuchakSourceLinkService $service */
$service = app(SuchakSourceLinkService::class);
$link = $service->createFromIntakeUpload($account, $user, null, $text, '127.0.0.1', 'suchak-e2e-script');
$intake = $link->biodataIntake()->first();
$intake?->refresh();

echo "link_id={$link->id}\n";
echo "intake_id={$intake?->id}\n";
echo "parse_status={$intake?->parse_status}\n";
$core = is_array($intake?->parsed_json) ? ($intake->parsed_json['core'] ?? []) : [];
echo 'full_name='.($core['full_name'] ?? 'null')."\n";
echo 'gender='.($core['gender'] ?? 'null')."\n";
echo 'preview_url='.url('/intake/preview/'.$intake?->id)."\n";
