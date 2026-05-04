<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * SSOT for own qualification on {@code matrimony_profiles}: {@code highest_education} text only.
 * Drops parallel FK/text columns; values are merged into {@code highest_education} before drop.
 */
return new class extends Migration
{
    private function tryDropForeign(string $table, string $column): void
    {
        if (! Schema::hasColumn($table, $column)) {
            return;
        }
        try {
            Schema::table($table, function (Blueprint $blueprint) use ($column): void {
                $blueprint->dropForeign([$column]);
            });
        } catch (\Throwable) {
        }
    }

    public function up(): void
    {
        if (! Schema::hasTable('matrimony_profiles')) {
            return;
        }

        $tbl = 'matrimony_profiles';

        // Merge legacy columns into highest_education where missing or empty.
        if (Schema::hasColumn($tbl, 'highest_education')) {
            DB::table($tbl)
                ->where(function ($q): void {
                    $q->whereNull('highest_education')->orWhere('highest_education', '');
                })
                ->orderBy('id')
                ->chunkById(250, function ($rows) use ($tbl): void {
                    foreach ($rows as $row) {
                        $line = '';
                        if (Schema::hasColumn($tbl, 'education_degree_id') && ! empty($row->education_degree_id) && Schema::hasTable('education_degrees')) {
                            $deg = DB::table('education_degrees')->where('id', $row->education_degree_id)->first();
                            if ($deg) {
                                $line = trim((string) ($deg->title ?: $deg->code ?? ''));
                            }
                        }
                        $extra = Schema::hasColumn($tbl, 'education_text') ? trim((string) ($row->education_text ?? '')) : '';
                        if ($extra !== '') {
                            $line = $line !== '' ? $line.', '.$extra : $extra;
                        }
                        if (($line === '' || $line === null) && Schema::hasColumn($tbl, 'highest_education_text')) {
                            $line = trim((string) ($row->highest_education_text ?? ''));
                        }
                        if ($line !== '') {
                            DB::table($tbl)->where('id', $row->id)->update([
                                'highest_education' => mb_substr($line, 0, 255),
                            ]);
                        }
                    }
                });
        }

        foreach (['education_degree_id', 'highest_education_id'] as $col) {
            $this->tryDropForeign($tbl, $col);
        }

        foreach (['education_degree_id', 'highest_education_id'] as $col) {
            if (! Schema::hasColumn($tbl, $col)) {
                continue;
            }
            try {
                Schema::table($tbl, function (Blueprint $table) use ($col): void {
                    $table->dropIndex([$col]);
                });
            } catch (\Throwable) {
            }
        }

        Schema::table($tbl, function (Blueprint $table): void {
            $drops = array_values(array_filter([
                Schema::hasColumn('matrimony_profiles', 'education_degree_id') ? 'education_degree_id' : null,
                Schema::hasColumn('matrimony_profiles', 'education_text') ? 'education_text' : null,
                Schema::hasColumn('matrimony_profiles', 'highest_education_id') ? 'highest_education_id' : null,
                Schema::hasColumn('matrimony_profiles', 'highest_education_text') ? 'highest_education_text' : null,
            ]));
            if ($drops !== []) {
                $table->dropColumn($drops);
            }
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('matrimony_profiles')) {
            return;
        }

        Schema::table('matrimony_profiles', function (Blueprint $table): void {
            $after = 'highest_education';
            if (! Schema::hasColumn('matrimony_profiles', 'highest_education_id')) {
                $table->unsignedBigInteger('highest_education_id')->nullable()->after($after);
                $after = 'highest_education_id';
            }
            if (! Schema::hasColumn('matrimony_profiles', 'highest_education_text')) {
                $table->string('highest_education_text')->nullable()->after($after);
                $after = 'highest_education_text';
            }
            if (! Schema::hasColumn('matrimony_profiles', 'education_degree_id')) {
                $table->unsignedBigInteger('education_degree_id')->nullable()->after($after);
                $after = 'education_degree_id';
            }
            if (! Schema::hasColumn('matrimony_profiles', 'education_text')) {
                $table->string('education_text')->nullable()->after($after);
            }
        });

        if (Schema::hasTable('education_degrees') && Schema::hasColumn('matrimony_profiles', 'education_degree_id')) {
            try {
                Schema::table('matrimony_profiles', function (Blueprint $table): void {
                    $table->foreign('education_degree_id')->references('id')->on('education_degrees')->nullOnDelete();
                });
            } catch (\Throwable) {
            }
        }
    }
};
