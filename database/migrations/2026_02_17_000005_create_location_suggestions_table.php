<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('location_suggestions', function (Blueprint $table) {
            $table->id();
            $table->string('suggested_name');
            $table->string('normalized_name');
            $table->unsignedBigInteger('country_id');
            $table->unsignedBigInteger('state_id');
            $table->unsignedBigInteger('district_id');
            $table->unsignedBigInteger('taluka_id');
            $table->enum('suggestion_type', ['city', 'village']);
            $table->unsignedBigInteger('suggested_by');
            $table->enum('status', ['pending', 'approved', 'rejected'])->default('pending');
            $table->unsignedBigInteger('admin_reviewed_by')->nullable();
            $table->timestamp('admin_reviewed_at')->nullable();
            $table->timestamps();

            $table->foreign('country_id')->references('id')->on('countries')->onDelete('restrict');
            $table->foreign('state_id')->references('id')->on('states')->onDelete('restrict');
            $table->foreign('district_id')->references('id')->on('districts')->onDelete('restrict');
            $table->foreign('taluka_id')->references('id')->on('talukas')->onDelete('restrict');
            $table->foreign('suggested_by')->references('id')->on('users')->onDelete('restrict');
            $table->foreign('admin_reviewed_by')->references('id')->on('users')->onDelete('set null');

            $table->unique(['normalized_name', 'taluka_id']);
            $table->index('normalized_name');
            $table->index('status');
            $table->index('taluka_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('location_suggestions');
    }
};
