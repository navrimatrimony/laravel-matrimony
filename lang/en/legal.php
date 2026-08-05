<?php

/*
|--------------------------------------------------------------------------
| Public legal documents — English
|--------------------------------------------------------------------------
| Rendered by resources/views/legal/show.blade.php through
| App\Support\LegalDocument, which substitutes every :token from
| config/legal.php. Never hard-code a company fact here — add it to
| config/legal.php and reference it as a token, so the product owner fills
| it in exactly once.
|
| Section shape:
|   ['heading' => '...', 'body' => [...], 'facts' => [...], 'list' => [...], 'after' => [...]]
| All keys except 'heading' are optional; they render in that order.
|
| Digits are Latin 0-9 everywhere, including in lang/mr/legal.php.
|
| mr/ must carry every key that exists here.
*/

return [

    'common' => [
        'home' => 'Home',
        'version' => 'Version',
        'effective_from' => 'Effective from',
        'last_updated' => 'Last updated',
        'other_documents' => 'Other legal documents',
        'footer_entity' => 'Operated by :entity.',
    ],

    /*
    |--------------------------------------------------------------------------
    | 1. Terms and Conditions
    |--------------------------------------------------------------------------
    */
    'terms' => [
        'title' => 'Terms and Conditions',
        'summary' => 'These Terms govern your use of the :domain website and the Navri Mile Navryala member and Suchak mobile applications. Please read them before creating a profile. By verifying your mobile number you accept them.',
        'sections' => [
            [
                'heading' => '1. Who we are',
                'body' => [
                    ':legal_name ("we", "us", the "Company") operates the matrimonial matchmaking platform published at :website and delivered through two Android applications: a member application for people seeking a marriage partner, and a Suchak application for matchmakers who assist families.',
                    'The Company is a Limited Liability Partnership registered in India (LLPIN :llpin) with its registered office at :registered_address. It was incorporated approximately 12 years ago, remained dormant for several years, and has now resumed operations. GSTIN: :gstin.',
                    'Together, the website and both applications are referred to in these Terms as the "Platform".',
                ],
            ],
            [
                'heading' => '2. Acceptance, version and record of consent',
                'body' => [
                    'These Terms are version :terms_version, effective from :effective_from. You accept them when you tick the acceptance box and request a one-time password (OTP) to verify your mobile number.',
                    'At that moment we record, against your account, the fact of acceptance, the version string of these Terms and of the Privacy Policy, the date and time, your IP address, your device browser/application identifier and the language you were using. That record is kept as evidence of your consent for as long as your account exists.',
                    'If we publish a materially different version, the new version identifier will be shown here and you may be asked to accept again. Continuing to use the Platform after a change means you accept the current published version.',
                ],
            ],
            [
                'heading' => '3. Definitions',
                'list' => [
                    '"Member" — an individual who registers to find a marriage partner for themselves or, with that person\'s knowledge, for a family member.',
                    '"Suchak" — a matchmaker or marriage bureau operator who uses the Suchak application to create and manage candidate profiles on behalf of families, and who may charge those families for that service.',
                    '"Profile" — the information published about a candidate on the Platform.',
                    '"Showcase Profile" — a profile created by the Platform itself for demonstration purposes. It does not represent a real person. See clause 9.',
                    '"Content" — any text, photograph, document, horoscope detail or other material submitted to the Platform.',
                ],
            ],
            [
                'heading' => '4. Eligibility',
                'body' => [
                    'You may use the Platform only if you are legally competent to marry under Indian law and are seeking a lawful marriage.',
                ],
                'list' => [
                    'Minimum age is enforced by the Platform: 21 years for a male candidate and 18 years for a female candidate, in line with the Prohibition of Child Marriage Act, 2006. Profiles below these ages are rejected at registration and are excluded from every match query.',
                    'You must not be prohibited from marrying under any law applicable to you, and you must not already be in a subsisting marriage unless you have lawfully disclosed and recorded that status on your profile.',
                    'You must not use the Platform on behalf of a person who has not agreed to be listed.',
                    'One person should have one profile. Duplicate profiles for the same candidate may be merged, suspended or removed.',
                    'The Platform is intended for use in India and its content is provided in Marathi and English.',
                ],
            ],
            [
                'heading' => '5. Your obligations',
                'list' => [
                    'Give accurate, current and complete information about yourself or the candidate you represent — particularly age, marital status, existing children, education, occupation, income and health.',
                    'Enter only mobile numbers that you or the candidate\'s family actually own and are entitled to share. Do not enter a stranger\'s number.',
                    'Upload only photographs of the candidate, taken with their knowledge. Do not upload another person\'s photograph.',
                    'Keep your password and your OTP confidential. We will never ask you for your OTP, your password, your bank details, your card number or your UPI PIN. Anyone who does is committing fraud.',
                    'Tell us promptly if you believe your account has been used without your permission.',
                    'Conduct your own independent verification of any person before making any commitment. See the Disclaimer at :disclaimer_url.',
                ],
            ],
            [
                'heading' => '6. Profiles created by a Suchak or a family member',
                'body' => [
                    'Many profiles on this Platform are typed in by a Suchak or by a parent, sibling or relative rather than by the candidate. That is a normal part of how marriages are arranged in Maharashtra, and the Platform supports it — but with limits.',
                    'A Suchak who wishes to represent a person who is already registered on the Platform may only create a pending claim. Nothing about that person is disclosed to the Suchak, and the Suchak cannot read or edit that profile, until the person accepts a consent request sent to a mobile number already recorded on their profile. Consent has a validity period, and the person may revoke it at any time, which closes the Suchak\'s access again.',
                    'If you enter a candidate\'s details, you confirm that you have that candidate\'s permission to do so and to publish those details. If we are told that permission was not given, we may suspend or remove the profile without notice.',
                ],
            ],
            [
                'heading' => '7. Contact numbers and who can see them',
                'body' => [
                    'A candidate\'s mobile number is not published openly. Each member chooses a contact-visibility rule — visible to anyone, only after an interest is accepted, only to sufficiently well-matched profiles, or to no one — and can additionally require that the viewer be photo-verified or identity-verified.',
                    'Even where a member permits it, revealing a contact number normally also requires the viewer to hold a paid plan with contact-reveal entitlement. Each reveal is recorded against the viewer for quota purposes.',
                    'Between Suchaks, a candidate\'s name, village, detailed address and mobile number are hidden by default; the Suchak who represents that candidate may choose to reveal any of them.',
                ],
            ],
            [
                'heading' => '8. What we do not do — please read this carefully',
                'body' => [
                    'The Platform is an intermediary that publishes what its users submit. This is the single most important thing to understand before you rely on anything you read here.',
                ],
                'list' => [
                    'We do NOT verify that the information in a profile is true. Age, marital status, education, job, income, property, caste, health and family details are supplied by the user and are published without independent checking, unless a specific verification badge says otherwise.',
                    'We do NOT run background checks, criminal-record checks, credit checks or medical checks on any member.',
                    'We do NOT guarantee that you will find a match, receive replies, or get married.',
                    'We do NOT act as a marriage broker, guardian or negotiator between families, and we take no part in dowry, gift or financial arrangements of any kind.',
                    'We do NOT endorse, recommend or vouch for any member, family or Suchak, whatever their ranking or badge on the Platform.',
                ],
                'after' => [
                    'Verification, and the decision to marry, remain entirely yours.',
                ],
            ],
            [
                'heading' => '9. Showcase profiles — an honest disclosure',
                'body' => [
                    'You must know this before you use the Platform. The Platform creates and publishes a category of profile that we call a "Showcase Profile". A Showcase Profile is generated by the Company. It does not represent a real person and no real person is behind it.',
                    'Showcase Profiles exist so that a new or narrowly filtered search does not return an empty screen while the member base is still growing. They are created automatically, including in response to a search that returned very few results, and are shaped to resemble what that search asked for.',
                    'Showcase Profiles behave automatically. Specifically, they may:',
                ],
                'list' => [
                    'view real members\' profiles, which then appears in "who viewed me" and generates an ordinary notification;',
                    'send an interest to a real member;',
                    'accept or decline an interest that a real member sent, decided automatically rather than by a person;',
                    'reply to chat messages, and display online, typing and read-receipt indicators.',
                ],
                'after' => [
                    'These actions use the same screens, notifications and records as a real member\'s actions. Except for an "AI Assisted Replies" tag that may appear inside a chat thread, a Showcase Profile is not currently labelled as such in search results, on the profile page, in "who viewed me" or in the interest flow.',
                    'Accordingly: do not treat a view, an interest or an accepted interest as proof that a real person is interested in you. Before you rely on any interaction, speak to the family on the telephone and verify independently.',
                    'We do not ask a Showcase Profile for money, and no Showcase Profile will ever ask you for money. If you believe you have spent a paid contact-reveal credit on a Showcase Profile, write to us at :support_email or telephone :contact_mobile and we will restore the credit.',
                ],
            ],
            [
                'heading' => '10. Prohibited conduct',
                'body' => [
                    'You must not use the Platform to do any of the following. Doing so may lead to immediate suspension and to a report to the police.',
                ],
                'list' => [
                    'Impersonate any person, or publish a profile for a person who has not agreed to it.',
                    'Publish information you know to be false about age, marital status, children, education, income, caste, religion or health.',
                    'Demand, offer, negotiate or discuss dowry in any form. Demanding dowry is an offence under the Dowry Prohibition Act, 1961.',
                    'Ask any member for money, a loan, a gift, a payment "to release a parcel", an investment, a cryptocurrency transfer, or bank/UPI/card credentials.',
                    'Harass, stalk, threaten, abuse, or send sexual, obscene or violent content to any member.',
                    'Post content that is defamatory, casteist, communal, or that incites hatred or violence.',
                    'Publish another person\'s photograph, contact number, address or documents without their consent, or resell, scrape, copy or bulk-export data from the Platform.',
                    'Use bots, automated scripts, or any means of accessing the Platform other than its published interfaces, or attempt to defeat its security, masking or quota controls.',
                    'Register if you are below the minimum marriage age, or create a profile for a minor.',
                    'Use the Platform for any purpose other than seeking a lawful marriage — including commercial solicitation, recruitment, or dating.',
                ],
            ],
            [
                'heading' => '11. Your content and the licence you give us',
                'body' => [
                    'You keep ownership of everything you submit. To operate the Platform we need permission to use it, so you grant us a non-exclusive, royalty-free licence to store, reproduce, resize, watermark, translate and display your Content to other users of the Platform and to Suchaks, strictly for matchmaking purposes and strictly within the visibility settings you have chosen.',
                    'Photographs are re-encoded and resized on upload. A photograph shown to another Suchak carries a watermark naming the Suchak viewing it, so that any misuse can be traced.',
                    'We may refuse, remove or blur any Content that breaches these Terms or the law, and we may do so without prior notice where the content is unlawful.',
                    'This licence ends when the Content is deleted, except for copies retained in backups or where a law requires us to keep them.',
                ],
            ],
            [
                'heading' => '12. Paid plans and payments',
                'body' => [
                    'Some features — such as revealing contact numbers, or a Suchak\'s access to bulk biodata intake — require a paid plan. Prices, entitlements and validity are shown before you pay.',
                    'Payments to the Company are collected through our payment gateway, :payment_gateway. We do not receive or store your full card number, CVV or UPI PIN.',
                    'A plan is delivered digitally and becomes usable immediately on successful payment. Cancellation and refund terms are set out separately at :refund_url, which forms part of these Terms.',
                ],
            ],
            [
                'heading' => '13. Money you pay to a Suchak — the Company is not a party',
                'body' => [
                    'A Suchak is an independent business, not our employee or agent. A Suchak may agree their own service fee, meeting fee or success fee directly with a family, and may send that family a payment request and a UPI QR code generated through the Suchak application.',
                    'That money is paid by the family to the Suchak. It does not pass through the Company, we do not receive it, and we are not a party to that agreement. Any dispute about it is between the family and the Suchak, although the Platform records the request and provides a complaint route (see :grievance_url).',
                    'Fees a Suchak discloses as optional notes — for example a per-meeting fee or a post-marriage fee — are disclosures, not amounts the Platform bills.',
                ],
            ],
            [
                'heading' => '14. Our status as an intermediary',
                'body' => [
                    'The Company is an "intermediary" within the meaning of Section 2(1)(w) of the Information Technology Act, 2000, and claims the protection of Section 79 of that Act in respect of content published by its users.',
                    'We comply with the Information Technology (Intermediary Guidelines and Digital Media Ethics Code) Rules, 2021. We have published these Terms, our Privacy Policy and our user agreement; we have appointed a Grievance Officer whose name and contact details appear at :grievance_url; and we will remove or disable access to unlawful content on receipt of a valid court order or a notification from an authorised government agency, within the time the law requires.',
                    'We do not initiate the transmission of user content, do not select its receiver, and do not modify it, other than by the automatic formatting, resizing and masking described in these Terms.',
                ],
            ],
            [
                'heading' => '15. Suspension, termination and deletion',
                'body' => [
                    'We may suspend or terminate a profile immediately, without refund, if we reasonably believe it breaches these Terms or the law, if it impersonates someone, if a candidate says they never consented to being listed, or if we are required to do so by a court or authority.',
                    'You may ask us at any time to close your account and delete your profile. Write to :support_email or telephone :contact_mobile, or use the grievance route at :grievance_url. We act on such requests as described in the Privacy Policy at :privacy_url.',
                    'On termination, clauses concerning liability, indemnity, governing law and any accrued payment obligations survive.',
                ],
            ],
            [
                'heading' => '16. Intellectual property',
                'body' => [
                    'The Platform, its software, design, database structure, matching logic, Marathi and English interface text, and the "Navri Mile Navryala" name and logo belong to the Company. You may not copy, adapt, reverse-engineer or redistribute any of it.',
                ],
            ],
            [
                'heading' => '17. Disclaimer and limit of our liability',
                'body' => [
                    'The Platform is provided "as is" and "as available". Our full disclaimer is set out at :disclaimer_url and forms part of these Terms.',
                    'To the maximum extent permitted by Indian law, the Company is not liable for any loss arising from the conduct, misrepresentation, fraud or criminal act of any member, family or Suchak; from a marriage that does not take place or does not succeed; from information published by a user; or from any interaction that begins on the Platform and continues off it.',
                    'Where liability cannot lawfully be excluded, our total liability to you for all claims is limited to the amount you actually paid the Company in the 6 months before the claim arose, or ₹5,000, whichever is lower.',
                    'Nothing in these Terms limits liability for fraud or for anything that cannot lawfully be limited.',
                ],
            ],
            [
                'heading' => '18. Indemnity',
                'body' => [
                    'You agree to indemnify the Company against any claim, loss or expense arising from your breach of these Terms, from information you published, or from your conduct towards another user.',
                ],
            ],
            [
                'heading' => '19. Changes to these Terms',
                'body' => [
                    'We may amend these Terms. The version identifier and effective date at the top of this page always show which version is current. Material changes will be notified through the Platform, and where the law requires it, we will seek your acceptance again.',
                ],
            ],
            [
                'heading' => '20. Governing law and jurisdiction',
                'body' => [
                    'These Terms are governed by the laws of India. Subject to any right you have to approach a consumer forum where you reside, the courts at :jurisdiction_city, :jurisdiction_state shall have exclusive jurisdiction.',
                ],
            ],
            [
                'heading' => '21. How to contact us',
                'body' => [
                    'For any question about these Terms, or to raise a complaint, see the grievance page at :grievance_url or contact us directly.',
                ],
                'facts' => [
                    'Entity' => ':legal_name',
                    'Registered office' => ':registered_address',
                    'Mobile' => ':contact_mobile',
                    'Email' => ':support_email',
                    'Website' => ':website',
                ],
            ],
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | 2. Privacy Policy
    |--------------------------------------------------------------------------
    */
    'privacy' => [
        'title' => 'Privacy Policy',
        'summary' => 'What personal data :legal_name collects on the :domain website and the Navri Mile Navryala member and Suchak applications, why we collect it, who can see it, which companies we send it to, how long we keep it, and how you can get it corrected or deleted.',
        'sections' => [
            [
                'heading' => '1. About this policy',
                'body' => [
                    ':legal_name (LLPIN :llpin), registered at :registered_address, is the Data Fiduciary for the personal data described here. We operate the matrimonial platform at :website, an Android application for members, and an Android application for Suchaks (matchmakers).',
                    'This policy is version :privacy_version, effective from :effective_from, last updated :last_updated. It is written to meet the Digital Personal Data Protection Act, 2023, the Information Technology Act, 2000, and the Information Technology (Reasonable Security Practices and Procedures and Sensitive Personal Data or Information) Rules, 2011.',
                    'A matrimonial profile is, by its nature, unusually revealing — it contains your religion, your caste, your family, your income, your health and your marital history. We have tried to describe exactly what happens to it, including the parts that are uncomfortable.',
                ],
            ],
            [
                'heading' => '2. How your consent is recorded',
                'body' => [
                    'You give consent when you tick the acceptance box and request a one-time password to verify your mobile number. At that moment we store, against your account: the type of consent (terms, privacy), the version string of the document you accepted, the date and time, your IP address, your browser or application identifier, and the language you were using.',
                    'This record is kept as evidence of consent for as long as your account exists. You may withdraw consent at any time, as described in clause 12. Withdrawing consent does not make our earlier lawful processing unlawful.',
                ],
            ],
            [
                'heading' => '3. Personal data we collect — identity and account',
                'list' => [
                    'Your name and, where the profile is not your own, your relationship to the candidate (self, parent or guardian, sibling, relative, friend, other).',
                    'Mobile number (and an optional backup number), and the date it was verified. The mobile number is your login identifier.',
                    'Email address, if you give one, and the date it was verified.',
                    'Password, stored only as a one-way cryptographic hash — we never see it and cannot recover it.',
                    'Language preference and notification preferences.',
                    'Your plan, plan status, start and expiry dates.',
                    'A referral code, and if you arrived through a referral, the referring account, the IP address of your registration, and the campaign parameters in the link you clicked.',
                ],
            ],
            [
                'heading' => '4. Personal data we collect — the candidate profile',
                'body' => [
                    'This is the core of a matrimonial profile. Much of it falls into categories that deserve special care, and we treat it accordingly.',
                ],
                'list' => [
                    'Identity and demographics: full name, date of birth, time of birth, gender, mother tongue.',
                    'Religion, caste and sub-caste — both your own, and the religion and caste you are willing to consider in a partner.',
                    'Marital status and complete marital history: year of marriage, year of separation, year of divorce, legal status of a divorce, year of a spouse\'s death, and any note you add.',
                    'Children: their gender, age, and who they live with.',
                    'Location: your current residence down to village or city level, through the state, district and taluka hierarchy, plus a free-text address line; native place; and place of work.',
                    'Education: degrees, specialisation, university and year.',
                    'Occupation and employment: designation, employer name, type of employer, place of work, and career history.',
                    'Income: your annual income (as an exact amount, a range, or a band), the currency and period, and whether you have marked it private. Family income is recorded separately.',
                    'Property and assets: whether you own a house, flat or agricultural land, the area of land, agricultural income, estimated values and ownership type.',
                    'Physical attributes: height, weight, complexion, build, blood group.',
                    'Health and disability: whether you have a physical, hearing or vision condition or prefer not to say, and whether you use spectacles or contact lenses.',
                    'Lifestyle: diet, smoking and drinking.',
                    'Horoscope and birth details: place of birth, rashi, nakshatra, charan, gan, nadi, yoni, varna, vashya, rashi lord, mangal dosh status, devak, kul, gotra and birth weekday.',
                    'Any pending or past court case you choose to disclose: type, court, case number, stage and next hearing date.',
                    'Free text you write about yourself, your expectations, and any additional notes.',
                    'Your partner preferences: age, height, income, education, marital status, location, diet, religion, caste, occupation, and how strictly each applies.',
                ],
                'after' => [
                    'Some of this — religion, caste, health, disability, marital history, litigation and financial details — is sensitive. We collect it because a marriage proposal in this community is genuinely decided on these facts. We do not use it for advertising, we do not sell it, and we do not profile you for any purpose other than suggesting matches.',
                ],
            ],
            [
                'heading' => '5. Personal data about other people that you give us',
                'body' => [
                    'A matrimonial profile is unavoidably about a family, not only about one person. When you fill in a profile you give us personal data about people other than yourself, including:',
                ],
                'list' => [
                    'your father and mother — their names, occupations, notes about them, and up to three mobile numbers each;',
                    'your brothers and sisters — names, gender, marital status, occupation, city, address and up to three contact numbers each, and the same for their spouses;',
                    'your children — name, gender, age and who they live with;',
                    'other relatives — the relationship, a free-text description, and a contact number;',
                    'the families your family is already connected to by marriage — surnames and places.',
                ],
                'after' => [
                    'When you enter these details you confirm that you are entitled to share them and that the person concerned knows. Please do not enter a phone number belonging to someone who would not want it here. Those numbers are also used to reach a candidate for consent when a Suchak asks to represent them, so an incorrect number causes a real person to receive a message that was never meant for them.',
                    'If your details appear on someone else\'s profile and you did not agree to that, write to our Grievance Officer at :officer_email and we will remove them.',
                ],
            ],
            [
                'heading' => '6. Photographs, documents and biodata files',
                'list' => [
                    'Profile photographs. On upload, every photograph is automatically re-encoded and resized, and is scanned by an automated moderation system for nudity and for other prohibited content. The result of that scan, the approval or rejection status, the reason for a rejection, and any later admin decision are stored alongside the photograph.',
                    'Photographs that have been through moderation, together with the scan result and the final decision, may be retained in an internal dataset used to improve that moderation system.',
                    'Identity documents, if you choose to apply for identity verification. We accept a photograph or PDF of a government identity document. It is stored on private storage that is not publicly reachable, and is reviewed by our staff against your profile photograph. We store the document file and the review outcome. We do not extract or store your Aadhaar number, PAN number or any other identity number as a separate field.',
                    'Biodata documents. Where a printed or handwritten biodata is uploaded — by you, by a Suchak, or through our WhatsApp intake — we store the original file, the full text extracted from it, and the structured fields extracted from that text. Extraction is done partly by automated services, including services operated by third parties (clause 9).',
                    'A Suchak uploads their own identity documents (for example Aadhaar and PAN images) when their account is verified, along with their photograph, office details, UPI id and payment QR image.',
                    'Payment proof documents and receipts uploaded in connection with a Suchak\'s collections.',
                ],
            ],
            [
                'heading' => '7. Activity, interaction and technical data',
                'list' => [
                    'Interests you send and receive, and their status; shortlists; profiles you hide or block; and profiles you have viewed and who has viewed you.',
                    'Chat messages you send on the Platform, including the message text, any image sent in chat, and read and delivery status.',
                    'Contact requests, contact grants, and a record of each time a contact number is revealed — which member revealed which profile\'s number, and when. This record exists both for your protection and to meter your plan\'s quota.',
                    'Notifications we generate for you.',
                    'Match scores, the reasons computed for them, and behavioural signals — such as which suggestions you skipped — used to rank future suggestions.',
                    'Login sessions: your IP address, browser or application identifier, and last activity time.',
                    'Every one-time password request: the mobile number, a hash of the OTP (never the OTP itself in readable form), the number of attempts, expiry, your IP address, your browser or application identifier, and your language.',
                    'The date and time you were last active on the Platform.',
                    'A device registration token for push notifications, and the platform and app it belongs to.',
                    'API access tokens issued to your app, and when they were last used.',
                    'Reports made about you or by you, moderation decisions, and internal risk flags.',
                    'Every earlier value of a profile field you have changed. Our governance system keeps a history of changes — the old value, the new value, who changed it and from where — so that a disputed edit can be traced.',
                ],
                'after' => [
                    'We do not use any advertising network, analytics SDK, crash-reporting service, or tracking pixel. There is no Google Analytics, no Meta Pixel, and no third-party advertising identifier on this Platform.',
                ],
            ],
            [
                'heading' => '8. Location data',
                'body' => [
                    'Your residence, native place and work location are stored as entries in a standard Indian administrative hierarchy — country, state, district, taluka, village or city — plus any free-text address line you type.',
                    'If you use the "use my current location" feature, your device sends us GPS coordinates. We forward those coordinates to the OpenStreetMap Foundation\'s Nominatim service to convert them into a place name. The coordinates are held only in a short-lived cache to answer that lookup; we do not write them into your profile.',
                    'We do not track your location in the background, and we do not derive your location from your IP address.',
                ],
            ],
            [
                'heading' => '9. Companies we send your data to',
                'body' => [
                    'We use the following third-party service providers. Each one is named because our code actually sends data to it. We do not sell your personal data to anyone, and we do not share it for advertising.',
                ],
                'list' => [
                    'Meta Platforms (WhatsApp Business / Cloud API) — we send your mobile number and the one-time password to Meta so that the OTP reaches you on WhatsApp, because WhatsApp is the only channel we use to deliver OTPs. Meta also carries our consent-request messages, notification and reminder messages, the WhatsApp-based biodata intake conversation, and any media exchanged in it. Where a message shows profile information — for example a name, education, occupation and district, with a link to a profile — that content passes through Meta.',
                    'PayU Payments Private Limited — when you pay, we send your name, email address, mobile number, the amount, the plan identifier, a transaction reference and our internal account identifier to PayU. Your card number, CVV and UPI PIN are entered on PayU\'s own page and never reach our servers. We store PayU\'s response to the transaction.',
                    'OpenAI — we send uploaded biodata documents (as images or text) for automated extraction of the fields in them; profile photographs for automated moderation; and the text of a help-centre question you send us, when the automated help assistant is enabled. Biodata content includes names, dates of birth, phone numbers, caste, addresses and family details.',
                    'Sarvam AI — used for the same biodata document and text extraction as above, and for a limited automated comparison between two profile summaries used in match scoring. Which of Sarvam or OpenAI processes a given document depends on our current configuration; both may.',
                    'Google LLC / Firebase — for push notifications we send your device registration token and the notification content to Firebase Cloud Messaging. If you sign in with Google, your Google identity token (which contains your email address) is sent back to Google for verification. On the Suchak application, mobile number verification may use Firebase Phone Authentication, in which case your mobile number is processed by Google. Our public pages also load fonts from Google, which means Google receives your IP address and browser identifier when you visit.',
                    'OpenStreetMap Foundation — GPS coordinates, only when you use the "current location" feature, as described in clause 8.',
                    'Content delivery networks (Bunny Fonts, jsDelivr, Cloudflare cdnjs) — these serve fonts and scripts on some pages, and therefore receive your IP address and browser identifier.',
                    'Our email service provider (:support_email domain) — where we send you an email, for example a password change alert.',
                ],
                'after' => [
                    'We also use our own servers, located in India, and a photograph-moderation component that we operate ourselves.',
                    'For clarity, and because they are often assumed: we do not use any SMS gateway, any analytics or crash-reporting service, any advertising network, Apple or Facebook sign-in, or any third-party cloud photo storage. Your photographs and biodata files are stored on our own servers.',
                ],
            ],
            [
                'heading' => '10. Who can see your data on the Platform',
                'body' => [
                    'Not everything on your profile is visible to everyone. In summary:',
                ],
                'list' => [
                    'Other members can see your profile as your visibility settings allow. Your mobile number is not published openly.',
                    'Your contact number is governed by a rule you choose — visible to anyone, only after you accept an interest, only to sufficiently well-matched profiles, or to no one. You may additionally require the viewer to be photo-verified or identity-verified. Beyond your rule, revealing a number normally also requires the viewer to hold a paid plan with a contact-reveal entitlement, and each reveal is recorded.',
                    'A Suchak who represents you sees and can edit your full profile, exactly as you see it, including your stored contact numbers. This access exists only after you have accepted a consent request sent to a number already on your profile. Before you accept, the Suchak is shown nothing at all about you — not even your stored numbers — and cannot read or edit anything. Consent has an expiry date and you may revoke it at any time; access closes again immediately.',
                    'Other Suchaks — those who do not represent you — see a masked version. Your name, your village, your detailed address and your mobile number are hidden by default. Your photograph is visible to them, because a matchmaker who cannot see a face cannot propose a match, and it carries a watermark naming the Suchak viewing it so that misuse can be traced. Only the Suchak who represents you may choose to reveal any of the four hidden items, and only for you. Search filters that could be used to guess a masked value — searching by name, or by an exact income range, or narrower than taluka — are refused.',
                    'Our staff can see profile data where it is necessary to moderate content, review an identity document, investigate a complaint, resolve a data conflict or provide support. Staff actions on a profile are logged.',
                    'Profiles created by the Platform for demonstration ("Showcase Profiles") may view your profile and interact with you automatically. They are not real people and no human reads your data through them; the interaction is generated by software. This is described in clause 9 of the Terms at :terms_url.',
                ],
            ],
            [
                'heading' => '11. Why we use your data, and on what basis',
                'body' => [
                    'We process your personal data on the basis of the consent you gave at signup, and for the specific purposes below. We do not use it for any incompatible purpose.',
                ],
                'list' => [
                    'To create and publish your profile, and to let other members and Suchaks find it.',
                    'To verify your mobile number and to sign you in.',
                    'To compute matches, scores, explanations and suggestions from your profile and your stated preferences.',
                    'To let members express interest, exchange messages and — subject to clause 10 — exchange contact numbers.',
                    'To operate the Suchak consent system, so that nobody is represented by a matchmaker they have not agreed to.',
                    'To extract fields from an uploaded biodata document so that a profile can be created without retyping it.',
                    'To moderate photographs and content, to detect fraud and duplicate profiles, and to protect members from harassment and abuse.',
                    'To take payment, issue invoices and maintain financial records.',
                    'To send you service messages, including OTPs, consent requests, interest and message notifications, and — where you have opted in — reminders.',
                    'To answer your questions and handle your complaints.',
                    'To comply with law, and to respond to a lawful order of a court or an authorised government agency.',
                ],
            ],
            [
                'heading' => '12. Your rights',
                'body' => [
                    'Under the Digital Personal Data Protection Act, 2023 you have the following rights. To exercise any of them, contact our Grievance Officer at :officer_email or telephone :officer_phone. We may verify your identity — normally by an OTP to your registered mobile number — before we act, so that nobody else can obtain or delete your data. We respond within :dpdp_days days.',
                ],
                'list' => [
                    'Access — a summary of the personal data we hold about you, how we are processing it, and the third parties we have shared it with.',
                    'Correction and completion — you can edit most of your profile yourself in the app or on the website; for anything you cannot, write to us.',
                    'Erasure — see clause 13.',
                    'Withdrawal of consent — you may withdraw the consent you gave at signup, and you may separately revoke a consent you gave to a Suchak, which immediately ends that Suchak\'s access to your profile.',
                    'Nomination — you may nominate a person to exercise these rights on your behalf if you die or become unable to act.',
                    'Grievance redressal — a complaint route that does not depend on going to court, described at :grievance_url.',
                ],
            ],
            [
                'heading' => '13. Deleting your account and your profile',
                'body' => [
                    'We want to be straightforward about how this works today rather than describe something we have not built.',
                    'There is currently no self-service "delete my account" button in the applications or on the website. To close your account and have your profile removed, send a request to :officer_email, or telephone :officer_phone, or write to :officer_address. Please send the request from, or quote, the mobile number registered with us.',
                    'On receiving a verified request we act within :deletion_days days. We withdraw your profile from search, from every match query and from every Suchak\'s view, and we mark it deleted so that it is no longer served to any user.',
                    'What we keep, and why:',
                ],
                'list' => [
                    'Records we are required by law to keep, in particular payment, invoice and tax records, which are retained for :financial_years years.',
                    'The record of your consent at signup, retained as evidence that consent was given.',
                    'Records needed to defend or pursue a legal claim, or to comply with a court or government order, for as long as that need lasts.',
                    'Change-history entries showing that a field was edited. These are part of our governance and dispute-resolution trail.',
                    'Copies inside routine encrypted backups, which are overwritten on their own cycle.',
                    'Data that is genuinely no longer identifiable, such as aggregate counts.',
                ],
                'after' => [
                    'If you want the retained items reduced to the legal minimum, say so in your request and we will tell you exactly what we are obliged to keep and why.',
                ],
            ],
            [
                'heading' => '14. How long we keep your data',
                'body' => [
                    'We keep personal data only as long as the purpose it was collected for requires, and then for any period the law requires.',
                ],
                'list' => [
                    'An active profile and its data are kept for as long as your account is open.',
                    'In-app notifications are automatically deleted after 90 days.',
                    'Payment, invoice and tax records are kept for :financial_years years.',
                    'Consent records — including Suchak consent records, with the IP address and device identifier captured at the time — are kept as evidence for as long as the account and any related claim period lasts.',
                    'Uploaded biodata documents are kept while the intake is being processed and reviewed. Where a retention period is configured, the original file is deleted from storage at the end of it; the fields extracted from it remain on the profile they created.',
                    'Identity verification documents are kept while the verification is valid, and are deleted on request once the verification is no longer relied on.',
                    'Suchak accounts that were started but never completed and never used are deleted after 30 days.',
                    'If your account has been inactive for more than :inactive_months months you may ask us to close it, and we may contact you before doing so ourselves.',
                ],
            ],
            [
                'heading' => '15. Security',
                'body' => [
                    'We take reasonable security safeguards as required by Section 8(5) of the Digital Personal Data Protection Act, 2023.',
                ],
                'list' => [
                    'Passwords are stored only as one-way hashes. One-time passwords are stored as hashes and expire quickly.',
                    'Identity documents, biodata files and payment proofs are stored on private storage that is not reachable by a public web address.',
                    'The site and both applications communicate over encrypted connections.',
                    'Access to member data by our staff is restricted by role, and administrative actions are logged.',
                    'Contact numbers are masked by default in every cross-Suchak view, and search filters that could be used to reverse a mask are refused.',
                    'When your password is changed, every other session and API token for your account is invalidated and you are notified.',
                    'No system is perfectly secure. If a personal data breach occurs, we will notify the Data Protection Board of India and every affected user, as required by law.',
                ],
            ],
            [
                'heading' => '16. Where your data is stored',
                'body' => [
                    'Our servers are located in India and your profile data, photographs and documents are stored there.',
                    'Some of the service providers named in clause 9 process data outside India. Where that happens, the transfer is limited to what that service needs — for example, a mobile number and an OTP sent to Meta, or a biodata document sent for automated extraction — and is subject to that provider\'s own obligations. We do not transfer personal data to any country to which such transfer is restricted by the Central Government.',
                ],
            ],
            [
                'heading' => '17. Children',
                'body' => [
                    'The Platform is not for children. A profile may only be created for a person who has reached the minimum legal age of marriage — 21 years for a man and 18 years for a woman — and this is enforced at registration and in every match query.',
                    'We do not knowingly collect the personal data of a child. If you believe a profile has been created for a person below the minimum age, tell us at :officer_email and we will remove it.',
                    'The one exception is that a member may record the gender, age and living arrangement of their own children. That information is provided by the parent for the purpose of an honest matrimonial disclosure, and no profile is created for the child.',
                ],
            ],
            [
                'heading' => '18. Cookies and similar technologies',
                'body' => [
                    'The website uses only the cookies it needs to work: a session cookie to keep you signed in, a security token cookie to prevent cross-site request forgery, and a cookie remembering whether you chose Marathi or English.',
                    'We do not use advertising cookies, tracking cookies or any third-party analytics cookie. Fonts and a small number of scripts are loaded from the content delivery networks named in clause 9, which see your IP address as a normal consequence of serving a file.',
                ],
            ],
            [
                'heading' => '19. Changes to this policy',
                'body' => [
                    'We may update this policy. The version and effective date at the top of this page always tell you which version applies. Where a change is material we will notify you through the Platform, and where the law requires it we will seek your consent again.',
                ],
            ],
            [
                'heading' => '20. Contact us',
                'body' => [
                    'For any question about this policy, or to exercise a right described in clause 12, contact our Grievance Officer. Full details and escalation routes are at :grievance_url.',
                ],
                'facts' => [
                    'Data Fiduciary' => ':legal_name',
                    'Registered office' => ':registered_address',
                    'Grievance Officer' => ':officer_name',
                    'Email' => ':officer_email',
                    'Telephone' => ':officer_phone',
                    'Website' => ':website',
                ],
            ],
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | 3. Refund and Cancellation Policy
    |--------------------------------------------------------------------------
    */
    'refund' => [
        'title' => 'Refund and Cancellation Policy',
        'summary' => 'How cancellations and refunds work for paid plans bought from :legal_name, and what happens to money paid directly to a Suchak.',
        'sections' => [
            [
                'heading' => '1. What this policy covers',
                'body' => [
                    'This policy applies to payments made to :legal_name through the :domain website or the Navri Mile Navryala applications. It is version :refund_version, effective from :effective_from.',
                    'Two kinds of payment are made to the Company:',
                ],
                'list' => [
                    'Member plans — bought by a member to unlock features such as revealing contact numbers.',
                    'Suchak platform subscriptions — bought by a Suchak to unlock capabilities such as bulk biodata intake and higher profile limits.',
                ],
                'after' => [
                    'A third kind of payment exists on the Platform and this policy does NOT cover it — money a family pays directly to a Suchak. See clause 7.',
                ],
            ],
            [
                'heading' => '2. What you are buying',
                'body' => [
                    'A plan is a digital service. Access to the paid features begins immediately on successful payment, for the stated validity period. There is no physical product and therefore no shipping, delivery or return.',
                    'A plan gives you access to features. It does not promise a match, a reply, a meeting or a marriage, and a refund will not be granted because those did not happen.',
                ],
            ],
            [
                'heading' => '3. Cancelling a plan',
                'body' => [
                    'You may stop using a paid plan at any time. Because the service is delivered immediately and continuously, stopping does not by itself create a right to a refund of the unused period.',
                    'Plans do not auto-renew without your action. If a renewal is ever set up, you will be told how to switch it off before it charges.',
                ],
            ],
            [
                'heading' => '4. When we will refund you',
                'body' => [
                    'We will refund a payment in the following situations.',
                ],
                'list' => [
                    'Duplicate payment — the same plan was charged more than once for the same period. The extra charge is refunded in full.',
                    'Money debited but the plan not activated — the amount is refunded in full, or the plan is activated, at your choice.',
                    'A technical fault on our side prevented you from using the paid feature for a substantial part of the validity period, and we could not fix it.',
                    'You were charged after cancelling, or charged an amount different from the price displayed.',
                    'You bought a plan and did not use any paid feature at all, and you ask for a refund within :cooling_off_hours hours of payment. In this case we refund the full amount.',
                    'We suspend or close your account for a reason that is not your fault. In that case we refund the unused portion of your plan on a pro-rata basis.',
                ],
            ],
            [
                'heading' => '5. When we will not refund you',
                'list' => [
                    'You have already used a paid feature — for example you revealed a contact number — and then change your mind.',
                    'You did not receive interest, replies or a proposal, or a match did not lead to marriage.',
                    'You are dissatisfied with the accuracy of another user\'s profile. We do not verify user claims (see the Disclaimer at :disclaimer_url), and misrepresentation by another user is a matter to raise through the grievance route at :grievance_url.',
                    'Your account was suspended or closed because you breached the Terms at :terms_url.',
                    'The request is made after the plan\'s validity period has expired.',
                    'You paid a Suchak directly (see clause 7).',
                ],
            ],
            [
                'heading' => '6. How to ask for a refund, and how long it takes',
                'body' => [
                    'Send your request to :support_email, or telephone :contact_mobile. Include your registered mobile number, the transaction reference or order id, the date and amount, and the reason.',
                ],
                'list' => [
                    'We acknowledge a refund request within :ack_hours hours.',
                    'We decide the request within :refund_processing_days working days of receiving it and the information we need.',
                    'An approved refund is sent back to the same payment method you paid from, through :payment_gateway. We cannot refund to a different account.',
                    'Once we release it, your bank or card issuer usually credits the money within :bank_credit_days working days. That final step is controlled by your bank, not by us.',
                    'Refunds are made in Indian Rupees. Any bank or gateway charge deducted at your end is not within our control.',
                ],
            ],
            [
                'heading' => '7. Money paid to a Suchak — this policy does not apply',
                'body' => [
                    'A Suchak is an independent business. When a family agrees a service fee, a per-meeting fee or a success fee with a Suchak and pays it — including by scanning a UPI QR code generated in the Suchak application — that money goes directly to the Suchak. It does not pass through the Company and we do not hold it.',
                    'We therefore cannot refund it. Refund or adjustment of a Suchak\'s fee is a matter between the family and that Suchak, on the terms they agreed.',
                    'If you believe a Suchak has taken money improperly, you may still complain to us at :grievance_url. We can suspend a Suchak\'s account, and we will assist any lawful investigation, but we cannot return money we never received.',
                    'Where a success fee has been agreed with a Suchak, it is structured to be earned in stages that have already occurred, so that nothing is held against a future event that may not happen.',
                ],
            ],
            [
                'heading' => '8. Failed and disputed transactions',
                'body' => [
                    'If money leaves your account but the payment fails, the gateway or your bank normally reverses it automatically within :bank_credit_days working days. If it does not, contact us with the transaction reference and we will pursue it with :payment_gateway.',
                    'Please contact us before raising a chargeback with your bank. A chargeback raised while we are already processing the same request can freeze the amount and delay it further.',
                ],
            ],
            [
                'heading' => '9. Contact for refunds',
                'facts' => [
                    'Entity' => ':legal_name',
                    'Registered office' => ':registered_address',
                    'Mobile' => ':contact_mobile',
                    'Email' => ':support_email',
                    'Escalation' => 'Grievance Officer — see :grievance_url',
                ],
            ],
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | 4. Disclaimer
    |--------------------------------------------------------------------------
    */
    'disclaimer' => [
        'title' => 'Disclaimer',
        'summary' => 'What this Platform does not check, does not promise, and cannot protect you from. Please read it before you act on anything you see here.',
        'sections' => [
            [
                'heading' => '1. We do not verify what members tell us',
                'body' => [
                    'Every fact on a profile — name, age, date of birth, marital status, existing children, caste, religion, education, job, income, property, health, family details and horoscope — is entered by a member, a family, or a Suchak. :legal_name publishes it. We do not check whether it is true.',
                    'A profile that looks complete, well written and recently active is not for that reason verified. Some profiles carry a specific verification badge (for example a verified mobile number or an approved identity document); that badge means only what it says on its face, and nothing more.',
                    'Treat every claim as unconfirmed until you have confirmed it yourself.',
                ],
            ],
            [
                'heading' => '2. We do not promise a match or a marriage',
                'body' => [
                    'We provide a place to search, to be seen and to make contact. We do not promise that you will receive interest, that anyone will reply, that a meeting will happen, or that you will marry. Buying a paid plan does not change this.',
                    'We are not a marriage bureau acting for you, not your guardian, and not a party to any negotiation between two families. We take no part in dowry, gifts or any financial arrangement between families, and any such demand made through this Platform is both a breach of our Terms and an offence under the Dowry Prohibition Act, 1961.',
                ],
            ],
            [
                'heading' => '3. Showcase profiles are not real people',
                'body' => [
                    'The Platform publishes Platform-generated "Showcase Profiles" that do not correspond to any real person. They are created automatically — including when a search would otherwise return almost nothing — and they act automatically: they may view your profile, send you an interest, accept or decline an interest you sent, and reply to your chat messages, using the same notifications and screens as a real member.',
                    'Other than an "AI Assisted Replies" tag that may appear inside a chat thread, they are not currently marked as Showcase Profiles in search, on the profile page, in "who viewed me", or in the interest flow.',
                    'This means a view, an interest, or an accepted interest is not by itself evidence that a real person is interested in you. Always speak to the family by telephone and verify independently before you act. Clause 9 of the Terms at :terms_url sets this out in full.',
                ],
            ],
            [
                'heading' => '4. Fraud warning — read this before you send anyone money',
                'body' => [
                    'Matrimonial platforms attract fraudsters. The pattern is consistent: a warm, attentive conversation, an urgent problem, and a request for money. Please take these rules as absolute.',
                ],
                'list' => [
                    'Never send money, however small the amount and however convincing the reason — customs duty on a gift, a medical emergency, a visa fee, a stuck parcel, an investment "opportunity", or a cryptocurrency transfer.',
                    'Never share your OTP, password, UPI PIN, card number, CVV, bank account details or net-banking credentials with anyone. We will never ask you for them, and no genuine member ever needs them.',
                    'Be careful with photographs, identity documents and video calls. Do not send intimate images to anyone. Sextortion by way of recorded video calls is a known and common attack.',
                    'Be cautious of anyone who refuses a normal telephone call, who is "abroad" and cannot meet, who moves the conversation off the Platform very quickly, or who pushes you to decide urgently.',
                    'Meet only in a public place, tell a family member where you are going, and take someone with you the first time.',
                    'Independently verify the person: their workplace, their address, their family, and their marital status. Check identity documents against the person in front of you.',
                    'Report the profile to us immediately at :grievance_url, and report financial fraud to the National Cyber Crime Reporting Portal at cybercrime.gov.in or by dialling 1930.',
                ],
            ],
            [
                'heading' => '5. Matching, ranking and scores are automated',
                'body' => [
                    'Search results, match scores, compatibility explanations and "suggested" profiles are produced by software from the data users have entered and from their stated preferences. They are a convenience, not advice, not a recommendation, and not an assessment of any person\'s character or suitability.',
                    'The ranking of a profile may be affected by signals such as verification, photograph, profile completeness and recent activity, and to a limited extent by a paid plan. A higher position does not mean a better or safer person.',
                    'Where the software relaxes a preference to find more results, or fills in a preference you did not state, it is marked as an assumption. An assumption made by our software is never a condition stated by that family.',
                ],
            ],
            [
                'heading' => '6. गुणमिलन (horoscope matching) is informational only',
                'body' => [
                    'Where both candidates have entered birth details, the Platform can compute a गुणमिलन score. This is a computation over the data entered, offered as information for families who use it. It is not a prediction, not religious advice, and not a statement about anyone\'s future.',
                    'Most profiles do not carry the birth details needed for this computation. Where the data is missing, the result is reported as "not computable". Not computable never means incompatible, and must not be read that way.',
                    'Mangal dosh is shown separately, from a manually entered field, and where either side is unknown the result is again "not computable" rather than a negative.',
                ],
            ],
            [
                'heading' => '7. Location, translation and other data limits',
                'body' => [
                    'Village, taluka and district data is derived from public datasets and from name-based geocoding, and is known to contain errors. "Nearby" is an approximation and should not be relied on as a precise distance.',
                    'The Platform is presented in Marathi and English. Where an official name has not yet been translated, the English form is shown. Member-supplied text is never translated.',
                ],
            ],
            [
                'heading' => '8. No professional advice',
                'body' => [
                    'Nothing on the Platform is legal, financial, medical, psychological or astrological advice. Take independent professional advice before making any decision that depends on such matters.',
                ],
            ],
            [
                'heading' => '9. Availability and third parties',
                'body' => [
                    'The Platform is provided "as is" and "as available". We do not promise uninterrupted or error-free operation, and we may change, suspend or withdraw any feature.',
                    'The Platform relies on third-party services — a payment gateway, a messaging provider, push notification and cloud infrastructure. We are not responsible for their outages, and links to external sites are not endorsements.',
                ],
            ],
            [
                'heading' => '10. Limit of liability',
                'body' => [
                    'To the maximum extent permitted by Indian law, :legal_name is not liable for loss arising from any user\'s conduct, misrepresentation, fraud or criminal act, from a marriage that does not occur or does not succeed, or from any interaction that begins on the Platform and continues elsewhere. The liability cap in clause 17 of the Terms at :terms_url applies.',
                    'Nothing here excludes liability for fraud by us, or any liability that cannot lawfully be excluded.',
                ],
            ],
            [
                'heading' => '11. Contact',
                'facts' => [
                    'Entity' => ':legal_name',
                    'Mobile' => ':contact_mobile',
                    'Email' => ':support_email',
                    'Complaints' => 'Grievance Officer — see :grievance_url',
                ],
            ],
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | 5. Grievance Redressal
    |--------------------------------------------------------------------------
    */
    'grievance' => [
        'title' => 'Grievance Redressal',
        'summary' => 'How to complain to :legal_name, who handles it, how long we take, and where to go if you are not satisfied.',
        'sections' => [
            [
                'heading' => '1. Why this page exists',
                'body' => [
                    'Rule 3(2) of the Information Technology (Intermediary Guidelines and Digital Media Ethics Code) Rules, 2021 requires an intermediary to publish the name and contact details of a Grievance Officer and to state its response timelines. Section 13 of the Digital Personal Data Protection Act, 2023 requires a readily available means of grievance redressal for data principals.',
                    'This page satisfies both. It is version :grievance_version, effective from :effective_from.',
                ],
            ],
            [
                'heading' => '2. Grievance Officer',
                'facts' => [
                    'Name' => ':officer_name',
                    'Designation' => ':officer_designation',
                    'Company' => ':legal_name',
                    'Address' => ':officer_address',
                    'Email' => ':officer_email',
                    'Telephone' => ':officer_phone',
                    'Working hours' => ':officer_hours',
                ],
                'after' => [
                    'The Grievance Officer is a resident of India and is the same officer for the website and for both Android applications. The same officer handles complaints under the IT Rules, 2021 and data-protection grievances under the DPDP Act, 2023.',
                ],
            ],
            [
                'heading' => '3. What you can complain about',
                'list' => [
                    'A fake, duplicate or impersonating profile, or a profile using your photograph or details without permission.',
                    'A profile listed without the candidate\'s consent, or a Suchak acting for a candidate who did not agree.',
                    'Harassment, abuse, threats, obscene or sexual content, or unwanted contact.',
                    'Fraud — any demand for money, a request for OTP, bank or UPI credentials, or an attempt at extortion.',
                    'A dowry demand made through the Platform.',
                    'Content that is unlawful, defamatory, casteist or communal.',
                    'Non-consensual intimate imagery or morphed images of you.',
                    'A billing, plan or refund problem (see also :refund_url).',
                    'A complaint about a Suchak — their conduct, their fees, or their handling of your data.',
                    'Any data-protection matter: access to your data, correction, deletion, or withdrawal of consent (clause 6).',
                ],
            ],
            [
                'heading' => '4. How to file a complaint',
                'body' => [
                    'Write to the Grievance Officer by email at :officer_email, or telephone :officer_phone, or post to :officer_address.',
                    'To let us act quickly, please include:',
                ],
                'list' => [
                    'your name and the mobile number registered with us;',
                    'the profile id, screen name or link of the profile you are complaining about;',
                    'what happened, with dates and times;',
                    'screenshots or other evidence, if you have them;',
                    'what you would like us to do;',
                    'for a complaint about your own content or identity, a statement that the information you have given is true, and that you are the person concerned or are authorised to act for them.',
                ],
                'after' => [
                    'We do not require a fee, a stamp paper or a lawyer to accept a complaint.',
                ],
            ],
            [
                'heading' => '5. Our timelines',
                'list' => [
                    'We acknowledge every complaint within :ack_hours hours of receiving it.',
                    'We dispose of a complaint, and inform you of the action taken, within :resolution_days days of receipt, as required by Rule 3(2)(a) of the IT Rules, 2021.',
                    'For a complaint about non-consensual intimate imagery, or about content that impersonates a person in an intimate or artificially altered form, we act to remove or disable access within :takedown_hours hours, as required by Rule 3(2)(b).',
                    'For a data-protection request under the DPDP Act, 2023, we respond within :dpdp_days days.',
                    'Where we need more information from you, the clock on our response runs from the day we receive it. We will tell you exactly what we need.',
                    'If we decide not to act on a complaint, we will tell you why.',
                ],
            ],
            [
                'heading' => '6. Your data-protection rights and how to exercise them',
                'body' => [
                    'Under the Digital Personal Data Protection Act, 2023 you may ask us to:',
                ],
                'list' => [
                    'confirm what personal data of yours we hold, and give you a summary of it and of who we have shared it with;',
                    'correct, complete or update data that is inaccurate or out of date;',
                    'erase your personal data and close your account, where we are not required to keep it by law;',
                    'withdraw a consent you gave us — including a consent you gave to a Suchak to represent you, which you may revoke at any time;',
                    'nominate another person to exercise these rights for you if you die or become incapacitated.',
                ],
                'after' => [
                    'Send any of these requests to :officer_email or telephone :officer_phone. We may ask you to verify your identity — normally by confirming an OTP sent to your registered mobile number — before we act, so that no one else can obtain or delete your data.',
                    'How deletion actually works, and what we are required to keep afterwards, is described in the Privacy Policy at :privacy_url.',
                ],
            ],
            [
                'heading' => '7. Unlawful content, court orders and law-enforcement requests',
                'body' => [
                    'On receipt of a valid order of a court of competent jurisdiction, or a notification from an appropriate government agency under Section 79(3)(b) of the Information Technology Act, 2000, we will remove or disable access to the content identified, within the period the law allows.',
                    'We will co-operate with a lawful request from a law-enforcement agency made under Section 91 of the Code of Criminal Procedure or its successor provision, or under the Information Technology Act, 2000.',
                ],
            ],
            [
                'heading' => '8. If you are not satisfied with our response',
                'body' => [
                    'You may escalate. Your options include:',
                ],
                'list' => [
                    'the Data Protection Board of India, for a data-protection grievance, after you have first raised it with us and either received no reply within :escalation_days days or are dissatisfied with it;',
                    'the Grievance Appellate Committee constituted under Rule 3A of the IT Rules, 2021, within 30 days of our decision;',
                    'the National Cyber Crime Reporting Portal at cybercrime.gov.in, or the helpline 1930, for online fraud or cyber crime;',
                    'your local police station, for any criminal offence — including a dowry demand, extortion or threat;',
                    'the National Consumer Helpline at consumerhelpline.gov.in or 1915, or the consumer commission where you reside, for a service or billing complaint.',
                ],
            ],
            [
                'heading' => '9. Monthly compliance reporting',
                'body' => [
                    'Where the IT Rules, 2021 require it, we publish periodic reports of complaints received and action taken. Those reports contain no personal data of any complainant.',
                ],
            ],
            [
                'heading' => '10. Company details',
                'facts' => [
                    'Entity' => ':legal_name',
                    'LLPIN' => ':llpin',
                    'Registered office' => ':registered_address',
                    'Mobile' => ':contact_mobile',
                    'Email' => ':support_email',
                    'Website' => ':website',
                ],
            ],
        ],
    ],
];
