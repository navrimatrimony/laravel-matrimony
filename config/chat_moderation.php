<?php

/**
 * Deterministic chat text moderation (keyword / pattern lists only).
 * No external services; no persisted scores on messages.
 */
return [
    'block_score_threshold' => 8,
    'warn_score_threshold' => 4,

    'user_messages' => [
        'block' => 'हा संदेश पाठवता येणार नाही. कृपया आदराने आणि सुरक्षित भाषेत लिहा.',
        'warn' => 'काही शब्द नियमांशी जुळत नाहीत. कृपया संदेश बदलून पुन्हा पाठवा.',
    ],

    'mask_placeholder' => 'हा संदेश उपलब्ध नाही.',

    'admin_badge_label' => 'Filtered',

    /**
     * Patterns matched against normalized text (and compact Latin where noted).
     * Use conservative, high-signal phrases; extend via additive edits only.
     */
    'patterns' => [
        'sexual_explicit' => [
            'severe' => [
                '/\b(send\s+nudes?|nude\s+pic|sex\s*chat|sexting|phone\s*sex|dirty\s*talk)\b/u',
                '/\b(fuck\s+me|want\s+to\s+fuck|lets\s+fuck)\b/u',
                '/\b(naked\s+photo|intimate\s+photo|explicit\s+photo)\b/u',
                '/(नग्न|अश्लील\s*चित्र|सेक्स\s*चॅट)/u',
            ],
            'medium' => [
                '/\b(turn\s+me\s+on|make\s+me\s+wet|horny)\b/u',
            ],
        ],
        'solicitation_photo_video' => [
            'severe' => [
                '/\b(send\s+(your\s+)?nudes?|show\s+me\s+your\s+body)\b/u',
            ],
            'medium' => [
                '/\b(send\s+(me\s+)?(a\s+)?photo\s+now|video\s+call\s+now|send\s+pics\s+now)\b/u',
                '/\b(whatsapp\s+me\s+your\s+pics|telegram\s+nudes)\b/u',
            ],
        ],
        'hate_caste_religion' => [
            'severe' => [
                '/\b(your\s+caste\s+is\s+(dirty|inferior|low))\b/u',
                '/(जात\s*वर\s*टीका|धर्म\s*विरोधी\s*द्वेष)/u',
                '/\b(kill\s+all\s+(muslims|hindus|christians))\b/u',
            ],
            'medium' => [
                '/\b(you\s+people\s+are\s+all\s+the\s+same)\b/u',
            ],
        ],
        'threat_blackmail' => [
            'severe' => [
                '/\b(i\s+will\s+kill|i\s*ll\s+hurt\s+you|blackmail|pay\s+or\s+i\s+ll\s+expose)\b/u',
                '/\b(expose\s+your\s+photos|leak\s+your)\b/u',
            ],
            'medium' => [
                '/\b(or\s+else\s+you\s+ll\s+regret)\b/u',
            ],
        ],
        'harassment_pressure' => [
            'severe' => [
                '/\b(i\s+am\s+watching\s+you|i\s+know\s+where\s+you\s+live)\b/u',
            ],
            'medium' => [
                '/\b(reply\s+or\s+i\s+ll\s+keep\s+messaging|why\s+are\s+you\s+ignoring\s+me\s+bitch)\b/u',
            ],
        ],
        'minors_inappropriate' => [
            'severe' => [
                '/\b(teen\s+sex|child\s+porn|minor\s+nude|underage\s+sex|school\s+girl\s+sex)\b/u',
            ],
            'medium' => [],
        ],
    ],
];
