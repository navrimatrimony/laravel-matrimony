<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class BackfillReligionCasteData extends Command
{
    protected $signature = 'backfill:religion-caste';
    protected $description = 'Backfill religion, caste, sub_caste into master tables and update FK columns';

    public function handle()
{
    DB::transaction(function () {

        $profiles = DB::table('matrimony_profiles')->get();

        foreach ($profiles as $profile) {

            // ======================
            // RELIGION
            // ======================
            if ($profile->religion) {

                $religionKey = Str::slug(strtolower(trim($profile->religion)));

                $religion = DB::table('religions')
                    ->where('key', $religionKey)
                    ->first();

                if (!$religion) {
                    $religionId = DB::table('religions')->insertGetId([
                        'key' => $religionKey,
                        'label' => ucfirst(trim($profile->religion)),
                        'is_active' => true,
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]);
                } else {
                    $religionId = $religion->id;
                }

                DB::table('matrimony_profiles')
                    ->where('id', $profile->id)
                    ->update(['religion_id' => $religionId]);
            }

            // ======================
            // CASTE
            // ======================
            if ($profile->caste) {

                $casteKey = Str::slug(strtolower(trim($profile->caste)));

                $caste = DB::table('castes')
                    ->where('key', $casteKey)
                    ->first();

                if (!$caste) {
                    $casteId = DB::table('castes')->insertGetId([
                        'religion_id' => null,
                        'key' => $casteKey,
                        'label' => ucfirst(trim($profile->caste)),
                        'is_active' => true,
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]);
                } else {
                    $casteId = $caste->id;
                }

                DB::table('matrimony_profiles')
                    ->where('id', $profile->id)
                    ->update(['caste_id' => $casteId]);
            }

            // REFRESH PROFILE
            $updatedProfile = DB::table('matrimony_profiles')
                ->where('id', $profile->id)
                ->first();

            // ======================
            // SUB CASTE
            // ======================
            if ($profile->sub_caste && $updatedProfile->caste_id) {

                $subKey = Str::slug(strtolower(trim($profile->sub_caste)));

                $subCaste = DB::table('sub_castes')
                    ->where('caste_id', $updatedProfile->caste_id)
                    ->where('key', $subKey)
                    ->first();

                if (!$subCaste) {
                    $subId = DB::table('sub_castes')->insertGetId([
                        'caste_id' => $updatedProfile->caste_id,
                        'key' => $subKey,
                        'label' => ucfirst(trim($profile->sub_caste)),
                        'is_active' => true,
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]);
                } else {
                    $subId = $subCaste->id;
                }

                DB::table('matrimony_profiles')
                    ->where('id', $profile->id)
                    ->update(['sub_caste_id' => $subId]);
            }
        }
    });

    $this->info('Backfill completed successfully.');
}
}