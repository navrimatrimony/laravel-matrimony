<?php

namespace App\Services\Image;

class AiModerationService
{
    public function __construct(
        private readonly \App\Services\Image\Providers\OpenAiModerationProvider $openAi,
        private readonly \App\Services\Image\Providers\SarvamModerationProvider $sarvam,
    ) {}

    /**
     * @return array{approved:bool,reason:?string,raw:array}
     */
    public function moderate(string $imagePath, string $provider): array
    {
        $provider = strtolower(trim($provider));
        if ($provider === 'openai') {
            return $this->openAi->moderate($imagePath);
        }
        if ($provider === 'sarvam') {
            return $this->sarvam->moderate($imagePath);
        }

        return [
            'approved' => false,
            'reason' => 'AI moderation provider not configured.',
            'raw' => ['provider' => $provider],
        ];
    }
}
