<?php

use App\Support\NotificationMarathiPayload;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('notifications')) {
            return;
        }

        DB::table('notifications')
            ->select(['id', 'data'])
            ->orderBy('id')
            ->chunk(200, function ($rows): void {
                foreach ($rows as $row) {
                $data = json_decode((string) $row->data, true);
                if (! is_array($data)) {
                    continue;
                }

                if (array_key_exists('message_mr', $data)) {
                    continue;
                }

                $updated = NotificationMarathiPayload::withMessage($data);
                if ($updated === $data) {
                    continue;
                }

                DB::table('notifications')
                    ->where('id', $row->id)
                    ->update([
                        'data' => json_encode($updated, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                    ]);
                }
            });
    }

    public function down(): void
    {
        if (! Schema::hasTable('notifications')) {
            return;
        }

        DB::table('notifications')
            ->select(['id', 'data'])
            ->orderBy('id')
            ->chunk(200, function ($rows): void {
                foreach ($rows as $row) {
                    $data = json_decode((string) $row->data, true);
                    if (! is_array($data) || ! array_key_exists('message_mr', $data)) {
                        continue;
                    }

                    unset($data['message_mr']);

                    DB::table('notifications')
                        ->where('id', $row->id)
                        ->update([
                            'data' => json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                        ]);
                }
            });
    }
};
