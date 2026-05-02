<?php

namespace App\Console\Commands;

use App\Models\Village;
use App\Services\Location\SarvamMarathiVillageNameService;
use Database\Seeders\Support\LocationMarathiLabels;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Schema;

class FillVillagesMarathiSarvamCommand extends Command
{
    protected $signature = 'villages:fill-marathi-sarvam
                            {--only-addresses : Only villages referenced by profile_addresses.village_id (default)}
                            {--all : Fill every village row matching filters (not only address-linked)}
                            {--force : Overwrite existing non-empty name_mr}
                            {--dry-run : Show planned updates without saving}
                            {--batch-size=12 : Villages per Sarvam request}
                            {--sleep=400 : Milliseconds to pause between batches}
                            {--mirror-cities : After saving, mirror villages.name_mr onto matching cities.name_mr}';

    protected $description = 'Fill villages.name_mr using Sarvam (sarvam-m) for Maharashtra address-linked villages';

    public function handle(SarvamMarathiVillageNameService $sarvam): int
    {
        if (! Schema::hasTable('villages')) {
            $this->error('Table villages does not exist.');

            return self::FAILURE;
        }
        if (! Schema::hasColumn('villages', 'name_mr')) {
            $this->error('Column villages.name_mr is missing. Run migrations (ensure_villages_marathi_columns).');

            return self::FAILURE;
        }

        $onlyAddresses = ! $this->option('all');
        $force = (bool) $this->option('force');
        $dry = (bool) $this->option('dry-run');
        $batchSize = max(1, min(40, (int) $this->option('batch-size')));
        $sleepMs = max(0, (int) $this->option('sleep'));

        $q = Village::query()
            ->select(['villages.id', 'villages.name', 'villages.name_en', 'villages.name_mr'])
            ->with(['taluka:id,name,name_mr,district_id', 'taluka.district:id,name,name_mr']);

        if ($onlyAddresses && Schema::hasTable('profile_addresses')) {
            $q->whereIn('villages.id', function ($sub): void {
                $sub->select('village_id')
                    ->from('profile_addresses')
                    ->whereNotNull('village_id')
                    ->distinct();
            });
        }

        if (! $force) {
            $q->where(function ($w): void {
                $w->whereNull('villages.name_mr')->orWhereRaw("TRIM(villages.name_mr) = ''");
            });
        }

        $total = (clone $q)->count();
        if ($total === 0) {
            $this->info('No villages to process.');

            return self::SUCCESS;
        }

        $this->info('Villages to process: '.$total.($onlyAddresses ? ' (linked from profile_addresses)' : ' (all matching rows)'));

        $updated = 0;
        $q->orderBy('villages.id')->chunkById($batchSize, function ($chunk) use ($sarvam, $dry, $sleepMs, &$updated): void {
            $places = [];
            foreach ($chunk as $v) {
                $label = trim((string) (($v->name_en ?: null) ?: $v->name));
                if ($label === '') {
                    continue;
                }
                $taluka = trim((string) ($v->taluka?->name_mr ?: $v->taluka?->name ?? ''));
                $district = trim((string) ($v->taluka?->district?->name_mr ?: $v->taluka?->district?->name ?? ''));
                $places[] = [
                    'id' => (int) $v->id,
                    'name' => $label,
                    'taluka' => $taluka !== '' ? $taluka : null,
                    'district' => $district !== '' ? $district : null,
                ];
            }
            if ($places === []) {
                return;
            }

            $map = $sarvam->translatePlaces($places);
            if ($map === []) {
                $this->warn('Batch ids '.implode(',', array_column($places, 'id')).': Sarvam returned no mappings (check API key / quota).');

                if ($sleepMs > 0) {
                    usleep($sleepMs * 1000);
                }

                return;
            }

            foreach ($chunk as $v) {
                $id = (int) $v->id;
                if (! isset($map[$id])) {
                    continue;
                }
                $mr = $map[$id];
                if ($dry) {
                    $this->line("[dry-run] village {$id}: ".$mr);
                    $updated++;

                    continue;
                }
                Village::query()->whereKey($id)->update(['name_mr' => $mr]);
                $updated++;
            }

            if ($sleepMs > 0) {
                usleep($sleepMs * 1000);
            }
        });

        $this->info('Done. Rows '.($dry ? 'planned' : 'updated').': '.$updated);

        if (! $dry && $this->option('mirror-cities') && $updated > 0 && Schema::hasTable('cities') && Schema::hasColumn('cities', 'name_mr')) {
            LocationMarathiLabels::syncIndianCityNameMrFromVillageMirror();
            $this->info('Mirrored villages.name_mr onto matching cities.name_mr.');
        }

        return self::SUCCESS;
    }
}
