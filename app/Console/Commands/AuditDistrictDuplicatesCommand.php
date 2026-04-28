<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Read-only check before applying unique indexes on districts: reports duplicate (state_id, name) or (state_id, slug).
 */
class AuditDistrictDuplicatesCommand extends Command
{
    protected $signature = 'location:audit-district-duplicates';

    protected $description = 'List duplicate district rows by (state_id,name) or (state_id,slug); exit 1 if any found';

    public function handle(): int
    {
        if (! Schema::hasTable('districts')) {
            $this->warn('Table districts does not exist.');

            return self::SUCCESS;
        }

        $hasSlug = Schema::hasColumn('districts', 'slug');

        $dupNames = DB::table('districts')
            ->select('state_id', 'name', DB::raw('COUNT(*) as c'))
            ->groupBy('state_id', 'name')
            ->having('c', '>', 1)
            ->get();

        if ($dupNames->isNotEmpty()) {
            $this->error('Duplicate (state_id, name) rows:');
            foreach ($dupNames as $row) {
                $this->line("  state_id={$row->state_id} name=".json_encode((string) $row->name)." count={$row->c}");
            }
        }

        $dupSlugs = collect();
        if ($hasSlug) {
            $dupSlugs = DB::table('districts')
                ->select('state_id', 'slug', DB::raw('COUNT(*) as c'))
                ->groupBy('state_id', 'slug')
                ->having('c', '>', 1)
                ->get();
            if ($dupSlugs->isNotEmpty()) {
                $this->error('Duplicate (state_id, slug) rows:');
                foreach ($dupSlugs as $row) {
                    $this->line("  state_id={$row->state_id} slug=".json_encode((string) $row->slug)." count={$row->c}");
                }
            }
        }

        if ($dupNames->isEmpty() && $dupSlugs->isEmpty()) {
            $this->info('No duplicate districts found (per state_id + name'.($hasSlug ? ' / state_id + slug' : '').').');

            return self::SUCCESS;
        }

        $this->newLine();
        $this->comment('Resolve duplicates (merge FKs / delete extras) before migrate, or unique migration will fail.');

        return self::FAILURE;
    }
}
