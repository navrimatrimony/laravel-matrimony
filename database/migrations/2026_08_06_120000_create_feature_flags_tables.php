<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('feature_flags', function (Blueprint $table) {
            $table->id();
            $table->string('key', 100)->unique();
            $table->string('display_name', 191);
            $table->text('description')->nullable();
            $table->boolean('enabled')->default(false);
            $table->timestamps();
        });

        Schema::create('feature_flag_audits', function (Blueprint $table) {
            $table->id();
            $table->foreignId('feature_flag_id')->constrained('feature_flags')->restrictOnDelete();
            $table->string('key', 100);
            $table->boolean('old_value');
            $table->boolean('new_value');
            $table->foreignId('changed_by')->constrained('users')->restrictOnDelete();
            $table->string('ip_address', 45)->nullable();
            $table->text('user_agent')->nullable();
            $table->string('reason', 500)->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->index(['feature_flag_id', 'created_at']);
            $table->index(['key', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('feature_flag_audits');
        Schema::dropIfExists('feature_flags');
    }
};
