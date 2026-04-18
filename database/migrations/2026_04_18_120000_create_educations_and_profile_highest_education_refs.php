<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Canonical master list for highest education + profile FK refs.
 * Keeps legacy {@code matrimony_profiles.highest_education} string for backward compatibility.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('educations')) {
            Schema::create('educations', function (Blueprint $table) {
                $table->id();
                $table->string('name', 191);
                $table->string('slug', 191);
                $table->timestamps();
                $table->unique('slug');
            });
        }

        if (Schema::hasTable('education_degrees') && Schema::hasTable('educations') && ! DB::table('educations')->exists()) {
            $degrees = DB::table('education_degrees')
                ->orderBy('id')
                ->get(['id', 'title', 'code']);

            $usedSlugs = [];
            foreach ($degrees as $row) {
                $name = trim((string) ($row->title ?? ''));
                if ($name === '') {
                    $name = trim((string) ($row->code ?? ''));
                }
                if ($name === '') {
                    continue;
                }
                $base = self::migrationNormalizeSlug($name);
                if ($base === '') {
                    $base = 'edu'.$row->id;
                }
                $slug = $base;
                $n = 1;
                while (isset($usedSlugs[$slug])) {
                    $slug = $base.'-'.$n;
                    $n++;
                }
                $usedSlugs[$slug] = true;

                DB::table('educations')->insert([
                    'name' => mb_substr($name, 0, 191),
                    'slug' => mb_substr($slug, 0, 191),
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }
        }

        if (Schema::hasTable('matrimony_profiles')) {
            Schema::table('matrimony_profiles', function (Blueprint $table) {
                if (! Schema::hasColumn('matrimony_profiles', 'highest_education_id')) {
                    $table->unsignedBigInteger('highest_education_id')->nullable()->after('highest_education');
                }
                if (! Schema::hasColumn('matrimony_profiles', 'highest_education_text')) {
                    $table->string('highest_education_text')->nullable()->after('highest_education_id');
                }
            });

            if (Schema::hasColumn('matrimony_profiles', 'highest_education_id')) {
                try {
                    Schema::table('matrimony_profiles', function (Blueprint $table) {
                        $table->foreign('highest_education_id')->references('id')->on('educations')->nullOnDelete();
                    });
                } catch (\Throwable $e) {
                    // FK may already exist on re-run
                }
            }

            if (Schema::hasTable('education_degrees')) {
                $profiles = DB::table('matrimony_profiles')
                    ->whereNotNull('highest_education')
                    ->where('highest_education', '!=', '')
                    ->whereNull('highest_education_id')
                    ->get(['id', 'highest_education']);

                foreach ($profiles as $p) {
                    $raw = trim((string) $p->highest_education);
                    $deg = DB::table('education_degrees')->where('code', $raw)->first();
                    if (! $deg) {
                        $deg = DB::table('education_degrees')
                            ->whereRaw('LOWER(TRIM(title)) = ?', [mb_strtolower($raw)])
                            ->first();
                    }

                    $eduId = null;
                    if ($deg) {
                        $title = trim((string) ($deg->title ?? ''));
                        if ($title !== '') {
                            $eduId = DB::table('educations')
                                ->whereRaw('LOWER(TRIM(name)) = ?', [mb_strtolower($title)])
                                ->value('id');
                        }
                        if ($eduId === null && $title !== '') {
                            $eduId = DB::table('educations')
                                ->where('slug', self::migrationNormalizeSlug($title))
                                ->value('id');
                        }
                    }

                    if ($eduId !== null) {
                        DB::table('matrimony_profiles')->where('id', $p->id)->update([
                            'highest_education_id' => $eduId,
                            'highest_education_text' => null,
                        ]);
                    } else {
                        DB::table('matrimony_profiles')->where('id', $p->id)->update([
                            'highest_education_id' => null,
                            'highest_education_text' => mb_substr($raw, 0, 255),
                        ]);
                    }
                }
            }
        }
    }

    private static function migrationNormalizeSlug(string $input): string
    {
        $s = mb_strtolower(trim($input));
        $s = str_replace(['.', "\xc2\xa0"], '', $s);
        $s = preg_replace('/\s+/u', '', $s);
        $s = preg_replace('/[^a-z0-9\x{0900}-\x{097F}]+/u', '', $s);

        return $s;
    }

    public function down(): void
    {
        if (Schema::hasTable('matrimony_profiles')) {
            if (Schema::hasColumn('matrimony_profiles', 'highest_education_id')) {
                try {
                    Schema::table('matrimony_profiles', function (Blueprint $table) {
                        $table->dropForeign(['highest_education_id']);
                    });
                } catch (\Throwable $e) {
                }
            }
            Schema::table('matrimony_profiles', function (Blueprint $table) {
                if (Schema::hasColumn('matrimony_profiles', 'highest_education_text')) {
                    $table->dropColumn('highest_education_text');
                }
                if (Schema::hasColumn('matrimony_profiles', 'highest_education_id')) {
                    $table->dropColumn('highest_education_id');
                }
            });
        }

        Schema::dropIfExists('educations');
    }
};
