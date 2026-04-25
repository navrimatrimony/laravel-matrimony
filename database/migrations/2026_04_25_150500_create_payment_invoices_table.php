<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payment_invoices', function (Blueprint $table) {
            $table->id();
            $table->foreignId('payment_id')->constrained('payments')->cascadeOnDelete();
            $table->string('invoice_number', 64)->unique();
            $table->string('fy_label', 16)->index();
            $table->unsignedInteger('sequence_no');
            $table->timestamps();

            $table->unique(['fy_label', 'sequence_no']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payment_invoices');
    }
};

