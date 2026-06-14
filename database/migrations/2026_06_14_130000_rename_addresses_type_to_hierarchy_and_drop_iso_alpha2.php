<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
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

        $this->assertNoInvalidValues('type', self::HIERARCHIES);
        $this->assertNoInvalidValues('hierarchy', self::HIERARCHIES);
        $this->assertNoInvalidValues('tag', self::TAGS, nullable: true);

        if (DB::getDriverName() !== 'mysql') {
            $this->applyPortableSchemaFixes();

            return;
        }

        $this->prepareCoordinates();
        $this->renameTypeToHierarchy();
        $this->dropSingleColumnSlugUniqueIndexes();

        DB::statement("UPDATE addresses SET slug = CONCAT('address-', id) WHERE slug IS NULL OR slug = ''");

        $this->dropColumnIfExists('iso_alpha2');
        $this->dropColumnIfExists('state_code');
        $this->dropColumnIfExists('district_code');
        $this->dropColumnIfExists('latitude');
        $this->dropColumnIfExists('longitude');
        $this->dropColumnIfExists('population');
        $this->dropColumnIfExists('category');

        $this->reorderAndHardenMysqlColumns();
        $this->ensureMysqlIndex('addresses_slug_index', 'INDEX', ['slug']);
        $this->ensureMysqlIndex('addresses_hierarchy_index', 'INDEX', ['hierarchy']);
        $this->ensureMysqlIndex('addresses_tag_index', 'INDEX', ['tag']);
        $this->ensureMysqlIndex('addresses_parent_hierarchy_slug_unique', 'UNIQUE', ['parent_id', 'hierarchy', 'slug']);
    }

    public function down(): void
    {
        if (! Schema::hasTable('addresses')) {
            return;
        }

        // Intentionally not restoring the legacy address type or ISO columns.
    }

    private function applyPortableSchemaFixes(): void
    {
        Schema::table('addresses', function (Blueprint $table): void {
            if (Schema::hasColumn('addresses', 'type') && ! Schema::hasColumn('addresses', 'hierarchy')) {
                $table->renameColumn('type', 'hierarchy');
            }
            if (Schema::hasColumn('addresses', 'iso_alpha2')) {
                $table->dropColumn('iso_alpha2');
            }
            foreach (['state_code', 'district_code', 'latitude', 'longitude', 'population', 'category'] as $column) {
                if (Schema::hasColumn('addresses', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }

    private function prepareCoordinates(): void
    {
        if ($this->mysqlColumnExists('latitude') && ! $this->mysqlColumnExists('lat')) {
            DB::statement('ALTER TABLE addresses CHANGE `latitude` `lat` DECIMAL(10,7) NULL');
        } elseif ($this->mysqlColumnExists('latitude') && $this->mysqlColumnExists('lat')) {
            DB::statement('UPDATE addresses SET lat = latitude WHERE lat IS NULL AND latitude IS NOT NULL');
        }

        if ($this->mysqlColumnExists('longitude') && ! $this->mysqlColumnExists('lng')) {
            DB::statement('ALTER TABLE addresses CHANGE `longitude` `lng` DECIMAL(10,7) NULL');
        } elseif ($this->mysqlColumnExists('longitude') && $this->mysqlColumnExists('lng')) {
            DB::statement('UPDATE addresses SET lng = longitude WHERE lng IS NULL AND longitude IS NOT NULL');
        }
    }

    private function renameTypeToHierarchy(): void
    {
        if ($this->mysqlColumnExists('type') && ! $this->mysqlColumnExists('hierarchy')) {
            DB::statement("ALTER TABLE addresses CHANGE `type` `hierarchy` ENUM('country','state','district','taluka','village') NOT NULL AFTER `name_en`");

            return;
        }

        if ($this->mysqlColumnExists('type') && $this->mysqlColumnExists('hierarchy')) {
            DB::statement('UPDATE addresses SET hierarchy = type WHERE hierarchy IS NULL OR hierarchy = ""');
            $this->dropColumnIfExists('type');
        }

        if (! $this->mysqlColumnExists('hierarchy')) {
            DB::statement("ALTER TABLE addresses ADD `hierarchy` ENUM('country','state','district','taluka','village') NOT NULL AFTER `name_en`");
        }
    }

    private function reorderAndHardenMysqlColumns(): void
    {
        if (! $this->mysqlColumnExists('name_en')) {
            DB::statement('ALTER TABLE addresses ADD `name_en` VARCHAR(255) NULL AFTER `name_mr`');
        }
        if (! $this->mysqlColumnExists('name_mr')) {
            DB::statement('ALTER TABLE addresses ADD `name_mr` VARCHAR(255) NULL AFTER `slug`');
        }
        if (! $this->mysqlColumnExists('lat')) {
            DB::statement('ALTER TABLE addresses ADD `lat` DECIMAL(10,7) NULL AFTER `pincode`');
        }
        if (! $this->mysqlColumnExists('lng')) {
            DB::statement('ALTER TABLE addresses ADD `lng` DECIMAL(10,7) NULL AFTER `lat`');
        }
        if (! $this->mysqlColumnExists('tag')) {
            DB::statement("ALTER TABLE addresses ADD `tag` ENUM('city','suburban','rural') NULL DEFAULT NULL AFTER `is_active`");
        }
        if (! $this->mysqlColumnExists('lgd_code')) {
            DB::statement('ALTER TABLE addresses ADD `lgd_code` VARCHAR(32) NULL AFTER `tag`');
        }

        $desiredOrder = [
            'id',
            'parent_id',
            'name',
            'slug',
            'name_mr',
            'name_en',
            'hierarchy',
            'level',
            'pincode',
            'lat',
            'lng',
            'is_active',
            'tag',
            'lgd_code',
            'created_at',
            'updated_at',
        ];

        if ($this->mysqlColumnNames() !== $desiredOrder) {
            DB::statement('ALTER TABLE addresses MODIFY `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT FIRST');
            DB::statement('ALTER TABLE addresses MODIFY `parent_id` BIGINT UNSIGNED NULL AFTER `id`');
            DB::statement('ALTER TABLE addresses MODIFY `name` VARCHAR(255) NOT NULL AFTER `parent_id`');
            DB::statement('ALTER TABLE addresses MODIFY `slug` VARCHAR(255) NOT NULL AFTER `name`');
            DB::statement('ALTER TABLE addresses MODIFY `name_mr` VARCHAR(255) NULL AFTER `slug`');
            DB::statement('ALTER TABLE addresses MODIFY `name_en` VARCHAR(255) NULL AFTER `name_mr`');
            DB::statement("ALTER TABLE addresses MODIFY `hierarchy` ENUM('country','state','district','taluka','village') NOT NULL AFTER `name_en`");
            DB::statement('ALTER TABLE addresses MODIFY `level` TINYINT UNSIGNED NOT NULL DEFAULT 0 AFTER `hierarchy`');
            DB::statement('ALTER TABLE addresses MODIFY `pincode` VARCHAR(16) NULL AFTER `level`');
            DB::statement('ALTER TABLE addresses MODIFY `lat` DECIMAL(10,7) NULL AFTER `pincode`');
            DB::statement('ALTER TABLE addresses MODIFY `lng` DECIMAL(10,7) NULL AFTER `lat`');
            DB::statement('ALTER TABLE addresses MODIFY `is_active` TINYINT(1) NOT NULL DEFAULT 1 AFTER `lng`');
            DB::statement("ALTER TABLE addresses MODIFY `tag` ENUM('city','suburban','rural') NULL DEFAULT NULL AFTER `is_active`");
            DB::statement('ALTER TABLE addresses MODIFY `lgd_code` VARCHAR(32) NULL AFTER `tag`');
            DB::statement('ALTER TABLE addresses MODIFY `created_at` TIMESTAMP NULL DEFAULT NULL AFTER `lgd_code`');
            DB::statement('ALTER TABLE addresses MODIFY `updated_at` TIMESTAMP NULL DEFAULT NULL AFTER `created_at`');

            return;
        }

        $hierarchy = $this->mysqlColumnInfo('hierarchy');
        if ($hierarchy !== null && strtolower((string) $hierarchy->COLUMN_TYPE) !== "enum('country','state','district','taluka','village')") {
            DB::statement("ALTER TABLE addresses MODIFY `hierarchy` ENUM('country','state','district','taluka','village') NOT NULL AFTER `name_en`");
        }

        $tag = $this->mysqlColumnInfo('tag');
        if ($tag !== null && (
            strtolower((string) $tag->COLUMN_TYPE) !== "enum('city','suburban','rural')"
            || strtoupper((string) $tag->IS_NULLABLE) !== 'YES'
            || $tag->COLUMN_DEFAULT !== null
        )) {
            DB::statement("ALTER TABLE addresses MODIFY `tag` ENUM('city','suburban','rural') NULL DEFAULT NULL AFTER `is_active`");
        }
    }

    private function dropColumnIfExists(string $column): void
    {
        if ($this->mysqlColumnExists($column)) {
            DB::statement('ALTER TABLE addresses DROP COLUMN `'.str_replace('`', '``', $column).'`');
        }
    }

    private function dropSingleColumnSlugUniqueIndexes(): void
    {
        foreach ($this->mysqlIndexes() as $indexName => $index) {
            if ($index['unique'] === true && $index['columns'] === ['slug']) {
                DB::statement('ALTER TABLE addresses DROP INDEX `'.str_replace('`', '``', (string) $indexName).'`');
            }
        }
    }

    /**
     * @param  list<string>  $columns
     */
    private function ensureMysqlIndex(string $name, string $kind, array $columns): void
    {
        if (isset($this->mysqlIndexes()[$name])) {
            return;
        }

        $columnSql = implode(', ', array_map(static fn (string $column): string => '`'.str_replace('`', '``', $column).'`', $columns));
        DB::statement("ALTER TABLE addresses ADD {$kind} `{$name}` ({$columnSql})");
    }

    /**
     * @return array<string, array{unique: bool, columns: list<string>}>
     */
    private function mysqlIndexes(): array
    {
        $rows = DB::select(
            'SELECT INDEX_NAME, NON_UNIQUE, COLUMN_NAME, SEQ_IN_INDEX
             FROM information_schema.STATISTICS
             WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ?
             ORDER BY INDEX_NAME, SEQ_IN_INDEX',
            [DB::getDatabaseName(), 'addresses']
        );

        $indexes = [];
        foreach ($rows as $row) {
            $name = (string) $row->INDEX_NAME;
            $indexes[$name] ??= [
                'unique' => ((int) $row->NON_UNIQUE) === 0,
                'columns' => [],
            ];
            $indexes[$name]['columns'][] = (string) $row->COLUMN_NAME;
        }

        return $indexes;
    }

    private function mysqlColumnExists(string $column): bool
    {
        return (int) DB::selectOne(
            'SELECT COUNT(*) AS total
             FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ? AND COLUMN_NAME = ?',
            [DB::getDatabaseName(), 'addresses', $column]
        )->total > 0;
    }

    /**
     * @return list<string>
     */
    private function mysqlColumnNames(): array
    {
        return array_map(
            static fn ($row): string => (string) $row->COLUMN_NAME,
            DB::select(
                'SELECT COLUMN_NAME
                 FROM information_schema.COLUMNS
                 WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ?
                 ORDER BY ORDINAL_POSITION',
                [DB::getDatabaseName(), 'addresses']
            )
        );
    }

    private function mysqlColumnInfo(string $column): ?object
    {
        return DB::selectOne(
            'SELECT COLUMN_TYPE, IS_NULLABLE, COLUMN_DEFAULT
             FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ? AND COLUMN_NAME = ?
             LIMIT 1',
            [DB::getDatabaseName(), 'addresses', $column]
        );
    }

    /**
     * @param  list<string>  $allowed
     */
    private function assertNoInvalidValues(string $column, array $allowed, bool $nullable = false): void
    {
        if (! Schema::hasColumn('addresses', $column)) {
            return;
        }

        $invalid = DB::table('addresses')
            ->whereNotNull($column)
            ->where($column, '!=', '')
            ->whereNotIn($column, $allowed)
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

        $message = $nullable
            ? 'Cannot harden nullable addresses.'.$column.' enum while invalid non-null values exist: '
            : 'Cannot harden addresses.'.$column.' enum while invalid values exist: ';

        throw new RuntimeException($message.implode(', ', $pairs));
    }
};
