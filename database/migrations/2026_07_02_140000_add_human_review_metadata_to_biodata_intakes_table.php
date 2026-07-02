<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('biodata_intakes', function (Blueprint $table): void {
            if (! Schema::hasColumn('biodata_intakes', 'reviewed_by_user_id')) {
                $table->foreignId('reviewed_by_user_id')
                    ->nullable()
                    ->constrained('users')
                    ->nullOnDelete();
            }
            if (! Schema::hasColumn('biodata_intakes', 'review_actor_type')) {
                $table->string('review_actor_type', 32)->nullable();
            }
            if (! Schema::hasColumn('biodata_intakes', 'review_surface')) {
                $table->string('review_surface', 32)->nullable();
            }
            if (! Schema::hasColumn('biodata_intakes', 'reviewed_at')) {
                $table->timestamp('reviewed_at')->nullable();
            }
            if (! Schema::hasColumn('biodata_intakes', 'approval_policy')) {
                $table->string('approval_policy', 64)->nullable();
            }
            if (! Schema::hasColumn('biodata_intakes', 'approval_status')) {
                $table->string('approval_status', 32)->nullable();
            }
        });
    }

    public function down(): void
    {
        Schema::table('biodata_intakes', function (Blueprint $table): void {
            if (Schema::hasColumn('biodata_intakes', 'reviewed_by_user_id')) {
                $table->dropConstrainedForeignId('reviewed_by_user_id');
            }
            foreach ([
                'review_actor_type',
                'review_surface',
                'reviewed_at',
                'approval_policy',
                'approval_status',
            ] as $column) {
                if (Schema::hasColumn('biodata_intakes', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
