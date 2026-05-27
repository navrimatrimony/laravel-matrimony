<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::dropIfExists('master_legal_case_types');
    }

    public function down(): void
    {
        Schema::dropIfExists('master_legal_case_types');
    }
};
