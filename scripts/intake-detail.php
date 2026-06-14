<?php
require __DIR__.'/../vendor/autoload.php';
$app = require __DIR__.'/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

$id = (int) ($argv[1] ?? 497);
$i = App\Models\BiodataIntake::find($id);
if (!$i) { echo "not found\n"; exit(1); }
$core = $i->parsed_json['core'] ?? [];
echo "intake #{$i->id}\n";
echo "parse_status={$i->parse_status}\n";
echo "full_name=".($core['full_name'] ?? 'null')."\n";
echo "gender=".($core['gender'] ?? 'null')." gender_id=".($core['gender_id'] ?? 'null')."\n";
echo "dob=".($core['date_of_birth'] ?? 'null')."\n";
echo "birth_place=".($core['birth_place_text'] ?? 'null')."\n";
echo "religion=".($core['religion'] ?? $core['religion_id'] ?? 'null')."\n";
echo "raw_len=".strlen($i->raw_ocr_text ?? '')."\n";
echo substr($i->raw_ocr_text ?? '', 0, 200)."\n";
