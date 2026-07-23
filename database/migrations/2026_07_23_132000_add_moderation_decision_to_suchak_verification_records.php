<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('suchak_verification_records', function (Blueprint $table): void {
            if (! Schema::hasColumn('suchak_verification_records', 'moderation_decision')) {
                $table->string('moderation_decision', 32)->nullable()->after('admin_status');
                $table->index('moderation_decision');
            }
        });
    }

    public function down(): void
    {
        Schema::table('suchak_verification_records', function (Blueprint $table): void {
            if (Schema::hasColumn('suchak_verification_records', 'moderation_decision')) {
                $table->dropIndex(['moderation_decision']);
                $table->dropColumn('moderation_decision');
            }
        });
    }
};
