<?php

namespace App\Services\Chat;

use App\Models\MatrimonyProfile;

/**
 * Deterministic, matrimony-safe conversation starters (no DB, no AI).
 */
class ChatTemplateSuggestionService
{
    /**
     * @return array<string, array{label: string, items: list<string>}>
     */
    public function getSuggestionGroupsForConversation(MatrimonyProfile $other): array
    {
        return [
            'identity' => [
                'label' => 'ओळख',
                'items' => $this->buildIntroductionSuggestions($other),
            ],
            'family' => [
                'label' => 'कुटुंब',
                'items' => $this->buildFamilySuggestions($other),
            ],
            'career' => [
                'label' => 'करिअर',
                'items' => $this->buildCareerSuggestions($other),
            ],
            'more' => [
                'label' => 'आणखी',
                'items' => $this->buildFallbackSuggestions(),
            ],
        ];
    }

    /**
     * @return list<string>
     */
    public function buildIntroductionSuggestions(MatrimonyProfile $other): array
    {
        $items = [];

        $name = trim((string) ($other->full_name ?? ''));
        if ($name !== '') {
            $first = preg_split('/\s+/u', $name, 2)[0] ?? $name;
            $items[] = 'नमस्कार. '.$first.', तुमच्याशी ओळख वाढवायला आवडेल.';
        }

        $items[] = 'नमस्कार. तुमचा प्रोफाइल आवडला. तुमच्याबद्दल थोडंसं सांगाल का?';
        $items[] = 'नमस्कार. बोलण्याची सुरुवात म्हणून स्वतःबद्दल थोडक्यात सांगाल का?';

        if (count($items) < 3) {
            $items[] = 'तुमचा प्रोफाइल साधा आणि स्पष्ट वाटला. थोडंसं अधिक सांगाल का?';
        }

        return array_slice(array_values(array_unique($items)), 0, 3);
    }

    /**
     * @return list<string>
     */
    public function buildFamilySuggestions(MatrimonyProfile $other): array
    {
        return [
            'तुमच्या कुटुंबाबद्दल थोडक्यात सांगाल का?',
            'सध्या तुम्ही कुटुंबासोबत राहता का?',
            'तुमच्या family background बद्दल थोडंसं जाणून घ्यायला आवडेल.',
        ];
    }

    /**
     * @return list<string>
     */
    public function buildCareerSuggestions(MatrimonyProfile $other): array
    {
        $items = [];

        $occ = trim((string) ($other->occupation_title ?? ''));
        if ($occ !== '') {
            $items[] = 'तुमच्या कामाच्या स्वरूपाबद्दल थोडंसं सांगाल का? (प्रोफाइल: '.$occ.')';
        }

        $edu = trim((string) ($other->highest_education ?? ''));
        if ($edu !== '' && mb_strlen($edu) < 120) {
            $items[] = 'तुमच्या शिक्षण आणि करिअर दिशेनेबद्दल थोडंसं सांगाल का? (प्रोफाइल: '.$edu.')';
        }

        $loc = trim($other->residenceLocationDisplayLine());
        if ($loc !== '') {
            $first = trim(explode(',', $loc, 2)[0]);
            if ($first !== '') {
                $items[] = 'तुमच्या सध्या राहण्याच्या ठिकाणाबद्दल थोडंसं सांगाल का? ('.$first.')';
            }
        }

        $items = array_values(array_unique($items));
        $generic = [
            'तुमच्या कामाच्या स्वरूपाबद्दल सांगाल का?',
            'तुमची career direction आणि future plans याबद्दल थोडंसं सांगाल का?',
            'तुम्ही सध्या कोणत्या भागात / शहरात काम करता याबद्दल थोडंसं सांगाल का?',
        ];
        foreach ($generic as $g) {
            if (count($items) >= 3) {
                break;
            }
            if (! in_array($g, $items, true)) {
                $items[] = $g;
            }
        }

        return array_slice($items, 0, 3);
    }

    /**
     * @return list<string>
     */
    public function buildFallbackSuggestions(): array
    {
        return [
            'तुमच्या आवडी-निवडींबद्दल थोडंसं सांगाल का?',
            'लग्नाबद्दल तुमचा दृष्टिकोन आणि अपेक्षा याबद्दल थोडंसं सांगाल का?',
            'तुमच्यासाठी जोडीदारात कोणत्या गोष्टी महत्त्वाच्या आहेत याबद्दल सांगाल का?',
        ];
    }
}
