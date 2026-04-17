<?php

namespace App\Services\HelpCentre;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class HelpCentreAiService
{
    public function isEnabled(): bool
    {
        return (bool) config('help_centre.ai.enabled', false)
            && is_string(config('services.openai.key'))
            && trim((string) config('services.openai.key')) !== '';
    }

    public function generateReply(string $userMessage): ?string
    {
        if (! $this->isEnabled()) {
            return null;
        }

        $url = (string) config('services.openai.url', 'https://api.openai.com/v1/chat/completions');
        $model = (string) config('help_centre.ai.model', config('services.openai.model', 'gpt-4o-mini'));
        $timeout = (int) config('help_centre.ai.timeout', 12);
        $key = (string) config('services.openai.key');

        $systemPrompt = 'You are Help centre assistant for a matrimony app. '
            .'Never reveal personal data (phone, email, address, private chats, payment secrets). '
            .'Give safe, concise guidance in plain user-friendly language.';

        try {
            $resp = Http::withToken($key)
                ->timeout($timeout)
                ->acceptJson()
                ->post($url, [
                    'model' => $model,
                    'temperature' => 0.2,
                    'messages' => [
                        ['role' => 'system', 'content' => $systemPrompt],
                        ['role' => 'user', 'content' => $userMessage],
                    ],
                ]);

            if (! $resp->ok()) {
                Log::warning('help_centre_ai_http_failed', [
                    'status' => $resp->status(),
                ]);

                return null;
            }

            $reply = (string) data_get($resp->json(), 'choices.0.message.content', '');
            $reply = trim($reply);

            return $reply !== '' ? $reply : null;
        } catch (\Throwable $e) {
            Log::warning('help_centre_ai_exception', [
                'error' => $e->getMessage(),
            ]);

            return null;
        }
    }
}
