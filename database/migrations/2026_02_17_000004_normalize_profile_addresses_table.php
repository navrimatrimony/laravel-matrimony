<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('profile_addresses', 'country')) {
            Schema::table('profile_addresses', function (Blueprint $table) {
                $table->dropColumn('country');
            });
        }
        if (Schema::hasColumn('profile_addresses', 'state')) {
            Schema::table('profile_addresses', function (Blueprint $table) {
                $table->dropColumn('state');
            });
        }
        if (Schema::hasColumn('profile_addresses', 'district')) {
            Schema::table('profile_addresses', function (Blueprint $table) {
                $table->dropColumn('district');
            });
        }
        if (Schema::hasColumn('profile_addresses', 'taluka')) {
            Schema::table('profile_addresses', function (Blueprint $table) {
                $table->dropColumn('taluka');
            });
        }
        if (Schema::hasColumn('profile_addresses', 'city')) {
            Schema::table('profile_addresses', function (Blueprint $table) {
                $table->dropColumn('city');
            });
        }
        if (Schema::hasColumn('profile_addresses', 'pin_code')) {
            Schema::table('profile_addresses', function (Blueprint $table) {
                $table->dropColumn('pin_code');
            });
        }

        Schema::table('profile_addresses', function (Blueprint $table) {
            $table->unsignedBigInteger('country_id')->nullable();
            $table->unsignedBigInteger('state_id')->nullable();
            $table->unsignedBigInteger('district_id')->nullable();
            $table->unsignedBigInteger('taluka_id')->nullable();
            $table->unsignedBigInteger('city_id')->nullable();
            $table->string('postal_code')->nullable();
        });

        Schema::table('profile_addresses', function (Blueprint $table) {
            $table->foreign('country_id')->references('id')->on('countries')->nullOnDelete();
            $table->foreign('state_id')->references('id')->on('states')->nullOnDelete();
            $table->foreign('district_id')->references('id')->on('districts')->nullOnDelete();
            $table->foreign('taluka_id')->references('id')->on('talukas')->nullOnDelete();
            $table->foreign('city_id')->references('id')->on('cities')->nullOnDelete();
        });
    }

    public function down(): void
    {
        if (Schema::hasColumn('profile_addresses', 'country_id')) {
            Schema::table('profile_addresses', function (Blueprint $table) {
                $table->dropForeign(['country_id']);
            });
        }
        if (Schema::hasColumn('profile_addresses', 'state_id')) {
            Schema::table('profile_addresses', function (Blueprint $table) {
                $table->dropForeign(['state_id']);
            });
        }
        if (Schema::hasColumn('profile_addresses', 'district_id')) {
            Schema::table('profile_addresses', function (Blueprint $table) {
                $table->dropForeign(['district_id']);
            });
        }
        if (Schema::hasColumn('profile_addresses', 'taluka_id')) {
            Schema::table('profile_addresses', function (Blueprint $table) {
                $table->dropForeign(['taluka_id']);
            });
        }
        if (Schema::hasColumn('profile_addresses', 'city_id')) {
            Schema::table('profile_addresses', function (Blueprint $table) {
                $table->dropForeign(['city_id']);
            });
        }

        Schema::table('profile_addresses', function (Blueprint $table) {
            $table->dropColumn(['country_id', 'state_id', 'district_id', 'taluka_id', 'city_id', 'postal_code']);
        });

        Schema::table('profile_addresses', function (Blueprint $table) {
            $table->string('country')->nullable();
            $table->string('state')->nullable();
            $table->string('district')->nullable();
            $table->string('taluka')->nullable();
            $table->string('city')->nullable();
            $table->string('pin_code')->nullable();
        });
    }
};
