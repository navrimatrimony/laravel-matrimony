<?php

namespace App\Console\Commands;

use App\Models\BiodataIntake;
use Illuminate\Console\Command;

class IntakeAuditCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'intake:audit';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Quick audit of parsed_json coverage for biodata intakes (core, physical, siblings, relatives).';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $this->info('Running intake audit (parsed_json coverage)...');

        $total = BiodataIntake::whereNotNull('parsed_json')->count();
        if ($total === 0) {
            $this->warn('No intakes with parsed_json found.');
            return self::SUCCESS;
        }

        $counters = [
            'full_name' => 0,
            'date_of_birth' => 0,
            'gender' => 0,
            'religion' => 0,
            'caste' => 0,
            'sub_caste' => 0,
            'primary_contact_number' => 0,
            'height_cm' => 0,
            'complexion' => 0,
            'siblings_any' => 0,
            'siblings_with_spouse' => 0,
            'paternal_any' => 0,
            'maternal_any' => 0,
        ];

        BiodataIntake::whereNotNull('parsed_json')
            ->orderBy('id')
            ->chunk(200, function ($chunk) use (&$counters) {
                foreach ($chunk as $intake) {
                    $data = $intake->parsed_json;
                    if (! is_array($data)) {
                        continue;
                    }
                    $core = $data['core'] ?? [];
                    if (! is_array($core)) {
                        $core = [];
                    }

                    $has = fn ($k) => isset($core[$k]) && $core[$k] !== null && $core[$k] !== '';

                    foreach (['full_name', 'date_of_birth', 'gender', 'religion', 'caste', 'sub_caste', 'primary_contact_number', 'height_cm', 'complexion'] as $k) {
                        if ($has($k)) {
                            $counters[$k]++;
                        }
                    }

                    $siblings = $data['siblings'] ?? [];
                    if (is_array($siblings) && count($siblings) > 0) {
                        $counters['siblings_any']++;
                        foreach ($siblings as $s) {
                            $row = is_array($s) ? $s : (array) $s;
                            $spouse = $row['spouse'] ?? null;
                            if (is_object($spouse)) {
                                $spouse = (array) $spouse;
                            }
                            if (is_array($spouse) && ! empty(trim((string) ($spouse['name'] ?? '')))) {
                                $counters['siblings_with_spouse']++;
                                break;
                            }
                        }
                    }

                    $paternal = $data['relatives_parents_family'] ?? [];
                    if (is_array($paternal) && count($paternal) > 0) {
                        $counters['paternal_any']++;
                    }

                    $maternal = $data['relatives_maternal_family'] ?? [];
                    if (is_array($maternal) && count($maternal) > 0) {
                        $counters['maternal_any']++;
                    }
                }
            });

        $this->line('Total intakes with parsed_json: ' . $total);

        $pct = fn (int $n) => sprintf('%.1f%%', $total > 0 ? ($n * 100 / $total) : 0);

        $rows = [
            ['core.full_name', $counters['full_name'], $pct($counters['full_name'])],
            ['core.date_of_birth', $counters['date_of_birth'], $pct($counters['date_of_birth'])],
            ['core.gender', $counters['gender'], $pct($counters['gender'])],
            ['core.religion', $counters['religion'], $pct($counters['religion'])],
            ['core.caste', $counters['caste'], $pct($counters['caste'])],
            ['core.sub_caste', $counters['sub_caste'], $pct($counters['sub_caste'])],
            ['core.primary_contact_number', $counters['primary_contact_number'], $pct($counters['primary_contact_number'])],
            ['core.height_cm', $counters['height_cm'], $pct($counters['height_cm'])],
            ['core.complexion', $counters['complexion'], $pct($counters['complexion'])],
            ['siblings (any)', $counters['siblings_any'], $pct($counters['siblings_any'])],
            ['siblings with spouse', $counters['siblings_with_spouse'], $pct($counters['siblings_with_spouse'])],
            ['paternal_relatives (any)', $counters['paternal_any'], $pct($counters['paternal_any'])],
            ['maternal_relatives (any)', $counters['maternal_any'], $pct($counters['maternal_any'])],
        ];

        $this->table(['Field', 'Count', 'Coverage'], $rows);

        return self::SUCCESS;
    }
}

