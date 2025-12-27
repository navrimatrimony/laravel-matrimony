<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
   
     public function up(): void
{
    Schema::create('profiles', function (Blueprint $table) {
        $table->id();

        $table->foreignId('user_id')->constrained()->cascadeOnDelete();

        $table->string('full_name');
        $table->string('gender'); // derived copy from users
        $table->date('date_of_birth')->nullable();
        $table->string('height')->nullable();
        $table->string('caste')->nullable();
        $table->string('education')->nullable();
        $table->string('occupation')->nullable();
        $table->string('location')->nullable();

        $table->timestamps();
    });
}


    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('profiles');
    }
};
