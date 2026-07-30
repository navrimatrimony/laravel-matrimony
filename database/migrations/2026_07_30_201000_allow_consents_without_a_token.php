<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Lets a consent exist without a token.
 *
 * Every consent until now was something the candidate had to reach — a link to
 * open, an OTP to answer — so a token was always minted. A Suchak's declaration
 * that the candidate agreed in person has nothing for anyone to open, and
 * storing a random hash there would leave a row that looks like an unanswered
 * request forever.
 *
 * Widening only: existing rows keep their tokens.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('suchak_consents', function (Blueprint $table) {
            $table->string('token_hash', 128)->nullable()->change();
            $table->timestamp('token_expires_at')->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('suchak_consents', function (Blueprint $table) {
            $table->string('token_hash', 128)->nullable(false)->change();
            $table->timestamp('token_expires_at')->nullable(false)->change();
        });
    }
};
