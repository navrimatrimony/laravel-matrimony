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
        Schema::table('matrimony_profiles', function (Blueprint $table) {
            // Track admin edit metadata
            $table->foreignId('edited_by')->nullable()->after('visibility_override_reason')->constrained('users')->nullOnDelete();
            $table->timestamp('edited_at')->nullable()->after('edited_by');
            $table->text('edit_reason')->nullable()->after('edited_at');
            $table->string('edited_source')->nullable()->after('edit_reason')->default(null); // 'admin' or null
            // JSON field to track which fields were edited by admin (for showing "Admin corrected" markers)
            $table->json('admin_edited_fields')->nullable()->after('edited_source');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('matrimony_profiles', function (Blueprint $table) {
            $table->dropForeign(['edited_by']);
            $table->dropColumn(['edited_by', 'edited_at', 'edit_reason', 'edited_source', 'admin_edited_fields']);
        });
    }
};
