<?php

/**
 * Push notification copy (Marathi) — mirrors lang/en/push.php key for key.
 *
 * FROZEN RULE: every numeral here is Latin 0-9, never Devanagari (०-९). That
 * covers the quiet-hours times (":start", ":end" arrive pre-formatted as HH:MM)
 * and any count or duration added later.
 *
 * `body` is the generic fallback — used when the server knows nothing about the
 * person behind the event. `body_named` / `body_named_preview` are used instead
 * when the receiver's plan has ALREADY revealed that person, so their name may be
 * spoken. A locked row never reaches these keys: its body is the teaser line the
 * WhoViewedTeaserPresenter produced under the admin's privacy policy. See
 * App\Services\Push\PushTeaserCopyService.
 */
return [

    'groups' => [
        'interest' => 'स्वारस्य',
        'chat' => 'संदेश',
        'contact' => 'संपर्क विनंत्या',
        'profile' => 'माझे प्रोफाइल',
        'engagement' => 'सूचना व स्मरणपत्रे',
        'account' => 'खाते व प्लॅन',
    ],

    'quiet_hours' => [
        'label' => 'शांत वेळ',
        'description' => ':start ते :end या वेळेत push सूचना पाठवल्या जात नाहीत. त्या अ‍ॅपमध्ये तुम्हाला दिसतीलच.',
    ],

    'types' => [

        'new_interest' => [
            'label' => 'नवीन स्वारस्य आले',
            'description' => 'कोणी तुम्हाला स्वारस्य पाठवल्यावर.',
            'title' => 'नवीन स्वारस्य',
            'body' => 'कोणीतरी तुमच्या प्रोफाइलमध्ये स्वारस्य दाखवले आहे.',
            'body_named' => ':name यांनी तुम्हाला स्वारस्य पाठवले आहे.',
        ],
        'interest_accepted' => [
            'label' => 'स्वारस्य स्वीकारले',
            'description' => 'तुम्ही पाठवलेले स्वारस्य स्वीकारल्यावर.',
            'title' => 'स्वारस्य स्वीकारले',
            'body' => 'तुमचे स्वारस्य स्वीकारले गेले आहे.',
            'body_named' => ':name यांनी तुमचे स्वारस्य स्वीकारले आहे.',
        ],
        'interest_rejected' => [
            'label' => 'स्वारस्य नाकारले',
            'description' => 'तुम्ही पाठवलेले स्वारस्य नाकारल्यावर.',
            'title' => 'स्वारस्य नाकारले',
            'body' => 'तुमचे स्वारस्य स्वीकारले गेले नाही.',
        ],

        'new_chat_message' => [
            'label' => 'नवीन संदेश',
            'description' => 'नवीन चॅट संदेश आल्यावर.',
            'title' => 'नवीन संदेश',
            'body' => 'तुम्हाला नवीन संदेश आला आहे.',
            'body_named' => ':name यांनी तुम्हाला संदेश पाठवला आहे.',
            'body_named_preview' => ':name: :preview',
        ],
        'chat_message_locked' => [
            'label' => 'संदेश लॉक आहे',
            'description' => 'संदेश उघडण्यासाठी सक्रिय प्लॅन आवश्यक असल्यावर.',
            'title' => 'संदेश प्रतीक्षेत आहे',
            'body' => 'तुमच्यासाठी संदेश आला आहे. तो उघडण्यासाठी सक्रिय प्लॅन आवश्यक आहे.',
        ],

        'contact_request_received' => [
            'label' => 'संपर्क विनंती आली',
            'description' => 'कोणी तुमचा संपर्क मागितल्यावर.',
            'title' => 'संपर्क विनंती',
            'body' => 'कोणीतरी तुमचा संपर्क क्रमांक मागितला आहे.',
        ],
        'contact_request_accepted' => [
            'label' => 'संपर्क विनंती मंजूर',
            'description' => 'तुमची संपर्क विनंती मंजूर झाल्यावर.',
            'title' => 'संपर्क विनंती मंजूर',
            'body' => 'तुमची संपर्क विनंती मंजूर झाली आहे.',
        ],
        'contact_request_rejected' => [
            'label' => 'संपर्क विनंती नाकारली',
            'description' => 'तुमची संपर्क विनंती नाकारल्यावर.',
            'title' => 'संपर्क विनंती नाकारली',
            'body' => 'तुमची संपर्क विनंती मंजूर झाली नाही.',
        ],
        'contact_request_expired' => [
            'label' => 'संपर्क विनंतीची मुदत संपली',
            'description' => 'उत्तराविना संपर्क विनंतीची मुदत संपल्यावर.',
            'title' => 'संपर्क विनंतीची मुदत संपली',
            'body' => 'एका संपर्क विनंतीची मुदत संपली आहे.',
        ],
        'contact_grant_revoked' => [
            'label' => 'संपर्क प्रवेश रद्द',
            'description' => 'दिलेला संपर्क प्रवेश कोणी मागे घेतल्यावर.',
            'title' => 'संपर्क प्रवेश रद्द',
            'body' => 'संपर्क प्रवेश मागे घेण्यात आला आहे.',
        ],

        // मध्यस्थी = दोन सदस्यांमधील सहाय्यित संपर्क विनंती. ही सूचक profile-request
        // पाइपलाइन नाही — तशी शब्दरचना करू नये.
        'mediation_request_received' => [
            'label' => 'मध्यस्थी विनंती आली',
            'description' => 'सहाय्यित विनंती तुमच्यापर्यंत आल्यावर.',
            'title' => 'नवीन विनंती',
            'body' => 'तुमच्यासाठी नवीन विनंती आली आहे.',
        ],
        'mediation_request_response' => [
            'label' => 'मध्यस्थी विनंतीला उत्तर',
            'description' => 'तुमच्या सहाय्यित विनंतीला उत्तर आल्यावर.',
            'title' => 'विनंतीला उत्तर',
            'body' => 'तुमच्या विनंतीला उत्तर आले आहे.',
        ],

        'photo_approved' => [
            'label' => 'फोटो मंजूर',
            'description' => 'तुमचा फोटो मंजूर झाल्यावर.',
            'title' => 'फोटो मंजूर',
            'body' => 'तुमचा फोटो मंजूर झाला आहे.',
        ],
        'photo_rejected' => [
            'label' => 'फोटो मंजूर झाला नाही',
            'description' => 'तुमचा फोटो मंजूर न झाल्यावर.',
            'title' => 'फोटो मंजूर झाला नाही',
            'body' => 'तुमचा फोटो मंजूर झाला नाही. कृपया दुसरा फोटो अपलोड करा.',
        ],

        'profile_viewed' => [
            'label' => 'प्रोफाइल पाहिले',
            'description' => 'कोणी तुमचे प्रोफाइल पाहिल्यावर.',
            'title' => 'प्रोफाइल पाहिले',
            'body' => 'कोणीतरी तुमचे प्रोफाइल पाहिले आहे.',
            'body_named' => ':name यांनी तुमचे प्रोफाइल पाहिले आहे.',
        ],
        'new_matches' => [
            'label' => 'नवीन जुळणी',
            'description' => 'तुमच्यासाठी नवीन जुळणींचा दैनिक सारांश.',
            'title' => 'नवीन जुळणी',
            'body' => 'तुमच्यासाठी नवीन जुळणी उपलब्ध आहेत.',
        ],
        'inactive_reminder' => [
            'label' => 'स्मरणपत्रे',
            'description' => 'बरेच दिवस अ‍ॅप न उघडल्यास स्मरणपत्र.',
            'title' => 'नवीन प्रोफाइल वाट पाहत आहेत',
            'body' => 'तुमच्या मागील भेटीनंतर नवीन प्रोफाइल जोडली गेली आहेत.',
        ],

        'plan_expiring' => [
            'label' => 'प्लॅनची मुदत संपत आहे',
            'description' => 'तुमच्या प्लॅनची मुदत संपण्यापूर्वी.',
            'title' => 'प्लॅनची मुदत लवकरच संपत आहे',
            'body' => 'तुमच्या प्लॅनची मुदत लवकरच संपत आहे.',
        ],
        'profile_suspended' => [
            'label' => 'प्रोफाइल स्थगित',
            'description' => 'तुमचे प्रोफाइल स्थगित झाल्यावर.',
            'title' => 'प्रोफाइल स्थगित',
            'body' => 'तुमचे प्रोफाइल स्थगित करण्यात आले आहे.',
        ],
        'profile_unsuspended' => [
            'label' => 'प्रोफाइल पुन्हा सुरू',
            'description' => 'तुमचे प्रोफाइल पुन्हा सुरू झाल्यावर.',
            'title' => 'प्रोफाइल पुन्हा सुरू',
            'body' => 'तुमचे प्रोफाइल पुन्हा सक्रिय झाले आहे.',
        ],
        'profile_soft_deleted' => [
            'label' => 'प्रोफाइल काढले',
            'description' => 'तुमचे प्रोफाइल काढले गेल्यावर.',
            'title' => 'प्रोफाइल काढले',
            'body' => 'तुमचे प्रोफाइल काढण्यात आले आहे.',
        ],
        'password_changed' => [
            'label' => 'पासवर्ड बदलला',
            'description' => 'तुमच्या खात्याचा पासवर्ड बदलल्यावर.',
            'title' => 'पासवर्ड बदलला',
            'body' => 'तुमच्या खात्याचा पासवर्ड बदलण्यात आला. हे तुम्ही केले नसेल तर लगेच आमच्याशी संपर्क साधा.',
        ],
        'referral_activity' => [
            'label' => 'रेफरल अपडेट',
            'description' => 'तुम्ही आमंत्रित केलेली व्यक्ती सामील झाल्यावर.',
            'title' => 'रेफरल अपडेट',
            'body' => 'तुम्ही आमंत्रित केलेल्या व्यक्तीबद्दल अपडेट आहे.',
        ],
        'referral_reward' => [
            'label' => 'रेफरल बक्षीस',
            'description' => 'रेफरल बक्षीस मिळाल्यावर.',
            'title' => 'रेफरल बक्षीस',
            'body' => 'तुम्हाला रेफरल बक्षीस मिळाले आहे.',
        ],
        'suchak_customer_deletion_requested' => [
            'label' => 'ग्राहक जात आहे',
            'description' => 'तुम्ही प्रतिनिधित्व करत असलेल्या ग्राहकाने खाते हटवण्याची विनंती केली.',
            'title' => 'ग्राहक जात आहे',
            'body' => ':customer_full_name यांनी :event_date रोजी खाते हटवण्याची विनंती केली.',
        ],
        'suchak_customer_deletion_cancelled' => [
            'label' => 'ग्राहक राहिला',
            'description' => 'तुम्ही प्रतिनिधित्व करत असलेल्या ग्राहकाने खाते हटवण्याची विनंती रद्द केली.',
            'title' => 'ग्राहक राहिला',
            'body' => ':customer_full_name यांनी :event_date रोजी खाते हटवण्याची विनंती रद्द केली.',
        ],
        'dispute_party_deletion_requested' => [
            'label' => 'Dispute पक्ष जात आहे',
            'description' => 'खुल्या dispute मधील सदस्याने खाते हटवण्याची विनंती केली.',
            'title' => 'Dispute पक्ष जात आहे',
            'body' => ':customer_full_name यांनी :event_date रोजी हटवण्याची विनंती केली (:open_dispute_count खुले dispute).',
        ],

    ],

];
