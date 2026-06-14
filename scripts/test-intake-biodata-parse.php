<?php

require __DIR__.'/../vendor/autoload.php';
$app = require __DIR__.'/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

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
उंची :- 5' 4"
वर्ण :- गोरा
रक्त गट :- B+
आहार :- शाकाहारी
पित्याचे नाव :- श्री. रामराव परीक्षण शिंदे
आईचे नाव :- सौ. सीता परीक्षण शिंदे
घरचा पत्ता :- Flat 12, Green Valley, सांगली
मोबाईल :- 9811122233
TXT;

$draft = app(\App\Services\Parsing\IntakeNormalizedBiodataDraftBuilder::class)->build($text);
echo json_encode($draft['normalized']['core'] ?? [], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT)."\n";
