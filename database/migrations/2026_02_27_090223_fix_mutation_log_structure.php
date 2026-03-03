<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        if (! Schema::hasTable('mutation_log')) {
            return;
        }

        Schema::table('mutation_log', function (Blueprint $table) {
            if (! Schema::hasColumn('mutation_log', 'profile_id')) {
                $table->unsignedBigInteger('profile_id')->nullable()->index()->after('id');
            }
            if (! Schema::hasColumn('mutation_log', 'intake_id')) {
                $table->unsignedBigInteger('intake_id')->nullable()->index()->after('profile_id');
            }
            if (! Schema::hasColumn('mutation_log', 'mutation_status')) {
                $table->string('mutation_status')->nullable()->after('intake_id');
            }
            if (! Schema::hasColumn('mutation_log', 'conflict_detected')) {
                $table->boolean('conflict_detected')->default(false)->after('mutation_status');
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (! Schema::hasTable('mutation_log')) {
            return;
        }

        Schema::table('mutation_log', function (Blueprint $table) {
            $columns = ['profile_id', 'intake_id', 'mutation_status', 'conflict_detected'];
            foreach ($columns as $col) {
                if (Schema::hasColumn('mutation_log', $col)) {
                    $table->dropColumn($col);
                }
            }
        });
    }
};
