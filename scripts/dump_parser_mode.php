<?php

declare(strict_types=1);

use App\Models\AdminSetting;
use App\Services\Parsing\ProviderResolver;
use Illuminate\Contracts\Console\Kernel;

require __DIR__.'/../vendor/autoload.php';
$app = require __DIR__.'/../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

echo 'intake_active_parser='.AdminSetting::getValue('intake_active_parser', 'rules_only')."\n";
echo 'parseJobUsesAiVision='.(app(ProviderResolver::class)->parseJobUsesAiVisionExtraction() ? 'yes' : 'no')."\n";
