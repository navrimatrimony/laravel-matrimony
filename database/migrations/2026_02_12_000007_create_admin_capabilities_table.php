<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('admin_capabilities', function (Blueprint $table) {
            $table->id();
            $table->foreignId('admin_id')
                ->unique()
                ->constrained('users')
                ->restrictOnDelete();
            $table->boolean('can_manage_verification_tags')->default(false);
            $table->boolean('can_manage_serious_intents')->default(false);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('admin_capabilities');
    }
};
