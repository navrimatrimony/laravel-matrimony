<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** @var list<string> */
    private const HIERARCHIES = ['country', 'state', 'district', 'taluka', 'village'];

    /** @var list<string> */
    private const TAGS = ['city', 'suburban', 'rural'];

    public function up(): void
    {
        if (! Schema::hasTable('addresses')) {
            return;
        }

        $hierarchyColumn = Schema::hasColumn('addresses', 'hierarchy') ? 'hierarchy' : (Schema::hasColumn('addresses', 'type') ? 'type' : null);
        if ($hierarchyColumn !== null) {
            $this->assertNoInvalidValues($hierarchyColumn, self::HIERARCHIES);
        }

        if (Schema::hasColumn('addresses', 'tag')) {
            $this->assertNoInvalidValues('tag', self::TAGS, nullable: true);
        }

        if (DB::getDriverName() !== 'mysql') {
            return;
        }

        if ($hierarchyColumn !== null) {
            DB::statement("ALTER TABLE addresses MODIFY `{$hierarchyColumn}` ENUM('country','state','district','taluka','village') NOT NULL");
        }

        if (Schema::hasColumn('addresses', 'tag')) {
            DB::statement("ALTER TABLE addresses MODIFY tag ENUM('city','suburban','rural') NULL DEFAULT NULL");
        }
    }

    public function down(): void
    {
        if (! Schema::hasTable('addresses') || DB::getDriverName() !== 'mysql') {
            return;
        }

        $hierarchyColumn = Schema::hasColumn('addresses', 'hierarchy') ? 'hierarchy' : (Schema::hasColumn('addresses', 'type') ? 'type' : null);
        if ($hierarchyColumn !== null) {
            DB::statement("ALTER TABLE addresses MODIFY `{$hierarchyColumn}` ENUM('country','state','district','taluka','village') NOT NULL");
        }

        if (Schema::hasColumn('addresses', 'tag')) {
            DB::statement("ALTER TABLE addresses MODIFY tag ENUM('city','suburban','rural') NULL DEFAULT NULL");
        }
    }

    /**
     * @param  list<string>  $allowed
     */
    private function assertNoInvalidValues(string $column, array $allowed, bool $nullable = false): void
    {
        if (! Schema::hasColumn('addresses', $column)) {
            return;
        }

        $query = DB::table('addresses')
            ->whereNotNull($column)
            ->where($column, '!=', '')
            ->whereNotIn($column, $allowed);

        if ($nullable) {
            $query->whereNotNull($column);
        }

        $invalid = $query
            ->select($column)
            ->selectRaw('COUNT(*) as total')
            ->groupBy($column)
            ->orderBy($column)
            ->pluck('total', $column)
            ->all();

        if ($invalid === []) {
            return;
        }

        $pairs = [];
        foreach ($invalid as $value => $total) {
            $pairs[] = "{$value}={$total}";
        }

        throw new RuntimeException('Cannot harden addresses.'.$column.' enum while invalid values exist: '.implode(', ', $pairs));
    }
};
