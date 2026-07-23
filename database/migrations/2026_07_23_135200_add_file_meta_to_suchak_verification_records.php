<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('suchak_verification_records', function (Blueprint $table): void {
            if (! Schema::hasColumn('suchak_verification_records', 'file_meta')) {
                $table->json('file_meta')->nullable()->after('document_path');
            }
        });
    }

    public function down(): void
    {
        Schema::table('suchak_verification_records', function (Blueprint $table): void {
            if (Schema::hasColumn('suchak_verification_records', 'file_meta')) {
                $table->dropColumn('file_meta');
            }
        });
    }
};
