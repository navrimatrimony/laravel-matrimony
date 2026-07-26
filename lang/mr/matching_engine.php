<?php

/**
 * Matching engine — admin console + score explanation lines (Marathi).
 * Falls back to en/matching_engine.php for any key added later there.
 */
return array_merge(require __DIR__.'/../en/matching_engine.php', [
    'nav_group' => 'जुळणी इंजिन',
    'nav_overview' => 'आढावा',
    'nav_fields' => 'घटक व गुण',
    'nav_filters' => 'कडक अटी',
    'nav_behavior' => 'वर्तन',
    'nav_boosts' => 'बूस्ट नियम',
    'nav_ai' => 'AI सूचना',
    'nav_preview' => 'थेट पूर्वदृश्य',
    'nav_audit' => 'बदलांची नोंद',

    'overview_title' => 'मध्यवर्ती जुळणी इंजिन',
    'overview_intro' => 'वजन, अटी, वर्तन सिग्नल आणि बूस्ट मर्यादा इथे ठरवा. प्रत्येक बदलाची आवृत्ती जतन होते; DB टेबल नसतील तेव्हाच जुनी config/matching.php वापरली जाते.',

    'saved' => 'जुळणी इंजिनची सेटिंग्ज जतन झाली.',
    'rolled_back' => 'निवडलेल्या स्नॅपशॉटमधून सेटिंग पुन्हा लागू केली.',
    'sum_weights_error' => 'सुरू असलेल्या घटकांच्या वजनांची बेरीज 1 ते 100 च्या दरम्यान असावी (सध्या: :sum).',

    // Score explanation lines shown with each match.
    'behavior_positive' => 'अलीकडील सक्रियतेमुळे वाढ (+:n)',
    'behavior_negative' => 'अलीकडे नाकारल्याचा/टाळल्याचा सिग्नल (−:n)',
    'boost_layer' => 'क्रमवारी बूस्ट थर (+:n)',
    'penalty_religion_preferred' => 'तुमच्या पसंतीच्या धर्मांबाहेर (थोडी वजावट)',
    'penalty_marital_preferred' => 'तुमच्या पसंतीच्या वैवाहिक स्थितीबाहेर (थोडी वजावट)',
    'penalty_caste_preferred' => 'तुमच्या पसंतीच्या जातींबाहेर (थोडी वजावट)',

    // Ranking-boost signal reasons shown on member and Suchak cards.
    'boost_reason_verified_kyc' => 'ओळखपत्र पडताळलेले',
    'boost_reason_photo' => 'मंजूर फोटो आहे',
    'boost_reason_completeness' => 'प्रोफाइल व्यवस्थित भरलेले आहे',
    'boost_reason_verified_mobile' => 'मोबाइल नंबर पडताळलेला',
    'boost_reason_active' => 'अलीकडे सक्रिय',
    'boost_reason_similarity' => 'काम किंवा ठिकाण सारखे',
    'boost_reason_ai' => 'AI सुसंगतता सिग्नल',
    'boost_reason_premium' => 'सशुल्क प्लॅन घेतलेला सदस्य',
    'boost_reason_gold_extra' => 'Gold प्लॅन घेतलेला सदस्य',
    'boost_reason_silver_extra' => 'Silver प्लॅन घेतलेला सदस्य',

    'read_only' => 'तुम्हाला फक्त वाचण्याची परवानगी आहे. सुपर अ‍ॅडमिन, जुने अ‍ॅडमिन किंवा डेटा अ‍ॅडमिनच बदल करू शकतात.',
    'strict_warning' => 'कडक मोडमध्ये उमेदवार क्वेरीच्या वेळीच वगळले जातात. यादी बरीच लहान होऊ शकते.',

    'ai_title' => 'सल्लावजा सूचना',
    'ai_intro' => 'या फक्त प्राथमिक तपासण्या आहेत — काहीही आपोआप लागू होत नाही. पाहून सेटिंग स्वतः बदला.',
    'ai_run' => 'सूचना पुन्हा तपासा',

    'preview_title' => 'थेट पूर्वदृश्य',
    'preview_intro' => 'एखादा प्रोफाइल ID निवडा आणि क्रमवारीतील जुळण्या त्यांच्या स्पष्टीकरणासह पहा.',
    'preview_run' => 'पूर्वदृश्य चालवा',
    'preview_profile_id' => 'प्रोफाइल ID',

    'audit_title' => 'बदलांची नोंद',
    'audit_intro' => 'प्रत्येक सेव्हपूर्वी स्नॅपशॉट घेतला जातो. पुन्हा लागू केल्यास त्या स्नॅपशॉटमधील संपूर्ण स्थिती परत येते.',
    'rollback' => 'ही आवृत्ती पुन्हा लागू करा',
    'rollback_confirm' => 'हा स्नॅपशॉट पुन्हा लागू करायचा? सध्याची सेटिंग बदलली जाईल.',

    'field_weight_total' => 'सुरू असलेल्या वजनांची बेरीज',
    'fields_heading' => 'गुण देणारे घटक',
    'filters_heading' => 'शोधणाऱ्याच्या बाजूच्या कडक अटी',
    'behavior_heading' => 'वर्तनाची वजने',
    'boost_heading' => 'बूस्ट नियम',
    'runtime_heading' => 'रनटाइम',
    'candidate_pool' => 'उमेदवार यादीची मर्यादा (रिकामे = config/matching.php वापरा)',
    'persist_cache' => 'क्रमवारीतील जुळण्या profile_matches मध्ये साठवा',
    'behavior_cap' => 'वर्तनामुळे होणारा जास्तीत जास्त बदल (0–50)',
    'use_config_placeholder' => 'डीफॉल्ट वापरा',
]);
