<?php

use App\Support\Migrations\LocationSuggestionsFkAligner;
use Illuminate\Database\Migrations\Migration;

/**
 * @see LocationSuggestionsFkAligner Aligns all hierarchy FKs to `addresses` (not duplicate migrations per column).
 */
return new class extends Migration
{
    public function up(): void
    {
        LocationSuggestionsFkAligner::alignToAddresses();
    }

    public function down(): void
    {
        // Intentionally empty: do not restore FKs to deprecated geo tables.
    }
};
