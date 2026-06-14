<?php

namespace App\Support\Location;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Lists allowed {@code addresses.hierarchy} and {@code addresses.tag} values from the live schema (MySQL ENUM/SET)
 * when available, otherwise merges known baselines with DISTINCT values in the table (e.g. SQLite tests).
 */
final class AddressSchemaEnumOptions
{
    /** @var list<string> */
    private const FALLBACK_HIERARCHIES = ['country', 'state', 'district', 'taluka', 'village'];

    /** @var list<string> */
    private const FALLBACK_TAGS = ['city', 'suburban', 'rural'];

    /**
     * @return list<string>
     */
    public static function addressHierarchies(): array
    {
        if (! Schema::hasTable('addresses')) {
            return self::FALLBACK_HIERARCHIES;
        }

        $fromSchema = self::mysqlEnumOrSetMembers('addresses', 'hierarchy');
        if ($fromSchema !== []) {
            return $fromSchema;
        }

        return self::mergeDistinctColumn('hierarchy', self::FALLBACK_HIERARCHIES);
    }

    /**
     * @return list<string>
     */
    public static function addressTags(): array
    {
        if (! Schema::hasTable('addresses')) {
            return self::FALLBACK_TAGS;
        }

        $fromSchema = self::mysqlEnumOrSetMembers('addresses', 'tag');
        if ($fromSchema !== []) {
            return $fromSchema;
        }

        return self::mergeDistinctColumn('tag', self::FALLBACK_TAGS);
    }

    /**
     * @return list<string>
     */
    private static function mysqlEnumOrSetMembers(string $table, string $column): array
    {
        $conn = Schema::getConnection();
        if ($conn->getDriverName() !== 'mysql') {
            return [];
        }

        $db = $conn->getDatabaseName();
        $row = $conn->selectOne(
            'SELECT COLUMN_TYPE AS col_type FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ? AND COLUMN_NAME = ? LIMIT 1',
            [$db, $table, $column]
        );
        if (! $row || empty($row->col_type)) {
            return [];
        }

        return self::parseMysqlEnumOrSetType((string) $row->col_type);
    }

    /**
     * @return list<string>
     */
    private static function parseMysqlEnumOrSetType(string $columnType): array
    {
        $columnType = trim(str_replace(["\r", "\n"], '', $columnType));
        if (! preg_match('/^(ENUM|SET)\((.*)\)$/is', $columnType, $m)) {
            return [];
        }
        $body = $m[2];
        if (! preg_match_all("/'((?:[^'\\\\]|\\\\.)*)'/", $body, $mm)) {
            return [];
        }
        $out = [];
        foreach ($mm[1] as $raw) {
            $v = strtolower(stripcslashes($raw));
            if ($v !== '') {
                $out[] = $v;
            }
        }

        return array_values(array_unique($out));
    }

    /**
     * @param  list<string>  $baseline
     * @return list<string>
     */
    private static function mergeDistinctColumn(string $column, array $baseline): array
    {
        if (! Schema::hasColumn('addresses', $column)) {
            return $baseline;
        }

        try {
            $distinct = DB::table('addresses')
                ->whereNotNull($column)
                ->where($column, '!=', '')
                ->distinct()
                ->orderBy($column)
                ->pluck($column)
                ->map(fn ($v) => strtolower(trim((string) $v)))
                ->unique()
                ->values()
                ->all();
        } catch (\Throwable) {
            $distinct = [];
        }

        return array_values(array_unique([...$baseline, ...$distinct]));
    }
}
