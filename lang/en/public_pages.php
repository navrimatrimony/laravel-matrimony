<?php

/*
|--------------------------------------------------------------------------
| Public no-auth informational pages — English
|--------------------------------------------------------------------------
| Text for /pricing, /contact, /about and /shipping, rendered by
| resources/views/public/pages/*.blade.php.
|
| NEVER write a company fact here — no phone number, no email address, no
| postal address, no legal entity name, no LLPIN, no price. Every one of
| those is owned elsewhere and is injected by the view:
|
|   company / contact facts -> config/legal.php, read through
|                              App\Support\LegalDocument::replacements()
|   prices and plan names   -> the `plans` / `plan_terms` tables
|   money text              -> App\Support\MoneyFormat
|   percent text            -> App\Support\PercentDisplay
|
| Anything written here is voice, not fact. Changing a phone number must
| never require touching this file.
|
| Digits are Latin 0-9 everywhere, including in lang/mr/public_pages.php.
| lang/mr/public_pages.php must carry every key that exists here.
|
| Customer voice only. Never mention the admin panel, settings, uploads or
| any other internal tool — a signed-out visitor reads these strings.
*/

return [

    /*
    |--------------------------------------------------------------------------
    | Shared chrome
    |--------------------------------------------------------------------------
    */
    'common' => [
        'skip_to_content' => 'Skip to content',
        'pages' => 'Pages',
        'contact_us' => 'Contact us',
        'view_plans' => 'View plans and pricing',
        'read_full_policy' => 'Read the full policy',
        'call' => 'Call',
        'email' => 'Email',
        'address' => 'Registered office',
        'hours' => 'Working hours',
        'entity' => 'Legal entity',
        'llpin' => 'LLPIN',
        'incorporated_on' => 'Incorporated on',
        'jurisdiction' => 'Jurisdiction',
        'website' => 'Website',
    ],

    /*
    |--------------------------------------------------------------------------
    | /pricing
    |--------------------------------------------------------------------------
    */
    'pricing' => [
        'title' => 'Plans and Pricing',
        'summary' => 'Every plan we sell, with the amount you actually pay. Creating a profile and searching is free; a paid plan unlocks features such as revealing a contact number.',
        'meta_description' => 'Membership plans and prices for the Navri Mile Navryala Marathi matrimony platform. All amounts in Indian Rupees.',

        'currency_note' => 'All amounts are in Indian Rupees (INR).',
        'tax_note' => 'Prices shown are inclusive of applicable taxes.',
        'no_hidden_note' => 'The amount under "You pay" is the amount charged. Nothing further is added at checkout.',

        'empty_title' => 'Plans are not published right now',
        'empty_body' => 'We are updating our plan catalogue. Please call or email us and we will tell you the current prices.',

        'popular_badge_hint' => 'Recommended plan',
        'from_label' => 'From',
        'per_label' => 'for',
        'mrp_label' => 'MRP',
        'payable_label' => 'You pay',
        'save_label' => 'Save',
        'duration_label' => 'Duration',
        'all_durations' => 'All durations for this plan',
        'includes_title' => 'Included in this plan',
        'includes_note' => 'Limits shown are for the option priced above. A longer option may carry higher limits.',
        'no_features_note' => 'Feature details are being finalised. Please contact us for the current list.',

        'free_title' => 'Free membership',
        'free_body' => 'Registering a profile and searching costs nothing. The plans below are optional and unlock additional features.',

        'buy_title' => 'How to buy',
        'buy_steps' => [
            'Register a profile with your mobile number and complete it.',
            'Open Plans inside your account and choose the plan and duration you want.',
            'Pay online through our payment gateway. Access to the paid features starts immediately.',
        ],
        'buy_login_cta' => 'Already registered? Sign in to buy a plan',
        'buy_register_cta' => 'Create a free profile',

        'payment_title' => 'Payments, billing and refunds',
        'payment_gateway' => 'Payment gateway',
        'payment_delivery' => 'Delivery',
        'payment_delivery_value' => 'Digital — access begins immediately on successful payment. Nothing is shipped.',
        'payment_refund' => 'Refunds and cancellation',
        'payment_refund_value' => 'Set out in full in our Refund and Cancellation Policy.',
        'payment_terms' => 'Terms of use',
        'payment_terms_value' => 'Buying a plan is subject to our Terms and Conditions.',

        'promise_title' => 'What a paid plan does not do',
        'promise_body' => 'A plan gives you access to features. It does not promise interest, a reply, a meeting or a marriage, and it does not verify what another member has written about themselves.',
    ],

    /*
    |--------------------------------------------------------------------------
    | /contact
    |--------------------------------------------------------------------------
    */
    'contact' => [
        'title' => 'Contact Us',
        'summary' => 'Talk to a real person. Our address, telephone number, email and working hours are below.',
        'meta_description' => 'Address, telephone number, email and working hours for the Navri Mile Navryala matrimony platform, plus our Grievance Officer.',

        'reach_title' => 'Reach us',
        'reach_body' => 'Telephone is the fastest route during working hours. Email is answered on working days.',

        'office_title' => 'Registered office',
        'office_body' => 'This is the registered office of the company on the Registrar of Companies record. Please telephone before visiting.',

        'entity_title' => 'Who you are contacting',

        'grievance_title' => 'Grievance Officer',
        'grievance_body' => 'If something has gone wrong and normal support has not resolved it, write to our Grievance Officer. This officer is published under the Information Technology (Intermediary Guidelines and Digital Media Ethics Code) Rules, 2021.',
        'grievance_officer' => 'Officer',
        'grievance_designation' => 'Designation',
        'grievance_ack' => 'Acknowledgement',
        'grievance_ack_value' => 'Within :hours hours of receiving your complaint.',
        'grievance_resolution' => 'Resolution',
        'grievance_resolution_value' => 'Within :days days of receiving your complaint.',
        'grievance_cta' => 'Read the full grievance redressal procedure',

        'map_title' => 'Find us',

        'social_title' => 'Follow us',

        'support_note' => 'Please keep your registered mobile number ready. For a payment question, also keep the transaction reference and the date and amount to hand.',
    ],

    /*
    |--------------------------------------------------------------------------
    | /about
    |--------------------------------------------------------------------------
    */
    'about' => [
        'title' => 'About Us',
        'summary' => 'Who runs this platform, what it does, and — just as importantly — what it does not do.',
        'meta_description' => 'The company behind the Navri Mile Navryala Marathi matrimony platform, the service it provides, and its registered details.',

        'entity_title' => 'The company behind this platform',
        'entity_body' => 'This website and the Navri Mile Navryala mobile applications are operated by the limited liability partnership named below, registered with the Registrar of Companies, Pune. The details are the ones on the Registrar\'s record.',

        'service_title' => 'What the service does',
        'service_body' => 'We run a Marathi matrimonial matchmaking platform — a website and two mobile applications, one for members and one for Suchaks.',
        'service_list' => [
            'A member or their family creates a profile, adds photographs and horoscope details, and states what they are looking for.',
            'Members search and are shown profiles, express interest, and chat once an interest is accepted.',
            'Contact numbers stay hidden until the owner of that number allows them to be shared.',
            'A paid plan unlocks additional features, such as revealing a contact number more often.',
            'Both a Marathi and an English interface are available; you can switch language at any time.',
        ],

        'suchak_title' => 'Suchaks',
        'suchak_body' => 'A Suchak is an independent matchmaker who works with families and brings biodata onto the platform on their behalf. Suchaks are not our employees or agents. Any service fee, meeting fee or success fee a family agrees with a Suchak is a matter between the family and that Suchak, and money paid directly to a Suchak does not pass through us.',

        'limits_title' => 'What we do not do',
        'limits_body' => 'We would rather say this plainly on this page than leave you to discover it later.',
        'limits_list' => [
            'We do not verify what a member writes about themselves — name, age, education, job, income, property, health or family details. Please confirm everything independently.',
            'We do not promise interest, a reply, a meeting or a marriage. A paid plan does not change that.',
            'We are not a party to any negotiation between two families, and we take no part in dowry, gifts or any financial arrangement.',
            'The platform publishes Platform-generated Showcase Profiles that do not correspond to a real person. A view or an interest is not by itself proof that a real person is interested in you.',
        ],
        'limits_cta' => 'Read the full disclaimer',

        'money_title' => 'How we make money',
        'money_body' => 'Registration and search are free. We earn from optional membership plans bought by members and from platform subscriptions bought by Suchaks. All amounts are charged in Indian Rupees through our payment gateway, and access to a paid feature starts as soon as payment succeeds.',
        'money_cta' => 'See plans and pricing',

        'safety_title' => 'Safety and privacy',
        'safety_body' => 'Contact numbers are released only with the consent of the person who owns them. Every consent is recorded. You can ask us to delete your account and data, and our Privacy Policy explains what we keep, for how long, and why.',

        'contact_title' => 'Talk to us',
        'contact_body' => 'Our address, telephone number and email are published in full on the contact page, along with our Grievance Officer.',
    ],

    /*
    |--------------------------------------------------------------------------
    | /shipping
    |--------------------------------------------------------------------------
    */
    'shipping' => [
        'title' => 'Shipping and Delivery',
        'summary' => 'Nothing on this platform is physically shipped. Everything we sell is a digital service delivered to your account.',
        'meta_description' => 'Delivery policy: plans on the Navri Mile Navryala platform are digital services activated immediately on payment. Nothing is physically shipped.',

        'quoted_from' => 'Quoted from clause :clause of our :policy.',
        'source_note' => 'It is kept in one place so that this page and that policy can never say different things.',

        'digital_title' => 'There is no physical delivery',
        'digital_body' => 'We do not sell goods. There is no courier, no consignment, no tracking number and no delivery address. We do not ask you for a shipping address and we do not charge a delivery fee.',

        'activation_title' => 'How your plan is delivered',
        'activation_list' => [
            'Delivery method — the plan is activated on the same account that paid for it.',
            'Delivery time — immediately on successful payment. No waiting period.',
            'Where you receive it — the paid features appear in your account on this website and in the mobile applications, on any device you sign in from.',
            'Validity — for the duration stated on the plan you chose.',
        ],

        'problem_title' => 'If your plan did not activate',
        'problem_body' => 'If money was debited but the paid features did not appear, tell us. We will either activate the plan or refund the amount in full — whichever you prefer. Keep your registered mobile number and the transaction reference to hand.',

        'refund_title' => 'Cancellation and refunds',
        'refund_body' => 'Because there is no shipment, there is no return. Cancellation and refunds are governed entirely by our Refund and Cancellation Policy, which sets out when we refund, when we do not, and how long it takes.',
    ],
];
