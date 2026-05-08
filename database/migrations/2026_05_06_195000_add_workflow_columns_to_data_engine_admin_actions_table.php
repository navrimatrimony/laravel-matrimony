<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('data_engine_admin_actions', function (Blueprint $table) {
            $table->string('workflow_state', 32)->default('detected')->after('status');
            $table->unsignedTinyInteger('progress_percent')->default(0)->after('workflow_state');
            $table->foreignId('approved_by')->nullable()->after('user_id')->constrained('users')->nullOnDelete();
            $table->timestamp('approved_at')->nullable()->after('approved_by');
            $table->timestamp('eta_at')->nullable()->after('approved_at');
            $table->json('before_payload')->nullable()->after('request_payload');
            $table->json('after_payload')->nullable()->after('before_payload');
            $table->json('validation_payload')->nullable()->after('after_payload');
            $table->json('rollback_payload')->nullable()->after('validation_payload');

            $table->index(['workflow_state', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::table('data_engine_admin_actions', function (Blueprint $table) {
            $table->dropConstrainedForeignId('approved_by');
            $table->dropColumn([
                'workflow_state',
                'progress_percent',
                'approved_at',
                'eta_at',
                'before_payload',
                'after_payload',
                'validation_payload',
                'rollback_payload',
            ]);
        });
    }
};

