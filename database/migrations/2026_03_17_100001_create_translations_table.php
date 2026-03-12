<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Admin-editable translations. Keys (small letter English) are not editable;
     * only the display value per locale is stored here. DB overrides file-based lang.
     */
    public function up(): void
    {
        Schema::create('translations', function (Blueprint $table) {
            $table->id();
            $table->string('locale', 10)->index();
            $table->string('key', 255)->index();
            $table->text('value')->nullable();
            $table->timestamps();
            $table->unique(['locale', 'key']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('translations');
    }
};
