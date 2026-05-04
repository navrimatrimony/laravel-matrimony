<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Read-only: duplicate districts under {@code addresses} (type=district) by (parent_id,name) / slug.
 */
class AuditDistrictDuplicatesCommand extends Command
{
    protected $signature = 'location:audit-district-duplicates';

    protected $description = 'List duplicate district rows in addresses (SSOT); exit 1 if any found';

    public function handle(): int
    {
        $geo = 'addresses';
        if (! Schema::hasTable($geo)) {
            $this->warn('Table '.$geo.' does not exist.');

            return self::SUCCESS;
        }

        $dupNames = DB::table($geo)
            ->where('type', 'district')
            ->select('parent_id', 'name', DB::raw('COUNT(*) as c'))
            ->groupBy('parent_id', 'name')
            ->having('c', '>', 1)
            ->get();

        if ($dupNames->isNotEmpty()) {
            $this->error('Duplicate (parent_id, name) rows (districts):');
            foreach ($dupNames as $row) {
                $this->line("  parent_id={$row->parent_id} name=".json_encode((string) $row->name)." count={$row->c}");
            }
        }

        $dupSlugs = collect();
        if (Schema::hasColumn($geo, 'slug')) {
            $dupSlugs = DB::table($geo)
                ->where('type', 'district')
                ->select('parent_id', 'slug', DB::raw('COUNT(*) as c'))
                ->groupBy('parent_id', 'slug')
                ->having('c', '>', 1)
                ->get();
            if ($dupSlugs->isNotEmpty()) {
                $this->error('Duplicate (parent_id, slug) rows (districts):');
                foreach ($dupSlugs as $row) {
                    $this->line("  parent_id={$row->parent_id} slug=".json_encode((string) $row->slug)." count={$row->c}");
                }
            }
        }

        if ($dupNames->isEmpty() && $dupSlugs->isEmpty()) {
            $this->info('No duplicate districts found in addresses (per parent_id + name'.(Schema::hasColumn($geo, 'slug') ? ' / parent_id + slug' : '').').');

            return self::SUCCESS;
        }

        $this->newLine();
        $this->comment('Resolve duplicates (merge FKs / delete extras) before adding unique indexes.');

        return self::FAILURE;
    }
}
