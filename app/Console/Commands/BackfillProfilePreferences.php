<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class BackfillProfilePreferences extends Command
{
    protected $signature = 'backfill:preferences';
    protected $description = 'Backfill profile preferences into pivot tables';

    public function handle()
    {
        DB::transaction(function () {

            $preferences = DB::table('profile_preferences')->get();

            foreach ($preferences as $pref) {

                // ========================
                // CASTE PREFERENCE
                // ========================
                if ($pref->preferred_caste) {

                    $casteKey = Str::slug(strtolower(trim($pref->preferred_caste)));

                    $caste = DB::table('castes')
                        ->where('key', $casteKey)
                        ->first();

                    if ($caste) {
                        DB::table('profile_preferred_castes')->insert([
                            'profile_id' => $pref->profile_id,
                            'caste_id' => $caste->id,
                            'created_at' => now(),
                            'updated_at' => now(),
                        ]);
                    }
                }

                // ========================
                // CITY → DISTRICT PREFERENCE
                // ========================
                if ($pref->preferred_city) {

                    $city = DB::table('cities')
                        ->whereRaw('LOWER(name) = ?', [strtolower(trim($pref->preferred_city))])
                        ->first();

                    if ($city) {

                        $taluka = DB::table('talukas')
                            ->where('id', $city->taluka_id)
                            ->first();

                        if ($taluka) {

                            DB::table('profile_preferred_districts')->insert([
                                'profile_id' => $pref->profile_id,
                                'district_id' => $taluka->district_id,
                                'created_at' => now(),
                                'updated_at' => now(),
                            ]);
                        }
                    }
                }
            }
        });

        $this->info('Preference backfill completed.');
    }
}
