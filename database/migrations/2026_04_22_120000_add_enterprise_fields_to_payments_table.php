<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payments', function (Blueprint $table) {
            $table->string('plan_key', 128)->nullable()->after('txnid');
            $table->string('source', 32)->default('redirect')->after('gateway');
            $table->boolean('is_processed')->default(false)->after('source');
            $table->boolean('webhook_is_final')->default(false)->after('is_processed');
        });
    }

    public function down(): void
    {
        Schema::table('payments', function (Blueprint $table) {
            $table->dropColumn(['plan_key', 'source', 'is_processed', 'webhook_is_final']);
        });
    }
};
