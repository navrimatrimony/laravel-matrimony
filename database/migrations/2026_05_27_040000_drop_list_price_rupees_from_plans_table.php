<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('plans') || ! Schema::hasColumn('plans', 'list_price_rupees')) {
            return;
        }

        Schema::table('plans', function (Blueprint $table): void {
            $table->dropColumn('list_price_rupees');
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('plans') || Schema::hasColumn('plans', 'list_price_rupees')) {
            return;
        }

        Schema::table('plans', function (Blueprint $table): void {
            $table->decimal('list_price_rupees', 12, 2)->nullable()->after('price');
        });
    }
};
