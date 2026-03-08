<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Nakshatra-level attributes: gan, nadi, yoni.
     * Canonical Nadi: use master_nadis.key = adi | madhya | antya (labels may include Vata/Pitta/Kapha).
     */
    public function up(): void
    {
        Schema::create('master_nakshatra_attributes', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('nakshatra_id');
            $table->unsignedBigInteger('gan_id')->nullable();
            $table->unsignedBigInteger('nadi_id')->nullable();
            $table->unsignedBigInteger('yoni_id')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique('nakshatra_id');
            $table->index('nakshatra_id');
            $table->foreign('nakshatra_id')->references('id')->on('master_nakshatras')->cascadeOnDelete();
            $table->foreign('gan_id')->references('id')->on('master_gans')->nullOnDelete();
            $table->foreign('nadi_id')->references('id')->on('master_nadis')->nullOnDelete();
            $table->foreign('yoni_id')->references('id')->on('master_yonis')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('master_nakshatra_attributes');
    }
};
