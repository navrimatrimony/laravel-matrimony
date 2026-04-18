<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * Centralized occupation engine (additive only). Bridges to legacy {@see working_with_types} / {@see professions} via seed.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('occupation_categories', function (Blueprint $table) {
            $table->id();
            $table->string('name', 128);
            $table->unsignedInteger('sort_order')->default(0);
            $table->foreignId('legacy_working_with_type_id')->nullable()->constrained('working_with_types')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('occupation_master', function (Blueprint $table) {
            $table->id();
            $table->string('name', 160);
            $table->string('normalized_name', 160)->nullable()->index();
            $table->foreignId('category_id')->constrained('occupation_categories')->cascadeOnDelete();
            $table->timestamps();
        });

        Schema::create('occupation_custom', function (Blueprint $table) {
            $table->id();
            $table->string('raw_name', 160);
            $table->string('normalized_name', 160)->index();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('status', 32)->default('pending');
            $table->timestamps();
        });

        if (Schema::hasTable('matrimony_profiles')) {
            Schema::table('matrimony_profiles', function (Blueprint $table) {
                if (! Schema::hasColumn('matrimony_profiles', 'occupation_master_id')) {
                    $table->unsignedBigInteger('occupation_master_id')->nullable()->after('profession_id');
                    $table->foreign('occupation_master_id')->references('id')->on('occupation_master')->nullOnDelete();
                }
                if (! Schema::hasColumn('matrimony_profiles', 'occupation_custom_id')) {
                    $table->unsignedBigInteger('occupation_custom_id')->nullable()->after('occupation_master_id');
                    $table->foreign('occupation_custom_id')->references('id')->on('occupation_custom')->nullOnDelete();
                }
            });
        }

        $this->seedFromLegacyMasters();
    }

    private function seedFromLegacyMasters(): void
    {
        if (! Schema::hasTable('working_with_types') || ! Schema::hasTable('occupation_categories')) {
            return;
        }

        $now = now();
        $wwts = DB::table('working_with_types')->orderBy('sort_order')->orderBy('id')->get();
        foreach ($wwts as $w) {
            $exists = DB::table('occupation_categories')->where('legacy_working_with_type_id', $w->id)->exists();
            if ($exists) {
                continue;
            }
            DB::table('occupation_categories')->insert([
                'name' => (string) $w->name,
                'sort_order' => (int) ($w->sort_order ?? 0),
                'legacy_working_with_type_id' => $w->id,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }

        $catByWwt = DB::table('occupation_categories')
            ->whereNotNull('legacy_working_with_type_id')
            ->pluck('id', 'legacy_working_with_type_id')
            ->all();

        $fallbackCategoryId = DB::table('occupation_categories')->orderBy('id')->value('id');
        if (! $fallbackCategoryId || ! Schema::hasTable('professions') || ! Schema::hasTable('occupation_master')) {
            return;
        }

        $profs = DB::table('professions')->where('is_active', true)->orderBy('sort_order')->orderBy('id')->get();
        foreach ($profs as $p) {
            $wid = $p->working_with_type_id ?? null;
            $cid = $wid !== null ? ($catByWwt[(int) $wid] ?? null) : null;
            $cid = $cid ?? $fallbackCategoryId;
            $norm = mb_strtolower(trim((string) $p->name));
            $norm = $norm !== '' ? Str::limit($norm, 160, '') : null;

            $dup = DB::table('occupation_master')
                ->where('category_id', $cid)
                ->whereRaw('LOWER(name) = ?', [mb_strtolower(trim((string) $p->name))])
                ->exists();
            if ($dup) {
                continue;
            }

            DB::table('occupation_master')->insert([
                'name' => trim((string) $p->name),
                'normalized_name' => $norm,
                'category_id' => $cid,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('matrimony_profiles')) {
            Schema::table('matrimony_profiles', function (Blueprint $table) {
                if (Schema::hasColumn('matrimony_profiles', 'occupation_custom_id')) {
                    $table->dropForeign(['occupation_custom_id']);
                    $table->dropColumn('occupation_custom_id');
                }
                if (Schema::hasColumn('matrimony_profiles', 'occupation_master_id')) {
                    $table->dropForeign(['occupation_master_id']);
                    $table->dropColumn('occupation_master_id');
                }
            });
        }

        Schema::dropIfExists('occupation_custom');
        Schema::dropIfExists('occupation_master');
        Schema::dropIfExists('occupation_categories');
    }
};
