<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     * Additive only: rule lineage/versioning. No existing columns or data modified.
     */
    public function up(): void
    {
        if (! Schema::hasTable('ocr_correction_patterns')) {
            return;
        }

        Schema::table('ocr_correction_patterns', function (Blueprint $table) {
            $table->string('rule_family_key', 191)->nullable()->after('source');
            $table->unsignedInteger('rule_version')->nullable()->after('rule_family_key');
            $table->unsignedBigInteger('supersedes_pattern_id')->nullable()->after('rule_version');
            $table->timestamp('retired_at')->nullable()->after('supersedes_pattern_id');
            $table->string('retirement_reason', 191)->nullable()->after('retired_at');
            $table->string('authored_by_type', 32)->nullable()->after('retirement_reason');
            $table->unsignedBigInteger('authored_by_id')->nullable()->after('authored_by_type');
            $table->string('promotion_status', 32)->nullable()->after('authored_by_id');
        });

        Schema::table('ocr_correction_patterns', function (Blueprint $table) {
            $table->index('rule_family_key');
            $table->index('supersedes_pattern_id');
            $table->foreign('supersedes_pattern_id')
                ->references('id')
                ->on('ocr_correction_patterns')
                ->nullOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     * Removes lineage columns only; no rule rows are deleted.
     */
    public function down(): void
    {
        if (! Schema::hasTable('ocr_correction_patterns')) {
            return;
        }

        Schema::table('ocr_correction_patterns', function (Blueprint $table) {
            $table->dropForeign(['supersedes_pattern_id']);
            $table->dropIndex(['rule_family_key']);
            $table->dropIndex(['supersedes_pattern_id']);
        });

        Schema::table('ocr_correction_patterns', function (Blueprint $table) {
            $table->dropColumn([
                'rule_family_key',
                'rule_version',
                'supersedes_pattern_id',
                'retired_at',
                'retirement_reason',
                'authored_by_type',
                'authored_by_id',
                'promotion_status',
            ]);
        });
    }
};
