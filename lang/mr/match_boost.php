<?php

/**
 * Match boost admin screen (Marathi).
 * Falls back to en/match_boost.php for any key added later there.
 */
return array_merge(require __DIR__.'/../en/match_boost.php', [
    'title' => 'जुळणी बूस्ट',
    'intro' => 'नियमांवर आधारित बूस्ट, प्रीमियम स्तराचे अतिरिक्त गुण आणि ऐच्छिक Sarvam AI गुण इथे ठरवा. बदल नव्या जुळणी गणनेला लागू होतात; जोडीचे निकाल थोडा वेळ कॅशमध्ये राहतात.',
    'saved' => 'जुळणी बूस्ट सेटिंग्ज जतन झाली.',
    'api_note_title' => 'Sarvam API की',
    'api_note_body' => 'तुमची subscription key .env मध्ये ठेवा. की रिकामी असेल किंवा request अयशस्वी झाली तर AI वगळले जाते.',
    'ai_section' => 'AI बूस्ट (ऐच्छिक)',
    'use_ai' => 'AI सुसंगतता गुण सुरू करा (Sarvam)',
    'ai_provider' => 'प्रोव्हायडर',
    'ai_provider_none' => 'काहीही नाही',
    'ai_model' => 'मॉडेलचे नाव',
    'boost_active_weight' => 'सक्रिय सदस्याचा बूस्ट (उमेदवार)',
    'active_within_days' => 'इतक्या दिवसांत शेवटचे दिसल्यास सक्रिय (दिवस)',
    'hint_active' => 'जुळलेल्या प्रोफाइलचे खाते अलीकडे सक्रिय असल्यास हा बूस्ट जोडला जातो.',
    'boost_premium_weight' => 'पेड प्लॅनचा बूस्ट (सर्व सशुल्क स्तर)',
    'hint_premium' => 'Basic, Silver आणि Gold या लागू प्लॅनसाठी वापरला जातो.',
    'boost_similarity_weight' => 'साम्याचा बूस्ट',
    'hint_similarity' => 'प्रत्येक जोडीसाठी एकदाच: एकच व्यवसाय, एकच शहर किंवा एकच राज्य.',
    'boost_gold_extra' => 'Gold स्तराचे अतिरिक्त गुण',
    'boost_silver_extra' => 'Silver स्तराचे अतिरिक्त गुण',
    'max_boost_limit' => 'एकूण बूस्ट गुणांची कमाल मर्यादा',
    'hint_max' => 'मूळ गुणांमध्ये जोडल्या जाणाऱ्या नियम + AI बूस्टच्या बेरजेवर मर्यादा (अंतिम जुळणी गुण कधीही 100 पेक्षा जास्त होत नाहीत).',
    'save' => 'सेटिंग्ज जतन करा',
    'nav' => 'जुळणी बूस्ट',
    'only_sarvam' => 'AI जुळणी बूस्टसाठी फक्त Sarvam समर्थित आहे.',
]);
