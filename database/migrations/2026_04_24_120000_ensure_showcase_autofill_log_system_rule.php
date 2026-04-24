<?php

use App\Models\SystemRule;
use App\Services\RuleEngineService;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    public function up(): void
    {
        SystemRule::query()->firstOrCreate(
            ['key' => RuleEngineService::KEY_SHOWCASE_AUTOFILL_LOG_MIN_CORE],
            [
                'value' => '80',
                'meta' => null,
            ]
        );
    }

    public function down(): void
    {
        // Intentionally left blank: do not remove seeded configuration rows on rollback.
    }
};
