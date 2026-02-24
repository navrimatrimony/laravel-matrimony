<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Phase-5: profile_horoscope_data — rashi_id, nakshatra_id, gan_id, nadi_id, mangal_dosh_type_id, yoni_id.
 */
return new class extends Migration
{
    public function up(): void
    {
        (new \Database\Seeders\MasterLookupSeeder())->run();

        $t = 'profile_horoscope_data';
        if (! Schema::hasTable($t)) {
            return;
        }

        Schema::table($t, function (Blueprint $schema) use ($t) {
            if (! Schema::hasColumn($t, 'rashi_id')) {
                $schema->unsignedBigInteger('rashi_id')->nullable()->after('profile_id');
            }
            if (! Schema::hasColumn($t, 'nakshatra_id')) {
                $schema->unsignedBigInteger('nakshatra_id')->nullable()->after('rashi_id');
            }
            if (! Schema::hasColumn($t, 'gan_id')) {
                $schema->unsignedBigInteger('gan_id')->nullable();
            }
            if (! Schema::hasColumn($t, 'nadi_id')) {
                $schema->unsignedBigInteger('nadi_id')->nullable();
            }
            if (! Schema::hasColumn($t, 'mangal_dosh_type_id')) {
                $schema->unsignedBigInteger('mangal_dosh_type_id')->nullable();
            }
            if (! Schema::hasColumn($t, 'yoni_id')) {
                $schema->unsignedBigInteger('yoni_id')->nullable();
            }
        });

        $this->migrateByKey($t, 'rashi', 'rashi_id', 'master_rashis');
        $this->migrateByKey($t, 'nakshatra', 'nakshatra_id', 'master_nakshatras');
        $this->migrateByKey($t, 'gan', 'gan_id', 'master_gans');
        $this->migrateByKey($t, 'nadi', 'nadi_id', 'master_nadis');
        $this->migrateByKey($t, 'mangal_dosh_type', 'mangal_dosh_type_id', 'master_mangal_dosh_types');
        $this->migrateByKey($t, 'yoni', 'yoni_id', 'master_yonis');

        Schema::table($t, function (Blueprint $schema) use ($t) {
            $drops = [];
            if (Schema::hasColumn($t, 'rashi')) {
                $drops[] = 'rashi';
            }
            if (Schema::hasColumn($t, 'nakshatra')) {
                $drops[] = 'nakshatra';
            }
            if (Schema::hasColumn($t, 'gan')) {
                $drops[] = 'gan';
            }
            if (Schema::hasColumn($t, 'nadi')) {
                $drops[] = 'nadi';
            }
            if (Schema::hasColumn($t, 'mangal_dosh_type')) {
                $drops[] = 'mangal_dosh_type';
            }
            if (Schema::hasColumn($t, 'yoni')) {
                $drops[] = 'yoni';
            }
            if ($drops !== []) {
                $schema->dropColumn($drops);
            }
        });

        Schema::table($t, function (Blueprint $schema) use ($t) {
            if (Schema::hasColumn($t, 'rashi_id')) {
                $schema->foreign('rashi_id')->references('id')->on('master_rashis')->nullOnDelete();
                $schema->index('rashi_id');
            }
            if (Schema::hasColumn($t, 'nakshatra_id')) {
                $schema->foreign('nakshatra_id')->references('id')->on('master_nakshatras')->nullOnDelete();
                $schema->index('nakshatra_id');
            }
            if (Schema::hasColumn($t, 'gan_id')) {
                $schema->foreign('gan_id')->references('id')->on('master_gans')->nullOnDelete();
                $schema->index('gan_id');
            }
            if (Schema::hasColumn($t, 'nadi_id')) {
                $schema->foreign('nadi_id')->references('id')->on('master_nadis')->nullOnDelete();
                $schema->index('nadi_id');
            }
            if (Schema::hasColumn($t, 'mangal_dosh_type_id')) {
                $schema->foreign('mangal_dosh_type_id')->references('id')->on('master_mangal_dosh_types')->nullOnDelete();
                $schema->index('mangal_dosh_type_id');
            }
            if (Schema::hasColumn($t, 'yoni_id')) {
                $schema->foreign('yoni_id')->references('id')->on('master_yonis')->nullOnDelete();
                $schema->index('yoni_id');
            }
        });
    }

    public function down(): void
    {
        $t = 'profile_horoscope_data';
        if (! Schema::hasTable($t)) {
            return;
        }
        $this->dropFksAndIndexes($t, ['rashi_id', 'nakshatra_id', 'gan_id', 'nadi_id', 'mangal_dosh_type_id', 'yoni_id']);
        Schema::table($t, function (Blueprint $schema) use ($t) {
            if (! Schema::hasColumn($t, 'rashi')) {
                $schema->string('rashi')->nullable()->after('profile_id');
            }
            if (! Schema::hasColumn($t, 'nakshatra')) {
                $schema->string('nakshatra')->nullable();
            }
            if (! Schema::hasColumn($t, 'gan')) {
                $schema->string('gan')->nullable();
            }
            if (! Schema::hasColumn($t, 'nadi')) {
                $schema->string('nadi')->nullable();
            }
            if (! Schema::hasColumn($t, 'mangal_dosh_type')) {
                $schema->string('mangal_dosh_type')->nullable();
            }
            if (! Schema::hasColumn($t, 'yoni')) {
                $schema->string('yoni')->nullable();
            }
        });
        $this->backfillFromMaster($t, 'rashi_id', 'rashi', 'master_rashis');
        $this->backfillFromMaster($t, 'nakshatra_id', 'nakshatra', 'master_nakshatras');
        $this->backfillFromMaster($t, 'gan_id', 'gan', 'master_gans');
        $this->backfillFromMaster($t, 'nadi_id', 'nadi', 'master_nadis');
        $this->backfillFromMaster($t, 'mangal_dosh_type_id', 'mangal_dosh_type', 'master_mangal_dosh_types');
        $this->backfillFromMaster($t, 'yoni_id', 'yoni', 'master_yonis');
        Schema::table($t, function (Blueprint $schema) use ($t) {
            $drops = ['rashi_id', 'nakshatra_id', 'gan_id', 'nadi_id', 'mangal_dosh_type_id', 'yoni_id'];
            $drops = array_filter($drops, fn ($c) => Schema::hasColumn($t, $c));
            if ($drops !== []) {
                $schema->dropColumn($drops);
            }
        });
    }

    private function migrateByKey(string $table, string $col, string $idCol, string $masterTable): void
    {
        if (! Schema::hasColumn($table, $col)) {
            return;
        }
        foreach (DB::table($masterTable)->get() as $master) {
            DB::table($table)
                ->whereRaw('LOWER(TRIM(REPLACE(' . $col . ', " ", "_"))) = ?', [$master->key])
                ->orWhere($col, $master->key)
                ->orWhere($col, $master->label)
                ->update([$idCol => $master->id]);
        }
        foreach (DB::table($table)->whereNotNull($col)->whereNull($idCol)->get() as $row) {
            $val = trim((string) $row->{$col});
            if ($val === '') {
                continue;
            }
            $normalized = str_replace(' ', '_', strtolower($val));
            $master = DB::table($masterTable)->where('key', $normalized)->first();
            if ($master) {
                DB::table($table)->where('id', $row->id)->update([$idCol => $master->id]);
            }
        }
    }

    private function backfillFromMaster(string $table, string $idCol, string $col, string $masterTable): void
    {
        if (! Schema::hasColumn($table, $idCol)) {
            return;
        }
        foreach (DB::table($masterTable)->get() as $master) {
            DB::table($table)->where($idCol, $master->id)->update([$col => $master->key]);
        }
    }

    private function dropFksAndIndexes(string $table, array $cols): void
    {
        Schema::table($table, function (Blueprint $schema) use ($table, $cols) {
            foreach ($cols as $col) {
                if (! Schema::hasColumn($table, $col)) {
                    continue;
                }
                $fk = "{$table}_{$col}_foreign";
                if ($this->fkExists($table, $fk)) {
                    $schema->dropForeign($fk);
                }
                $schema->dropIndex([$col]);
            }
        });
    }

    private function fkExists(string $table, string $name): bool
    {
        $conn = Schema::getConnection();
        $db = $conn->getDatabaseName();
        $result = $conn->select(
            "SELECT CONSTRAINT_NAME FROM information_schema.TABLE_CONSTRAINTS WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ? AND CONSTRAINT_TYPE = 'FOREIGN KEY' AND CONSTRAINT_NAME = ?",
            [$db, $table, $name]
        );
        return count($result) > 0;
    }
};
