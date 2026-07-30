<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Lets one verification hold several files.
 *
 * An identity proof is rarely one image — an Aadhaar has two sides, a PAN may
 * be photographed separately, and a Suchak sending the second one used to
 * silently erase the first because the record kept a single path. The admin
 * decision stays on the record, because what gets approved is "this Suchak's
 * identity", not each page of it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('suchak_verification_documents', function (Blueprint $table) {
            $table->id();
            // Named by hand: the generated name for these two long identifiers
            // runs past MySQL's 64-character limit for constraints.
            $table->foreignId('suchak_verification_record_id');
            $table->foreign('suchak_verification_record_id', 'suchak_ver_docs_record_fk')
                ->references('id')
                ->on('suchak_verification_records')
                ->cascadeOnDelete();
            $table->string('document_path');
            // What the Suchak's phone called it. An admin looking at three
            // unlabelled scans has nothing to go on otherwise.
            $table->string('original_name', 255)->nullable();
            $table->string('mime_type', 128)->nullable();
            $table->unsignedInteger('size_bytes')->nullable();
            $table->timestamps();

            $table->index(['suchak_verification_record_id', 'id'], 'suchak_ver_docs_record_idx');
        });

        // Carry across what the single-path column already holds, so records
        // uploaded before this migration keep their document instead of
        // appearing empty the moment the app starts reading the new table.
        DB::table('suchak_verification_records')
            ->whereNotNull('document_path')
            ->where('document_path', '!=', '')
            ->orderBy('id')
            ->chunkById(200, function ($records) {
                $rows = [];
                foreach ($records as $record) {
                    $rows[] = [
                        'suchak_verification_record_id' => $record->id,
                        'document_path' => $record->document_path,
                        'original_name' => null,
                        'mime_type' => null,
                        'size_bytes' => null,
                        'created_at' => $record->updated_at ?? now(),
                        'updated_at' => $record->updated_at ?? now(),
                    ];
                }
                if ($rows !== []) {
                    DB::table('suchak_verification_documents')->insert($rows);
                }
            });
    }

    public function down(): void
    {
        Schema::dropIfExists('suchak_verification_documents');
    }
};
