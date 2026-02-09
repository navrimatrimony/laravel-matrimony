<?php

namespace App\Console\Commands;

use App\Models\MatrimonyProfile;
use App\Services\FieldValueHistoryService;
use App\Services\ProfileCompletenessService;
use Illuminate\Console\Command;

/*
|--------------------------------------------------------------------------
| Day-11 Option-B: Single-profile completeness proof (caste in/out)
|--------------------------------------------------------------------------
|
| Run after migration ensure_caste_is_enabled. Outputs VERIFY_INPUTS,
| BASELINE, AFTER_ADD, AFTER_REMOVE and VERDICT for paste.
|
*/
class Day11CompletenessProof extends Command
{
    protected $signature = 'day11:completeness-proof {--id= : Matrimony profile ID (default: first profile)}';

    protected $description = 'Day-11: Proof that ADD/REMOVE caste changes % (metadata-only fix).';

    public function handle(): int
    {
        $profileId = $this->option('id');
        $profile = $profileId
            ? MatrimonyProfile::findOrFail($profileId)
            : MatrimonyProfile::orderBy('id')->first();

        if (! $profile) {
            $this->error('No profile found.');
            return self::FAILURE;
        }

        // VERIFY_INPUTS (same as migration)
        $mandatory = \App\Models\FieldRegistry::where('field_type', 'CORE')->where('is_mandatory', true)->pluck('field_key')->values();
        $enabled = \App\Services\ProfileFieldConfigurationService::getEnabledFieldKeys();
        $used = array_values(array_intersect(
            \App\Models\FieldRegistry::where('field_type', 'CORE')->where('is_mandatory', true)->pluck('field_key')->toArray(),
            \App\Services\ProfileFieldConfigurationService::getEnabledFieldKeys()
        ));
        $verify = ['mandatory' => $mandatory, 'enabled' => $enabled, 'used' => $used];
        \Log::info('VERIFY_INPUTS', $verify);
        $this->line('[VERIFY_INPUTS] ' . json_encode($verify, JSON_PRETTY_PRINT));

        // BASELINE
        $pctBaseline = ProfileCompletenessService::percentage($profile);
        $casteBaseline = $profile->caste;
        \Log::info('BASELINE', ['pct' => $pctBaseline, 'caste' => $casteBaseline]);
        $this->line('[BASELINE] ' . json_encode(['pct' => $pctBaseline, 'caste' => $casteBaseline]));

        // ADD CASTE
        $oldCaste = $profile->caste === '' ? null : $profile->caste;
        FieldValueHistoryService::record($profile->id, 'caste', 'CORE', $oldCaste, 'TestCaste', FieldValueHistoryService::CHANGED_BY_SYSTEM);
        $profile->caste = 'TestCaste';
        $profile->save();
        $profile->refresh();
        $pctAfterAdd = ProfileCompletenessService::percentage($profile);
        $casteAfterAdd = $profile->caste;
        \Log::info('AFTER_ADD', ['pct' => $pctAfterAdd, 'caste' => $casteAfterAdd]);
        $this->line('[AFTER_ADD] ' . json_encode(['pct' => $pctAfterAdd, 'caste' => $casteAfterAdd]));

        // REMOVE CASTE
        $oldCaste = $profile->caste === '' ? null : $profile->caste;
        FieldValueHistoryService::record($profile->id, 'caste', 'CORE', $oldCaste, null, FieldValueHistoryService::CHANGED_BY_SYSTEM);
        $profile->caste = null;
        $profile->save();
        $profile->refresh();
        $pctAfterRemove = ProfileCompletenessService::percentage($profile);
        $casteAfterRemove = $profile->caste;
        \Log::info('AFTER_REMOVE', ['pct' => $pctAfterRemove, 'caste' => $casteAfterRemove]);
        $this->line('[AFTER_REMOVE] ' . json_encode(['pct' => $pctAfterRemove, 'caste' => $casteAfterRemove]));

        // VERDICT: % must increase when adding and decrease when removing
        $changesCorrectly = $pctAfterAdd > $pctAfterRemove && $pctAfterRemove <= $pctBaseline;
        $verdict = $changesCorrectly ? 'YES' : 'NO';
        $this->line('VERDICT = % CHANGES CORRECTLY (' . $verdict . ')');

        return self::SUCCESS;
    }
}
