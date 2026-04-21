<?php

namespace App\Services\System;

use Illuminate\Support\Facades\Http;

class AiHealthService
{
    public static function check(): array
    {
        $url = 'http://127.0.0.1:8001/health';

        try {
            $res = Http::timeout(2)->get($url);

            if ($res->ok()) {
                $json = $res->json();

                return [
                    'status' => 'up',
                    'model_loaded' => $json['model_loaded'] ?? false,
                ];
            }

            return ['status' => 'down'];
        } catch (\Throwable) {
            return ['status' => 'down'];
        }
    }
}
