<?php

return array_merge(require __DIR__.'/../en/matching.php', [
    'nav_matches' => 'जुळण्या',
    'profile_label' => 'प्रोफाइल',
    'photo_placeholder' => 'फोटो',
    'title' => 'तुमच्यासाठी जुळणी',
    'subtitle' => 'एकच जुळणी इंजिन — खाली लेन्स निवडा. गुण व सुरक्षा नियम सारखेच; फक्त कोण दिसतो व कोणत्या क्रमाने दिसतो एवढेच बदलते.',
    'lenses_label' => 'लेन्स',
    'empty' => 'अद्याप जुळणी सापडली नाही. प्रोफाइल व जोडीदार पसंती पूर्ण करा किंवा नंतर तपासा.',
    'empty_tab' => 'या टॅबमध्ये आत्ता काही नाही. दुसरा टॅब वापरा किंवा जोडीदार पसंती थोडी मोकळी करा.',
    'score' => 'जुळणी गुण',
    'score_percent' => ':n% जुळणी',
    'boost_note' => 'सक्रियता व प्रीमियम सिग्नलमुळे +:n बूस्ट',
    'view_profile' => 'प्रोफाइल पहा',
    'reasons_heading' => 'जुळणी का',
    'tab_perfect' => 'तुमच्यासाठी योग्य',
    'tab_daily' => 'दैनिक निवड',
    'tab_near' => 'जवळचे',
    'tab_fresh' => 'नवे प्रोफाइल',
    'tab_viewed' => 'तुम्हाला पाहिलं',
    'tab_interested' => 'इंटरेस्ट पाठवलं',
    'tab_second' => 'पुन्हा संधी',
    'tab_curated' => 'क्युरेटेड',
    'tab_hint_perfect' => 'एकूण पसंतींशी सर्वोत्तम जुळण.',
    'tab_hint_daily' => 'दररोज नवीन मिक्स — नियम सारखे, क्रम वेगळा.',
    'tab_hint_near' => 'समान शहर व राज्य आधी.',
    'tab_hint_fresh' => 'गेल्या 14 दिवसांत अपडेट झालेले.',
    'tab_hint_viewed' => 'तुमचं प्रोफाइल उघडले (पात्र जुळणीच).',
    'tab_hint_interested' => 'तुम्हाला पाठवलेले प्रलंबित इंटरेस्ट.',
    'tab_hint_second' => 'तुम्ही पाहिले पण अजून इंटरेस्ट नाही.',
    'tab_hint_curated' => 'बूस्ट जास्त — प्रीमियम/सक्रियता.',
    'skip' => 'नको',
    'skip_confirm' => 'या प्रोफाइलला लपवायचं? तीन वेळा नको म्हटल्यावर तो दिसणार नाही.',
    'skip_recorded' => 'नोंद झाली. आम्ही अशा सूचना कमी दाखवू.',
    'skip_invalid' => 'स्वतःचं प्रोफाइल स्किप करता येत नाही.',

    // Why-this-match reasons — shown to members and Suchaks on every card.
    'reason_age_both_in_range' => 'दोघांच्याही वयाच्या अपेक्षेत वय बसते',
    'reason_age_compatible' => 'वय अपेक्षेप्रमाणे जुळते',
    'reason_age_flexible' => 'वय थोड्या सवलतीच्या मर्यादेत बसते',
    'reason_age_partial' => 'वयाची अंशतः जुळणी',

    'reason_same_city' => 'एकच शहर',
    'reason_same_taluka' => 'एकच तालुका',
    'reason_same_district' => 'एकच जिल्हा',
    'reason_nearby_taluka' => 'जवळचा भाग — साधारण :km किमी',
    'reason_same_state' => 'एकच राज्य',
    'reason_same_country' => 'एकच देश',

    'reason_education_unknown' => 'शिक्षणाचा तपशील अपुरा — अंशतःच गुण दिले',
    'reason_education_match' => 'शिक्षणाची पातळी जवळपास तंतोतंत जुळते',
    'reason_education_close' => 'शिक्षणाची पातळी सारखीच',
    'reason_education_similar' => 'शिक्षण तुलनेने समान',

    'reason_same_occupation' => 'एकच व्यवसाय',
    'reason_similar_work_sector' => 'कामाचे क्षेत्र सारखे',

    'reason_same_subcaste' => 'एकच पोटजात',
    'reason_same_caste' => 'एकच जात',
    'reason_same_religion' => 'एकच धर्म',

    'reason_prefs_open' => 'जोडीदाराच्या अपेक्षा मोकळ्या आहेत',
    'reason_strong_pref_alignment' => 'दोन्ही बाजूंच्या अपेक्षा उत्तम जुळतात',
    'reason_good_pref_alignment' => 'अपेक्षा बऱ्यापैकी जुळतात',

    // Scored field labels — used when reporting a weak signal back to a Suchak.
    'field_age' => 'वयाची जुळणी',
    'field_location' => 'ठिकाणाची जवळीक',
    'field_education' => 'शिक्षण पातळी',
    'field_occupation' => 'व्यवसाय / क्षेत्र',
    'field_community' => 'समाज',
    'field_preferences' => 'जोडीदार पसंतीची जुळणी',
    'field_marital_status' => 'वैवाहिक स्थिती जुळणी',
    'field_height' => 'उंचीची जुळणी',
    'field_diet' => 'आहाराची जुळणी',
    'field_gunamilan' => 'गुणमिलन',

    // Location has two ways of scoring zero. "Far apart" is a real weak signal;
    // "no village entered" is a data gap. Never word the gap as a mismatch.
    'location_missing_seeker' => 'ग्राहकाचे गाव भरलेले नाही — ठिकाणाची जुळणी तपासता आली नाही',
    'location_missing_candidate' => 'या स्थळाचे गाव भरलेले नाही — ठिकाणाची जुळणी तपासता आली नाही',

    // गुणमिलन — शब्द नेहमी 'गुणमिलन', कधीही 'कुंडली' नाही (मालकाचा निर्णय).
    // तीन निकाल वेगवेगळेच ठेवायचे: जुळते / जुळत नाही / पत्रिकेची माहिती उपलब्ध नाही.
    // "माहिती उपलब्ध नाही" ही बहुतेक प्रोफाइलची सामान्य स्थिती आहे — ती नकार म्हणून लिहायची नाही.
    // आकडे नेहमी लॅटिनच — 26/36, 18 — हा नियम गोठवलेला आहे.
    'gunamilan_label' => 'गुणमिलन',
    'gunamilan_verdict_compatible' => 'गुणमिलन जुळते',
    'gunamilan_verdict_not_compatible' => 'गुणमिलन जुळत नाही',
    'gunamilan_verdict_unknown' => 'पत्रिका माहिती उपलब्ध नाही',
    'gunamilan_summary' => ':points · :verdict',
    'gunamilan_review_note' => 'गुणमिलन :points — आवश्यक 18 पेक्षा कमी, चर्चा करणे योग्य',
    'gunamilan_mangal_verdict_compatible' => 'मंगळ जुळतो',
    'gunamilan_mangal_verdict_not_compatible' => 'मंगळ जुळत नाही',
    'gunamilan_mangal_verdict_unknown' => 'मंगळाची स्थिती माहीत नाही',
    'reason_gunamilan_compatible' => 'गुणमिलन :points/:max — जुळते',

    // Suchak-facing fit presentation (same engine score, operator wording).
    'suchak_fit_strong' => 'प्राथमिक जुळणी मजबूत',
    'suchak_fit_possible' => 'प्राथमिक जुळणी संभाव्य',
    'suchak_fit_review' => 'काळजीपूर्वक तपासा',
    // नकार नाही — इंजिनकडून गुण मिळालेच नाहीत. स्थळ सुचवता येते.
    'suchak_fit_none' => 'जुळणीचा संकेत नाही',
    'suchak_weak_signal' => ':field तपासणे आवश्यक',
    'suchak_fit_signals' => '{1} :n जुळलेला मुद्दा|[2,*] :n जुळलेले मुद्दे',
    'suchak_fit_notes' => '{1} :n तपासणी नोंद|[2,*] :n तपासणी नोंदी',

    // Tiered relaxation ladder — यादी भरण्यासाठी कोणती अट सैल केली हे सुचकाला/सदस्याला स्पष्ट सांगते.
    'relaxation_heading' => 'ही यादी कशी वाढवली',
    'relaxation_tier_0' => 'काटेकोर जुळणी — कोणतीही अट सैल केलेली नाही.',
    'relaxation_tier_1' => 'उत्पन्न/उंचीची अट सैल केली.',
    'relaxation_tier_2' => 'जवळच्या जिल्ह्यांपर्यंत वाढवलं.',
    'relaxation_tier_3' => 'जात सैल केली (धर्म कायम).',
    'relaxation_tier_4' => 'गुणमिलन सैल केले — मोजलेले 18 पेक्षा कमी गुण असलेली स्थळेही दाखवली.',
    'relaxation_notice' => 'पुरेशी स्थळे मिळावीत म्हणून एवढं सैल केलं: :note',
    'relaxation_field_income' => 'उत्पन्न',
    'relaxation_field_height' => 'उंची',
    'relaxation_field_location' => 'ठिकाण',
    'relaxation_field_caste' => 'जात',
    'relaxation_field_gunamilan' => 'गुणमिलन',
    'relaxation_fields_label' => 'सैल केलेलं: :fields',
    'relaxation_row_strict' => 'सांगितलेल्या सर्व अटींत बसते',
    'relaxation_row_relaxed' => 'एक अट सैल करून दाखवलेले',
    'relaxation_floor_not_reached' => 'शक्य तेवढं सैल करूनही मोजकीच स्थळे मिळाली.',
    'relaxation_never_relaxed' => 'धर्म, लिंग आणि कायदेशीर विवाहाचे वय कधीही सैल केले जात नाही.',
]);
