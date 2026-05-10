<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Free-form “work location” text moves to {@code profile_addresses.address_line}
 * on self + type {@code work}. Drops redundant {@code matrimony_profiles.work_location_text}.
 *
 * Preconditions: migration 2026_05_13 (typed native/work leaf) and address_scope/address_line migrations.
 */
return new class extends Migration
{
    public function up(): void
    {
        $tbl = 'matrimony_profiles';
        if (! Schema::hasTable($tbl) || ! Schema::hasColumn($tbl, 'work_location_text')) {
            return;
        }
        if (! Schema::hasTable('profile_addresses')
            || ! Schema::hasTable('master_address_types')
            || ! Schema::hasColumn('profile_addresses', 'address_line')
        ) {
            return;
        }
        if (! DB::table('master_address_types')->where('key', 'work')->exists()) {
            return;
        }

        $typeId = (int) DB::table('master_address_types')->where('key', 'work')->value('id');
        $leafCol = Schema::hasColumn('profile_addresses', 'location_id') ? 'location_id' : 'city_id';

        DB::table($tbl)->orderBy('id')->chunkById(500, function ($rows) use ($typeId, $leafCol): void {
            foreach ($rows as $p) {
                $raw = $p->work_location_text ?? null;
                $wlt = trim((string) ($raw ?? ''));
                if ($wlt === '') {
                    continue;
                }
                $pid = (int) $p->id;
                $existing = DB::table('profile_addresses')
                    ->where('profile_id', $pid)
                    ->where('address_scope', 'self')
                    ->where('address_type_id', $typeId)
                    ->first();
                $now = now();
                if ($existing) {
                    $line = isset($existing->address_line) ? trim((string) $existing->address_line) : '';
                    if ($line === '') {
                        DB::table('profile_addresses')->where('id', $existing->id)->update([
                            'address_line' => mb_substr($wlt, 0, 255),
                            'updated_at' => $now,
                        ]);
                    }

                    continue;
                }
                DB::table('profile_addresses')->insert([
                    'profile_id' => $pid,
                    'address_scope' => 'self',
                    'address_type_id' => $typeId,
                    'address_line' => mb_substr($wlt, 0, 255),
                    $leafCol => null,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            }
        });

        if (Schema::hasTable('field_registry') && Schema::hasColumn('field_registry', 'is_archived')) {
            DB::table('field_registry')
                ->where('field_key', 'work_location_text')
                ->update(['is_archived' => true, 'updated_at' => now()]);
        }

        Schema::table($tbl, function (Blueprint $table) use ($tbl): void {
            if (Schema::hasColumn($tbl, 'work_location_text')) {
                $table->dropColumn('work_location_text');
            }
        });
    }

    public function down(): void
    {
        $tbl = 'matrimony_profiles';
        if (! Schema::hasTable($tbl)) {
            return;
        }
        if (! Schema::hasColumn($tbl, 'work_location_text')) {
            Schema::table($tbl, function (Blueprint $table): void {
                $table->string('work_location_text', 255)->nullable()->after('company_name');
            });
        }

        if (Schema::hasTable('field_registry') && Schema::hasColumn('field_registry', 'is_archived')) {
            DB::table('field_registry')
                ->where('field_key', 'work_location_text')
                ->update(['is_archived' => false, 'updated_at' => now()]);
        }
    }
};
