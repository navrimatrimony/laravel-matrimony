<?php

namespace App\Services\MasterData;

use App\Support\MasterData\ReligionCasteSubcasteSlugger;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

class ReligionCasteSubcasteImportService
{
    public function __construct(
        private ReligionCasteSubcasteSlugger $slugger
    ) {}

    /**
     * Inserts religions, castes, sub_castes from parsed rows (tables must already be empty).
     *
     * @param  array{unique_rows: list<array{0: string, 1: string, 2: string}>, skipped_duplicate_rows: int}  $parsed
     * @return array{religions: int, castes: int, sub_castes: int, skipped_duplicate_rows: int}
     */
    public function insertFromParsed(array $parsed): array
    {
        $now = Carbon::now();
        $religionLabelToId = [];
        $casteLookup = []; // "{$religionId}|{$casteKey}" => caste id

        foreach ($parsed['unique_rows'] as $row) {
            [$relLabel, $casteLabel, $subLabel] = $row;
            if ($relLabel === '') {
                continue;
            }
            if (isset($religionLabelToId[$relLabel])) {
                continue;
            }
            $rKey = $this->slugger->makeKey($relLabel);
            $rid = DB::table('religions')->insertGetId([
                'key' => $rKey,
                'label' => $relLabel,
                'is_active' => true,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
            $religionLabelToId[$relLabel] = $rid;
        }

        foreach ($parsed['unique_rows'] as $row) {
            [$relLabel, $casteLabel, $subLabel] = $row;
            if ($relLabel === '' || $casteLabel === '') {
                continue;
            }
            $rid = $religionLabelToId[$relLabel];
            $cKey = $this->slugger->makeKey($casteLabel);
            $lookup = $rid.'|'.$cKey;
            if (isset($casteLookup[$lookup])) {
                continue;
            }
            $cid = DB::table('castes')->insertGetId([
                'religion_id' => $rid,
                'key' => $cKey,
                'label' => $casteLabel,
                'is_active' => true,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
            $casteLookup[$lookup] = $cid;
        }

        foreach ($parsed['unique_rows'] as $row) {
            [$relLabel, $casteLabel, $subLabel] = $row;
            if ($relLabel === '' || $casteLabel === '' || $subLabel === '') {
                continue;
            }
            $rid = $religionLabelToId[$relLabel];
            $cKey = $this->slugger->makeKey($casteLabel);
            $lookup = $rid.'|'.$cKey;
            $cid = $casteLookup[$lookup] ?? null;
            if ($cid === null) {
                continue;
            }
            $sKey = $this->slugger->makeKey($subLabel);
            DB::table('sub_castes')->insert([
                'caste_id' => $cid,
                'key' => $sKey,
                'label' => $subLabel,
                'is_active' => true,
                'status' => 'approved',
                'created_by_user_id' => null,
                'approved_by_admin_id' => null,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }

        return [
            'religions' => (int) DB::table('religions')->count(),
            'castes' => (int) DB::table('castes')->count(),
            'sub_castes' => (int) DB::table('sub_castes')->count(),
            'skipped_duplicate_rows' => $parsed['skipped_duplicate_rows'],
        ];
    }

    /**
     * @return array{unique_rows: list<array{0: string, 1: string, 2: string}>, skipped_duplicate_rows: int}
     */
    public function parseAndDedupeTsv(string $absolutePath): array
    {
        $content = file_get_contents($absolutePath);
        if ($content === false) {
            throw new \RuntimeException('Failed to read TSV: '.$absolutePath);
        }
        $content = preg_replace('/^\xEF\xBB\xBF/', '', $content) ?? $content;
        $lines = preg_split('/\R/u', $content) ?: [];

        $headerSeen = false;
        $skippedDuplicateRows = 0;
        $seenTuple = [];
        $uniqueRows = [];

        foreach ($lines as $line) {
            $line = trim($line);
            if ($line === '') {
                continue;
            }
            $parts = str_getcsv($line, "\t", '"', '\\');
            if (! $headerSeen) {
                $headerSeen = true;
                $h0 = isset($parts[0]) ? strtolower(trim((string) $parts[0])) : '';
                $h1 = isset($parts[1]) ? strtolower(trim((string) $parts[1])) : '';
                $h2 = isset($parts[2]) ? strtolower(trim((string) $parts[2])) : '';
                if ($h0 !== 'religion' || $h1 !== 'caste' || $h2 !== 'subcaste') {
                    throw new \RuntimeException('TSV header must be: religion<TAB>caste<TAB>subcaste');
                }

                continue;
            }

            $rel = isset($parts[0]) ? $this->slugger->normalizeLabel((string) $parts[0]) : '';
            $caste = isset($parts[1]) ? $this->slugger->normalizeLabel((string) $parts[1]) : '';
            $sub = isset($parts[2]) ? $this->slugger->normalizeLabel((string) $parts[2]) : '';

            if ($rel === '' && $caste === '' && $sub === '') {
                continue;
            }

            if ($caste === '' && $sub !== '') {
                continue;
            }

            $tupleKey = $rel."\n".$caste."\n".$sub;
            if (isset($seenTuple[$tupleKey])) {
                $skippedDuplicateRows++;

                continue;
            }
            $seenTuple[$tupleKey] = true;
            $uniqueRows[] = [$rel, $caste, $sub];
        }

        return [
            'unique_rows' => $uniqueRows,
            'skipped_duplicate_rows' => $skippedDuplicateRows,
        ];
    }

    public function clearForeignReferences(): void
    {
        DB::table('matrimony_profiles')->update([
            'religion_id' => null,
            'caste_id' => null,
            'sub_caste_id' => null,
        ]);

        DB::table('profile_preferred_castes')->delete();
        DB::table('profile_preferred_religions')->delete();
    }

    public function deleteMasterRows(): void
    {
        DB::table('sub_castes')->delete();
        DB::table('castes')->delete();
        DB::table('religions')->delete();
    }

    /**
     * Run after master rows are deleted and before re-import. MySQL/MariaDB ALTER TABLE cannot run
     * inside the same transaction as the inserts that follow.
     */
    public function resetMasterTableAutoIncrements(): void
    {
        $driver = DB::getDriverName();
        if ($driver === 'mysql' || $driver === 'mariadb') {
            DB::statement('ALTER TABLE religions AUTO_INCREMENT = 1');
            DB::statement('ALTER TABLE castes AUTO_INCREMENT = 1');
            DB::statement('ALTER TABLE sub_castes AUTO_INCREMENT = 1');
        } elseif ($driver === 'sqlite') {
            DB::statement("DELETE FROM sqlite_sequence WHERE name IN ('religions', 'castes', 'sub_castes')");
        }
    }
}
