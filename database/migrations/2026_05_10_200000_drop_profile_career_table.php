<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;

/**
 * Product path no longer stores multi-row career history; work location lives on CORE (work_city_id / work_state_id / work_location_text).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::dropIfExists('profile_career');
    }

    public function down(): void
    {
        // Intentionally empty: restoring dropped rows is not supported.
    }
};
