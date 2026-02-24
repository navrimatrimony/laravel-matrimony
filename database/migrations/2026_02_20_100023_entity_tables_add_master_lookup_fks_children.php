<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Phase-5: profile_children — lives_with_parent (boolean) → child_living_with_id.
 */
return new class extends Migration
{
    public function up(): void
    {
        $t = 'profile_children';
        if (! Schema::hasTable($t)) {
            return;
        }

        Schema::table($t, function (Blueprint $schema) use ($t) {
            if (! Schema::hasColumn($t, 'child_living_with_id')) {
                $schema->unsignedBigInteger('child_living_with_id')->nullable()->after('age');
            }
        });

        $withParentId = DB::table('master_child_living_with')->where('key', 'with_parent')->value('id');
        $withOtherId = DB::table('master_child_living_with')->where('key', 'with_other_parent')->value('id');
        if ($withParentId && Schema::hasColumn($t, 'lives_with_parent')) {
            DB::table($t)->where('lives_with_parent', true)->update(['child_living_with_id' => $withParentId]);
        }
        if ($withOtherId && Schema::hasColumn($t, 'lives_with_parent')) {
            DB::table($t)->where('lives_with_parent', false)->update(['child_living_with_id' => $withOtherId]);
        }
        if (Schema::hasColumn($t, 'lives_with_parent')) {
            DB::table($t)->whereNull('child_living_with_id')->update(['child_living_with_id' => $withParentId ?? $withOtherId]);
        }

        Schema::table($t, function (Blueprint $schema) use ($t) {
            if (Schema::hasColumn($t, 'lives_with_parent')) {
                $schema->dropColumn('lives_with_parent');
            }
        });

        Schema::table($t, function (Blueprint $schema) use ($t) {
            if (Schema::hasColumn($t, 'child_living_with_id')) {
                $schema->foreign('child_living_with_id')->references('id')->on('master_child_living_with')->nullOnDelete();
                $schema->index('child_living_with_id');
            }
        });
    }

    public function down(): void
    {
        $t = 'profile_children';
        if (! Schema::hasTable($t)) {
            return;
        }
        if (Schema::hasColumn($t, 'child_living_with_id')) {
            Schema::table($t, function (Blueprint $schema) use ($t) {
                $fk = $t . '_child_living_with_id_foreign';
                if ($this->fkExists($t, $fk)) {
                    $schema->dropForeign($fk);
                }
                $schema->dropIndex(['child_living_with_id']);
            });
        }
        Schema::table($t, function (Blueprint $schema) use ($t) {
            if (! Schema::hasColumn($t, 'lives_with_parent')) {
                $schema->boolean('lives_with_parent')->default(true)->after('age');
            }
        });
        $withParentId = DB::table('master_child_living_with')->where('key', 'with_parent')->value('id');
        if ($withParentId) {
            DB::table($t)->where('child_living_with_id', $withParentId)->update(['lives_with_parent' => true]);
        }
        DB::table($t)->where('child_living_with_id', '!=', $withParentId)->orWhereNull('child_living_with_id')->update(['lives_with_parent' => false]);
        Schema::table($t, function (Blueprint $schema) use ($t) {
            if (Schema::hasColumn($t, 'child_living_with_id')) {
                $schema->dropColumn('child_living_with_id');
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
