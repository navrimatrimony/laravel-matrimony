<?php

namespace App\Console\Commands;

use App\Jobs\ParseIntakeJob;
use App\Models\BiodataIntake;
use App\Services\IntakeApprovalService;
use App\Services\MutationService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Phase-5 Full E2E: Create intake → parse → approve → mutation → verify.
 * Run: php artisan phase5:e2e-test
 */
class Phase5E2ETestCommand extends Command
{
    protected $signature = 'phase5:e2e-test';

    protected $description = 'Phase-5 E2E: upload → parse → approve → mutation → verify';

    private array $results = [];
    private ?BiodataIntake $intake = null;

    public function handle(): int
    {
        $raw = "
नाव: अमोल राजेश पाटील
जन्मतारीख: 10/05/1995
उंची: 5 फूट 8 इंच
शिक्षण: B.Com
वडिलांचे नाव: राजेश पाटील
आईचे नाव: सुनीता पाटील
भाऊ: 1
बहिण: 1
पत्ता: मु.पो. कराड, जि. सातारा
जात: मराठा
पगार: 500000 वार्षिक
";

        $userId = 1;
        if (\App\Models\User::find($userId) === null) {
            $this->error('User id 1 not found. Create a user first.');
            return self::FAILURE;
        }

        $this->results = [];

        // STEP 1 — Create intake
        try {
            $this->intake = BiodataIntake::create([
                'uploaded_by' => $userId,
                'raw_ocr_text' => trim($raw),
                'intake_status' => 'uploaded',
                'parse_status' => 'pending',
                'approved_by_user' => false,
                'intake_locked' => false,
            ]);
            $this->pass('STEP 1', 'Intake created', ['id' => $this->intake->id]);
        } catch (\Throwable $e) {
            $this->recordFail('STEP 1', $e->getMessage());
            $this->dumpResults();
            return self::FAILURE;
        }

        // Parse synchronously
        try {
            (new ParseIntakeJob($this->intake->id))->handle();
        } catch (\Throwable $e) {
            $this->recordFail('STEP 1 (parse)', $e->getMessage());
        }

        // STEP 2 — Verify parse
        $this->intake->refresh();
        $parsed = $this->intake->parsed_json;
        $step2Ok = true;
        $msg = [];
        if ($parsed === null || ! is_array($parsed)) {
            $step2Ok = false;
            $msg[] = 'parsed_json null or not array';
        } else {
            $core = $parsed['core'] ?? [];
            $name = $core['full_name'] ?? null;
            if ($name !== 'अमोल राजेश पाटील') {
                $step2Ok = false;
                $msg[] = "full_name expected 'अमोल राजेश पाटील', got " . json_encode($name);
            }
            $heightCm = $core['height_cm'] ?? null;
            if ($heightCm !== null && (float) $heightCm < 170 || (float) $heightCm > 175) {
                $step2Ok = false;
                $msg[] = "height_cm expected ~172, got {$heightCm}";
            }
            $caste = $core['caste'] ?? null;
            if ($caste === null || $caste === '') {
                $step2Ok = false;
                $msg[] = 'caste not extracted';
            }
            if (! isset($parsed['confidence_map']) || ! is_array($parsed['confidence_map'])) {
                $step2Ok = false;
                $msg[] = 'confidence_map missing';
            }
        }
        if ($step2Ok) {
            $this->pass('STEP 2', 'Parse verified', ['full_name' => $core['full_name'] ?? null, 'height_cm' => $core['height_cm'] ?? null, 'caste' => $core['caste'] ?? null]);
        } else {
            $this->recordFail('STEP 2', implode('; ', $msg));
        }

        // STEP 3 — Approve
        try {
            $approvalService = app(IntakeApprovalService::class);
            $approvalService->approve($this->intake->fresh(), $userId, null);
            $this->intake->refresh();
            // approve() runs mutation internally, so after return intake_status is 'applied' and intake_locked true
            if ($this->intake->approved_by_user === true && $this->intake->approval_snapshot_json !== null && $this->intake->intake_status === 'applied' && $this->intake->intake_locked === true) {
                $this->pass('STEP 3', 'Approval done');
            } else {
                $this->recordFail('STEP 3', 'approved_by_user or approval_snapshot or intake_status/applied or intake_locked wrong');
            }
        } catch (\Throwable $e) {
            $this->recordFail('STEP 3', $e->getMessage());
        }

        // STEP 4 — Profile created
        $this->intake->refresh();
        $profileId = $this->intake->matrimony_profile_id;
        $step4Ok = true;
        $msg4 = [];
        if ($profileId === null) {
            $step4Ok = false;
            $msg4[] = 'matrimony_profile_id null';
        } else {
            $profile = \App\Models\MatrimonyProfile::find($profileId);
            if (! $profile) {
                $step4Ok = false;
                $msg4[] = 'profile row not found';
            } else {
                if (($profile->full_name ?? '') !== 'अमोल राजेश पाटील') {
                    $step4Ok = false;
                    $msg4[] = 'full_name mismatch';
                }
                if (($profile->caste ?? '') !== 'मराठा') {
                    $step4Ok = false;
                    $msg4[] = 'caste mismatch';
                }
                if ((int) ($profile->annual_income ?? 0) !== 500000) {
                    $step4Ok = false;
                    $msg4[] = 'annual_income expected 500000, got ' . ($profile->annual_income ?? 'null');
                }
                if (($profile->lifecycle_state ?? '') !== 'active') {
                    $step4Ok = false;
                    $msg4[] = 'lifecycle_state expected active, got ' . ($profile->lifecycle_state ?? 'null');
                }
            }
        }
        if ($this->intake->intake_locked !== true) {
            $step4Ok = false;
            $msg4[] = 'intake_locked not true';
        }
        if ($this->intake->intake_status !== 'applied') {
            $step4Ok = false;
            $msg4[] = 'intake_status expected applied, got ' . ($this->intake->intake_status ?? 'null');
        }
        if ($step4Ok) {
            $this->pass('STEP 4', 'Profile and intake state OK', ['profile_id' => $profileId]);
        } else {
            $this->recordFail('STEP 4', implode('; ', $msg4));
        }

        // STEP 5 — History
        $historyCount = DB::table('profile_change_history')->where('profile_id', $profileId)->count();
        $fields = DB::table('profile_change_history')->where('profile_id', $profileId)->pluck('field_name')->unique()->values()->all();
        $required = ['full_name', 'date_of_birth', 'caste', 'annual_income'];
        $missing = array_diff($required, $fields);
        if ($historyCount > 0 && empty($missing)) {
            $this->pass('STEP 5', 'History entries exist', ['count' => $historyCount, 'fields' => $fields]);
        } else {
            $this->recordFail('STEP 5', 'Missing history for: ' . implode(', ', $missing) . ' (count=' . $historyCount . ')');
        }

        // STEP 6 — Second approval (double-click)
        try {
            $mut = app(MutationService::class);
            $second = $mut->applyApprovedIntake($this->intake->id);
            $already = $second['already_applied'] ?? false;
            $historyCount2 = DB::table('profile_change_history')->where('profile_id', $profileId)->count();
            if ($already && $historyCount2 === $historyCount) {
                $this->pass('STEP 6', 'Second call returned already_applied, no duplicate history');
            } else {
                $this->recordFail('STEP 6', 'already_applied=' . ($already ? 'true' : 'false') . ', history count same=' . ($historyCount2 === $historyCount ? 'yes' : 'no'));
            }
        } catch (\Throwable $e) {
            $this->recordFail('STEP 6', $e->getMessage());
        }

        $this->dumpResults();
        return $this->allPassed() ? self::SUCCESS : self::FAILURE;
    }

    private function pass(string $step, string $msg, array $ctx = []): void
    {
        $this->results[$step] = ['pass' => true, 'msg' => $msg, 'ctx' => $ctx];
        $this->line("<info>[PASS]</info> {$step}: {$msg}");
    }

    private function recordFail(string $step, string $msg): void
    {
        $this->results[$step] = ['pass' => false, 'msg' => $msg];
        $this->line("<error>[FAIL]</error> {$step}: {$msg}");
    }

    private function allPassed(): bool
    {
        foreach ($this->results as $r) {
            if (! ($r['pass'] ?? false)) {
                return false;
            }
        }
        return true;
    }

    private function dumpResults(): void
    {
        $this->newLine();
        $this->info('--- Summary ---');
        $pass = 0;
        $fail = 0;
        foreach ($this->results as $step => $r) {
            if ($r['pass'] ?? false) {
                $pass++;
            } else {
                $fail++;
            }
        }
        $this->line("PASS: {$pass}  FAIL: {$fail}");
        if ($this->intake !== null) {
            $this->line('Intake id: ' . $this->intake->id . ', profile_id: ' . ($this->intake->matrimony_profile_id ?? 'null'));
        }
    }
}
