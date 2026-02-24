<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Phase-5 SSOT OPTION-2: Replace string lookup columns with FK to master tables.
 * Add *_id -> migrate from old string by key match -> drop old columns -> add FK + index.
 */
return new class extends Migration
{
    public function up(): void
    {
        $tableName = 'matrimony_profiles';

        // Ensure master lookup tables are seeded before data migration
        (new \Database\Seeders\MasterLookupSeeder())->run();

        // Step 1: Add new *_id columns (nullable)
        Schema::table($tableName, function (Blueprint $schema) use ($tableName) {
            if (! Schema::hasColumn($tableName, 'gender_id')) {
                $schema->unsignedBigInteger('gender_id')->nullable()->after('full_name');
            }
            if (! Schema::hasColumn($tableName, 'marital_status_id')) {
                $schema->unsignedBigInteger('marital_status_id')->nullable()->after('date_of_birth');
            }
            if (! Schema::hasColumn($tableName, 'complexion_id')) {
                $schema->unsignedBigInteger('complexion_id')->nullable();
            }
            if (! Schema::hasColumn($tableName, 'physical_build_id')) {
                $schema->unsignedBigInteger('physical_build_id')->nullable();
            }
            if (! Schema::hasColumn($tableName, 'blood_group_id')) {
                $schema->unsignedBigInteger('blood_group_id')->nullable();
            }
            if (! Schema::hasColumn($tableName, 'family_type_id')) {
                $schema->unsignedBigInteger('family_type_id')->nullable();
            }
            if (! Schema::hasColumn($tableName, 'income_currency_id')) {
                $schema->unsignedBigInteger('income_currency_id')->nullable();
            }
        });

        $t = $tableName;

        // Step 2: Migrate existing values by key match
        if (Schema::hasColumn($t, 'gender')) {
            DB::table('master_genders')->get()->each(function ($master) use ($t) {
                DB::table($t)->where('gender', $master->key)->update(['gender_id' => $master->id]);
            });
        }

        if (Schema::hasColumn($t, 'marital_status')) {
            DB::table('master_marital_statuses')->get()->each(function ($master) use ($t) {
                if ($master->key === 'never_married') {
                    DB::table($t)->whereIn('marital_status', ['never_married', 'single'])->update(['marital_status_id' => $master->id]);
                } else {
                    DB::table($t)->where('marital_status', $master->key)->update(['marital_status_id' => $master->id]);
                }
            });
        }

        if (Schema::hasColumn($t, 'complexion')) {
            DB::table('master_complexions')->get()->each(function ($master) use ($t) {
                DB::table($t)->where('complexion', $master->key)->update(['complexion_id' => $master->id]);
            });
        }

        if (Schema::hasColumn($t, 'physical_build')) {
            DB::table('master_physical_builds')->get()->each(function ($master) use ($t) {
                DB::table($t)->where('physical_build', $master->key)->update(['physical_build_id' => $master->id]);
            });
        }

        if (Schema::hasColumn($t, 'blood_group')) {
            DB::table('master_blood_groups')->get()->each(function ($master) use ($t) {
                DB::table($t)->where('blood_group', $master->key)->update(['blood_group_id' => $master->id]);
            });
        }

        if (Schema::hasColumn($t, 'family_type')) {
            DB::table('master_family_types')->get()->each(function ($master) use ($t) {
                DB::table($t)->where('family_type', $master->key)->update(['family_type_id' => $master->id]);
            });
        }

        if (Schema::hasColumn($t, 'income_currency')) {
            DB::table('master_income_currencies')->get()->each(function ($master) use ($t) {
                DB::table($t)->where('income_currency', $master->code)->update(['income_currency_id' => $master->id]);
            });
            // Set default INR for nulls
            $inrId = DB::table('master_income_currencies')->where('code', 'INR')->value('id');
            if ($inrId !== null) {
                DB::table($t)->whereNull('income_currency_id')->whereNotNull('income_currency')->update(['income_currency_id' => $inrId]);
            }
        }

        // Step 3: Drop old string columns
        Schema::table($tableName, function (Blueprint $schema) use ($tableName) {
            $drops = [];
            if (Schema::hasColumn($tableName, 'gender')) {
                $drops[] = 'gender';
            }
            if (Schema::hasColumn($tableName, 'marital_status')) {
                $drops[] = 'marital_status';
            }
            if (Schema::hasColumn($tableName, 'complexion')) {
                $drops[] = 'complexion';
            }
            if (Schema::hasColumn($tableName, 'physical_build')) {
                $drops[] = 'physical_build';
            }
            if (Schema::hasColumn($tableName, 'blood_group')) {
                $drops[] = 'blood_group';
            }
            if (Schema::hasColumn($tableName, 'family_type')) {
                $drops[] = 'family_type';
            }
            if (Schema::hasColumn($tableName, 'income_currency')) {
                $drops[] = 'income_currency';
            }
            if ($drops !== []) {
                $schema->dropColumn($drops);
            }
        });

        // Step 4: Add foreign keys and indexes
        Schema::table($tableName, function (Blueprint $schema) use ($tableName) {
            if (Schema::hasColumn($tableName, 'gender_id')) {
                $schema->foreign('gender_id')->references('id')->on('master_genders')->nullOnDelete();
                $schema->index('gender_id');
            }
            if (Schema::hasColumn($tableName, 'marital_status_id')) {
                $schema->foreign('marital_status_id')->references('id')->on('master_marital_statuses')->nullOnDelete();
                $schema->index('marital_status_id');
            }
            if (Schema::hasColumn($tableName, 'complexion_id')) {
                $schema->foreign('complexion_id')->references('id')->on('master_complexions')->nullOnDelete();
                $schema->index('complexion_id');
            }
            if (Schema::hasColumn($tableName, 'physical_build_id')) {
                $schema->foreign('physical_build_id')->references('id')->on('master_physical_builds')->nullOnDelete();
                $schema->index('physical_build_id');
            }
            if (Schema::hasColumn($tableName, 'blood_group_id')) {
                $schema->foreign('blood_group_id')->references('id')->on('master_blood_groups')->nullOnDelete();
                $schema->index('blood_group_id');
            }
            if (Schema::hasColumn($tableName, 'family_type_id')) {
                $schema->foreign('family_type_id')->references('id')->on('master_family_types')->nullOnDelete();
                $schema->index('family_type_id');
            }
            if (Schema::hasColumn($tableName, 'income_currency_id')) {
                $schema->foreign('income_currency_id')->references('id')->on('master_income_currencies')->nullOnDelete();
                $schema->index('income_currency_id');
            }
        });
    }

    public function down(): void
    {
        $tableName = 'matrimony_profiles';

        // Drop foreign keys first
        Schema::table($tableName, function (Blueprint $schema) use ($tableName) {
            $fks = [
                'matrimony_profiles_gender_id_foreign',
                'matrimony_profiles_marital_status_id_foreign',
                'matrimony_profiles_complexion_id_foreign',
                'matrimony_profiles_physical_build_id_foreign',
                'matrimony_profiles_blood_group_id_foreign',
                'matrimony_profiles_family_type_id_foreign',
                'matrimony_profiles_income_currency_id_foreign',
            ];
            foreach ($fks as $fk) {
                if ($this->foreignKeyExists($tableName, $fk)) {
                    $schema->dropForeign($fk);
                }
            }
            $schema->dropIndex(['gender_id']);
            $schema->dropIndex(['marital_status_id']);
            $schema->dropIndex(['complexion_id']);
            $schema->dropIndex(['physical_build_id']);
            $schema->dropIndex(['blood_group_id']);
            $schema->dropIndex(['family_type_id']);
            $schema->dropIndex(['income_currency_id']);
        });

        // Add back string columns
        Schema::table($tableName, function (Blueprint $schema) use ($tableName) {
            if (! Schema::hasColumn($tableName, 'gender')) {
                $schema->string('gender', 16)->nullable()->after('full_name');
            }
            if (! Schema::hasColumn($tableName, 'marital_status')) {
                $schema->string('marital_status', 32)->nullable()->after('date_of_birth');
            }
            if (! Schema::hasColumn($tableName, 'complexion')) {
                $schema->string('complexion')->nullable();
            }
            if (! Schema::hasColumn($tableName, 'physical_build')) {
                $schema->string('physical_build')->nullable();
            }
            if (! Schema::hasColumn($tableName, 'blood_group')) {
                $schema->string('blood_group')->nullable();
            }
            if (! Schema::hasColumn($tableName, 'family_type')) {
                $schema->string('family_type')->nullable();
            }
            if (! Schema::hasColumn($tableName, 'income_currency')) {
                $schema->string('income_currency', 10)->nullable();
            }
        });

        // Repopulate string from master (id -> key)
        $t = $tableName;
        if (Schema::hasColumn($t, 'gender_id')) {
            DB::table('master_genders')->get()->each(function ($master) use ($t) {
                DB::table($t)->where('gender_id', $master->id)->update(['gender' => $master->key]);
            });
        }
        if (Schema::hasColumn($t, 'marital_status_id')) {
            DB::table('master_marital_statuses')->get()->each(function ($master) use ($t) {
                $key = $master->key === 'never_married' ? 'single' : $master->key;
                DB::table($t)->where('marital_status_id', $master->id)->update(['marital_status' => $key]);
            });
        }
        if (Schema::hasColumn($t, 'complexion_id')) {
            DB::table('master_complexions')->get()->each(function ($master) use ($t) {
                DB::table($t)->where('complexion_id', $master->id)->update(['complexion' => $master->key]);
            });
        }
        if (Schema::hasColumn($t, 'physical_build_id')) {
            DB::table('master_physical_builds')->get()->each(function ($master) use ($t) {
                DB::table($t)->where('physical_build_id', $master->id)->update(['physical_build' => $master->key]);
            });
        }
        if (Schema::hasColumn($t, 'blood_group_id')) {
            DB::table('master_blood_groups')->get()->each(function ($master) use ($t) {
                DB::table($t)->where('blood_group_id', $master->id)->update(['blood_group' => $master->key]);
            });
        }
        if (Schema::hasColumn($t, 'family_type_id')) {
            DB::table('master_family_types')->get()->each(function ($master) use ($t) {
                DB::table($t)->where('family_type_id', $master->id)->update(['family_type' => $master->key]);
            });
        }
        if (Schema::hasColumn($t, 'income_currency_id')) {
            DB::table('master_income_currencies')->get()->each(function ($master) use ($t) {
                DB::table($t)->where('income_currency_id', $master->id)->update(['income_currency' => $master->code]);
            });
        }

        // Drop *_id columns
        Schema::table($tableName, function (Blueprint $schema) use ($tableName) {
            $drops = [];
            if (Schema::hasColumn($tableName, 'gender_id')) {
                $drops[] = 'gender_id';
            }
            if (Schema::hasColumn($tableName, 'marital_status_id')) {
                $drops[] = 'marital_status_id';
            }
            if (Schema::hasColumn($tableName, 'complexion_id')) {
                $drops[] = 'complexion_id';
            }
            if (Schema::hasColumn($tableName, 'physical_build_id')) {
                $drops[] = 'physical_build_id';
            }
            if (Schema::hasColumn($tableName, 'blood_group_id')) {
                $drops[] = 'blood_group_id';
            }
            if (Schema::hasColumn($tableName, 'family_type_id')) {
                $drops[] = 'family_type_id';
            }
            if (Schema::hasColumn($tableName, 'income_currency_id')) {
                $drops[] = 'income_currency_id';
            }
            if ($drops !== []) {
                $schema->dropColumn($drops);
            }
        });
    }

    private function foreignKeyExists(string $table, string $name): bool
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
