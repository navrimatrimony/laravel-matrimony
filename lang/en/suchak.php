<?php

return [
    'nav' => [
        'centre' => 'Suchak Centre',
        'dashboard' => 'Suchak Dashboard',
        'create_intake_source' => 'Create Intake Source',
        'masked_search' => 'Masked Search',
        'registration' => 'Suchak Registration',
        'login' => 'Suchak Login',
    ],
    'register' => [
        'back' => 'Back to Suchak Centre',
        'title' => 'Suchak Registration',
        'intro' => 'WhatsApp number will be the primary login and OTP number. Extra numbers can be added later from the Suchak panel.',
        'already_registered' => 'Already registered? Login',
        'fix_information' => 'Please fix this information:',
        'separate_account_title' => 'Separate account required',
        'separate_account_body' => 'You are logged in as a regular member. Existing member accounts cannot be converted to Suchak. Please log out and create a separate account for Suchak.',
        'logout_register' => 'Logout and register as Suchak',
        'steps_label' => 'Suchak registration steps',
        'step_info' => '1. Information and password',
        'step_proof' => '2. Proof upload',
        'admin_review_note' => 'After OTP verification, the request will go to admin review.',
        'suchak_name' => 'Suchak name',
        'business_type' => 'Work type',
        'office_name' => 'Office / Bureau name',
        'employee_count' => 'How many people work in the bureau',
        'whatsapp_number' => 'Primary WhatsApp number',
        'whatsapp_placeholder' => '10 digit WhatsApp number',
        'whatsapp_help' => 'OTP will be sent on this number and it will be used as the login mobile.',
        'email' => 'Email',
        'optional' => 'Optional',
        'address' => 'Address',
        'address_placeholder' => 'Office / home address, road, landmark',
        'office_location' => 'Office area / city',
        'office_location_placeholder' => 'Type village, city or area',
        'select_office_location' => 'Select office area or city from the location list.',
        'location_icon' => 'Location',
        'password' => 'Password',
        'confirm_password' => 'Confirm password',
        'password_help' => 'Use a strong password.',
        'password_match_help' => 'Both passwords must match.',
        'proof_title' => 'Upload document to verify your identity',
        'proof_body' => 'Upload a clear photo/PDF of Aadhaar card or Passport for Suchak account verification. Name and number should be readable on the document.',
        'identity_document' => 'Aadhaar card / Passport upload',
        'identity_help' => 'Allowed: PDF/JPG/PNG, max 5 MB. Upload only identity proof.',
        'office_document' => 'Office / business proof',
        'office_document_help' => 'Required only for a Marriage Bureau.',
        'proof_rule' => 'Office document is not required for Individual Suchak. Office proof is required when Marriage Bureau is selected. WhatsApp OTP verification will happen after submit.',
        'back_step' => 'Back',
        'separate_account_note' => 'Suchak account will remain separate from regular member account.',
        'continue_proof' => 'Continue to proof upload',
        'submit' => 'Register Free',
        'already_have_account' => 'Already have an account?',
        'login_here' => 'Login here',
        'login_title' => 'Suchak login',
        'login_intro' => 'Use your Suchak login mobile, email, or username to continue.',
        'login_identifier' => 'Mobile / Email / Username',
        'login_identifier_placeholder' => 'Mobile, email, or username',
        'login_identifier_help' => 'Use the WhatsApp number used during Suchak registration, or your email/username.',
        'login_submit' => 'Login',
        'forgot_password' => 'Forgot your password?',
        'new_suchak_register' => 'New Suchak? Register here',
        'mobile_already_verified' => 'WhatsApp number is already verified. Continue to registration status.',
        'otp_verified_auto_approved' => 'OTP verified. You can use allowed Suchak tools now; admin will still review your profile and KYC.',
        'otp_verified_waiting_approval' => 'OTP verified. You can continue Suchak setup and work while admin review remains pending.',
        'otp_delivery_dev_show' => 'OTP generated for testing. Enter the displayed 6 digit code.',
        'otp_delivery_whatsapp' => 'OTP sent on your WhatsApp number.',
        'otp_delivery_disabled' => 'OTP delivery is disabled. Continue with the displayed 6 digit code.',
        'otp_delivery_default' => 'OTP is ready. Enter the 6 digit code to continue.',
    ],
    'status' => [
        'back' => 'Back to Suchak Centre',
        'title' => 'Suchak Request Status',
        'intro' => 'Track what is complete, where your request is now, and what will happen next.',
        'open_dashboard' => 'Open Dashboard',
        'setup_profile_cta' => 'Complete Suchak profile setup',
        'work_allowed_review_pending_badge' => 'Work allowed, review pending',
        'admin_review_badge' => 'Admin review: :status',
        'pipeline_label' => 'Suchak pipeline',
        'pipeline_steps_title' => 'Steps',
        'active_step' => 'Active step',
        'you_are_here' => 'You are here',
        'verify_otp' => 'Verify OTP',
        'send_new_otp' => 'Send new OTP',
        'otp_input_label' => 'Enter 6 digit OTP',
        'next_title' => 'What happens next',
        'next_label' => 'Next:',
        'photo_upload_intro' => 'Upload your Suchak photo. This photo appears on your Suchak profile/card and helps customers recognise and trust you.',
        'photo_upload_box_label' => 'Photo upload',
        'photo_upload_help' => 'Use a clear JPG, PNG, or WebP photo. Crop it before upload. Maximum 5 MB.',
        'upload_photo' => 'Upload photo',
        'photo_page_title' => 'Upload Suchak photo',
        'photo_page_intro' => 'Crop your photo here. It will be submitted for review before it appears on your Suchak card.',
        'upload_photo_for_review' => 'Upload photo for review',
        'uploading_photo' => 'Uploading photo...',
        'photo_select_error' => 'Please select a Suchak photo first.',
        'photo_upload_failed' => 'Suchak photo upload failed. Please try again.',
        'photo_upload_success' => 'Photo submitted for review. It will appear after admin approval.',
        'photo_review_note' => 'This photo is reviewed before it appears publicly on your Suchak card.',
        'photo_secure_note' => 'This upload uses the shared crop and review flow. It does not create a candidate profile.',
        'photo_rejected' => 'Photo was rejected. Please upload a clearer photo.',
        'back_to_status' => 'Back to request status',
        'kyc_title' => 'KYC documents',
        'kyc_intro' => 'Only uploaded verification documents are shown here. System audit records stay out of this list.',
        'kyc_intro_short' => 'Upload documents needed to verify your identity and Suchak work.',
        'required_identity_proof' => 'Identity proof',
        'required_office_proof' => 'Office / business proof, if applicable',
        'ready_work_title' => 'Ready to work',
        'ready_work_body' => 'Your Suchak profile is ready. Admin review will continue in the background. If anything is missing, we will tell you here.',
        'ready_work_next' => 'You can now add customer profiles, collect consent, and start matchmaking work.',
        'add_customer_profile' => 'Add customer profile',
        'open_suchak_dashboard' => 'Open Suchak Dashboard',
        'uploaded_count' => '{0} 0 uploaded|{1} 1 uploaded|[2,*] :count uploaded',
        'document_uploaded' => 'Uploaded',
        'document_not_uploaded' => 'Not uploaded',
        'required' => 'Required',
        'optional' => 'Optional',
        'upload_document' => 'Upload document',
        'replace_document' => 'Replace document',
        'document_upload_success' => 'Document uploaded. Admin review status is updated to pending.',
        'work_area_title' => 'Automatic work area',
        'work_area_intro' => 'Work area is calculated from valid consented customers. An area is earned after at least :minimum customers from that area.',
        'work_area_empty' => 'No work area is earned yet. Add customers and complete consent for at least :minimum customers from the same area.',
        'consented_customers_count' => ':count consented customers',
        'area_customer_count' => ':count consented customers',
        'area_earned' => 'Earned',
        'area_building' => ':count more needed',
        'step_states' => [
            'complete' => 'Complete',
            'submitted' => 'Submitted for review',
            'in_progress' => 'In progress',
            'current' => 'Current',
            'blocked' => 'Needs attention',
            'upcoming' => 'Upcoming',
        ],
        'step_actions' => [
            'verify_otp' => 'Verify OTP',
            'upload_photo' => 'Upload photo',
            'upload_documents' => 'Upload documents',
            'view_documents' => 'View documents',
            'view_status' => 'View status',
            'open_dashboard' => 'Open dashboard',
        ],
        'steps' => [
            'registration' => [
                'label' => 'Registration submitted',
                'detail' => 'Basic account is created.',
                'body' => 'Your Suchak account, login mobile, address, and business type are saved in Suchak account records only.',
            ],
            'submitted' => [
                'label' => 'Registration submitted',
                'detail' => 'Your Suchak request is created.',
            ],
            'otp' => [
                'label' => 'WhatsApp OTP',
                'detail' => 'Primary number verification.',
                'body' => 'Verify the primary WhatsApp number. This confirms the login number and keeps the next communication on one reliable channel.',
            ],
            'profile_photo' => [
                'label' => 'Suchak photo',
                'detail' => 'Photo for your profile/card.',
                'body' => 'Upload your Suchak photo so customers can recognise you on your profile/card.',
            ],
            'documents' => [
                'label' => 'KYC documents',
                'detail' => 'Identity and work proof.',
                'body' => 'Upload identity proof and office or business proof when applicable.',
                'submitted_body' => 'Documents are submitted. Admin review will continue in the background.',
            ],
            'kyc' => [
                'label' => 'KYC upload',
                'detail' => 'Identity and business proof.',
            ],
            'admin_review' => [
                'label' => 'Admin review',
                'detail' => 'Approval or correction.',
                'body' => 'Admin review can continue in parallel. This step becomes complete only after an admin reviews the account.',
                'submitted_body' => 'Allowed work is open for you, but admin review is still pending. Admin may verify or ask for correction later.',
            ],
            'review' => [
                'label' => 'Admin review',
                'detail' => 'Documents and details checked.',
            ],
            'card' => [
                'label' => 'Suchak card',
                'detail' => 'Photo and share card.',
                'body' => 'Review your public card details. Photo is optional, but adding one helps when sharing your Suchak information on WhatsApp.',
            ],
            'work_area' => [
                'label' => 'Work area',
                'detail' => 'Calculated from consented customers.',
                'body' => 'You do not select a work area manually. The system calculates it from customers who have valid consent in that area.',
            ],
            'work_start' => [
                'label' => 'Start Suchak work',
                'detail' => 'Use dashboard tools.',
                'body' => 'Use allowed dashboard tools to add customers, upload biodata, manage follow-up, and build consent evidence.',
            ],
            'ready_work' => [
                'label' => 'Ready to work',
                'detail' => 'Start customer work.',
                'body' => 'Your Suchak profile is ready. Start adding customer profiles and collecting consent.',
            ],
            'approval' => [
                'label' => 'Admin decision',
                'detail' => 'Approved or correction needed.',
            ],
            'work' => [
                'label' => 'Start Suchak work',
                'detail' => 'Use dashboard tools.',
            ],
        ],
        'messages' => [
            'otp_pending' => [
                'title' => 'WhatsApp OTP verification is pending',
                'body' => 'Your Suchak request is created. Verify the primary WhatsApp number so admin review can continue.',
                'next' => 'After OTP verification, your KYC documents move into admin review.',
                'action' => 'Verify the OTP sent to your primary WhatsApp number.',
            ],
            'kyc_pending' => [
                'title' => 'KYC upload is still pending',
                'body' => 'Your WhatsApp number is verified. Upload the required identity or office proof to continue review.',
                'next' => 'After required documents are available, admin can approve or ask for correction.',
                'action' => 'Upload the required proof documents.',
            ],
            'photo_pending' => [
                'title' => 'Suchak photo is pending',
                'body' => 'Your WhatsApp number is verified. Upload your Suchak photo before KYC documents.',
                'next' => 'After the photo is uploaded, you can upload KYC documents.',
                'action' => 'Upload a clear Suchak photo.',
            ],
            'review_pending' => [
                'title' => 'Documents submitted, KYC pending by admin',
                'body' => 'Your OTP and required documents are in place. Admin approval can continue while you use the allowed Suchak dashboard sections.',
                'next' => 'Admin will approve the account or send a correction note if something is unclear.',
                'action' => 'No extra onboarding step is required from you unless admin asks for correction.',
            ],
            'work_allowed_review_pending' => [
                'title' => 'You can work now; admin review is pending',
                'body' => 'Your Suchak work tools are open. Admin has not yet completed final profile and KYC verification, so the status stays pending-review instead of green.',
                'next' => 'Complete your Suchak profile setup and start adding customer entries. Admin review can continue in parallel.',
                'action' => 'Add your photo, visiting card or office proof, and then create your first customer profile.',
            ],
            'ready' => [
                'title' => 'Ready to work',
                'body' => 'Your Suchak profile is ready. Admin review will continue in the background.',
                'next' => 'Add customer profiles, collect consent, and start matchmaking work.',
                'action' => 'Open the dashboard.',
            ],
            'blocked' => [
                'title' => 'This request needs correction',
                'body' => 'A required document or account status needs attention. Upload the corrected document or contact admin.',
                'next' => 'Admin review can continue after the correction is available.',
                'action' => 'Check document remarks and upload a corrected file.',
            ],
            'rejected' => [
                'title' => 'Request needs admin attention',
                'body' => 'This Suchak request was rejected. Check remarks and contact admin before trying again.',
                'next' => 'Admin can explain what needs correction.',
                'action' => 'Review document remarks and contact platform admin.',
            ],
            'suspended' => [
                'title' => 'Suchak account is suspended',
                'body' => 'Your account is paused by admin. Suchak work will stay blocked until the suspension is resolved.',
                'next' => 'Admin must review and reactivate the account.',
                'action' => 'Contact admin with the required clarification.',
            ],
            'archived' => [
                'title' => 'Suchak request is archived',
                'body' => 'This request is no longer active in the onboarding pipeline.',
                'next' => 'Admin can tell you whether a fresh request is needed.',
                'action' => 'Contact admin before submitting again.',
            ],
        ],
        'summary' => [
            'whatsapp_otp' => 'WhatsApp OTP',
            'business_type' => 'Business type',
            'public_status' => 'Public status',
            'submitted' => 'Submitted',
            'kyc_upload' => 'KYC upload',
            'admin_review' => 'Admin review',
        ],
        'document_types' => [
            'profile_photo' => 'Suchak photo',
            'identity' => 'Identity proof',
            'office' => 'Office proof',
            'business' => 'Business proof',
            'phone' => 'Phone verification',
            'other' => 'Other verification',
        ],
        'document_help' => [
            'identity' => 'Aadhaar card, passport, or other readable identity proof.',
            'office' => 'Visiting card, office address proof, or bureau letterhead.',
            'business' => 'Bureau registration, authorization letter, or institution document.',
        ],
    ],
    'business_types' => [
        'individual' => 'Individual Suchak',
        'bureau' => 'Marriage Bureau',
    ],
    'manual_profile' => [
        'consent_relation' => 'Whose mobile is this?',
        'consent_relation_hint' => 'Their relation to the candidate — this is who consent will be requested from.',
        'consent_relation_self' => 'Candidate themselves',
        // Consent-first linking (2026-07-26).
        'existing_profile_consent_required' => 'This person already has a profile. Confirm to ask for their consent — they will be added to your customers only after they accept.',
        'consent_requested' => 'Consent request is ready. This person joins your customer list only after they accept.',
        'represented_by_other_suchak' => 'This customer is already with another Suchak.',
        'represented_by_other_suchak_named' => 'This customer is already with :suchak.',
    ],

    'represented_profile' => [
        'consent_required_for_edit' => 'You can edit this profile only after consent is granted. This person already exists on the platform, so their approval is required first.',
    ],

    'consent' => [
        'mobile_required' => 'A mobile number is required to request consent.',
        'profile_missing' => 'The profile for this customer could not be found.',
        'mobile_not_on_profile' => 'Consent can only be requested on a number already recorded on that person\'s profile. Add the number to the profile first, then request consent.',

        /*
         * Who the consent is being asked of.
         *
         * This list existed THREE times: an English array in
         * resources/views/suchak/dashboard.blade.php, a second English array as
         * the default in resources/views/suchak/partials/consent-action-modal.blade.php,
         * and a Marathi array beside it in the same partial that overrode both.
         * Keys are ConsentController::CONSENT_GIVER_RELATIONS, so the stored
         * value and the label the Suchak picked always come from one list.
         */
        'relations' => [
            'candidate_self' => 'Candidate themselves',
            'father' => 'Father',
            'mother' => 'Mother',
            'brother' => 'Brother',
            'sister' => 'Sister',
            'guardian' => 'Guardian',
            'other_family' => 'Other family member',
        ],

        /*
         * The Suchak-side "get consent" modal. Suchak-facing rather than
         * customer-facing, but it was carrying its own en/mr array literal —
         * a second translation mechanism beside __(), which meant an admin
         * could not correct this wording from the translations table.
         */
        'modal' => [
            'trigger' => 'Get consent',
            // Same button, one consent later.
            'trigger_renew' => 'Renew consent',
            'eyebrow' => 'Get consent',
            'title' => 'Create customer consent request',
            'intro' => 'Default mobile is editable before the request is created.',
            'close' => 'Close',
            'consent_type' => 'Consent type',

            'whatsapp_title' => 'Send via WhatsApp',
            'whatsapp_body' => 'Platform creates a secure consent link and ready message. Suchak sends it from their WhatsApp to the customer/family.',
            // One key, because the card heading and the "other options" link
            // were word-for-word identical in both languages.
            'offline_title' => 'Upload signed proof',
            'offline_body' => 'Use this only when the customer/family has already signed or provided offline proof for this profile.',
            'platform_title' => 'Platform-assisted consent',
            'platform_body' => 'Platform creates a secure consent request. Use this when platform-side follow-up is preferred.',

            'giver_name' => 'Consent giver name',
            'relation' => 'Relation',
            'requested_mobile' => 'Requested mobile',
            'mobile_help' => 'Defaults to the mobile kept for this profile. Suchak can change it.',
            'signed_file' => 'Signed proof file',
            'declaration' => 'I confirm this proof was given by the customer/family for this represented profile.',

            'send_whatsapp' => 'Send on WhatsApp',
            'upload_proof' => 'Upload signed proof',
            'create_request' => 'Create request',
            'other_options' => 'Other consent options',
        ],
    ],
    'match_suggestions' => [
        'loaded' => 'Match suggestions loaded.',
        'decision_saved' => 'Your decision has been saved.',
        'not_suggested' => 'This candidate was never suggested for this customer.',
        'representation_not_found' => 'Representation not found for this Suchak account.',
        'account_not_allowed' => 'Only active Suchak accounts can see match suggestions.',
        'consent_required' => 'Match suggestions open only after consent is granted. This person already exists on the platform, so their approval is required first.',
        'validation' => [
            'limit_invalid' => 'Choose how many suggestions to load between 1 and 50.',
            'include_seen_invalid' => 'Invalid value for showing already seen suggestions.',
            'decision_required' => 'Select what you decided about this candidate.',
            'decision_invalid' => 'This decision is not allowed. Choose chosen, rejected or ignored.',
            'rejection_reason_required' => 'Select a reason for the rejection.',
            'rejection_reason_invalid' => 'This rejection reason is not allowed.',
            'note_too_long' => 'The note can be at most 500 characters.',
        ],
    ],

    'labels' => [
        'common' => [
            'active' => 'Active',
            'accepted' => 'Accepted',
            'active_profile_limit' => 'Active customer profiles',
            'admin' => 'Admin',
            'admin_assisted' => 'Admin assisted',
            'admin_review' => 'Admin review',
            'approved' => 'Approved',
            'archived' => 'Archived',
            'bureau' => 'Marriage Bureau',
            'bulk_upload_access' => 'Bulk biodata upload',
            'cancelled' => 'Cancelled',
            'city' => 'City',
            'collaboration_request_limit' => 'Open collaboration requests',
            'completed' => 'Completed',
            'crm_features' => 'Customer follow-up tools',
            'declined' => 'Declined',
            'disabled' => 'Disabled',
            'draft' => 'Draft',
            'enabled' => 'Enabled',
            'expired' => 'Expired',
            'failed' => 'Failed',
            'hidden' => 'Hidden',
            'inactive' => 'Inactive',
            'individual' => 'Individual Suchak',
            'lead_request_limit' => 'Open customer lead requests',
            'ledger_features' => 'Payment record book',
            'manual_assignment' => 'Manual assignment',
            'manual_assignment_price_not_configured' => 'Manual assignment / price not configured',
            'manual_catalog' => 'Manual catalog',
            'manual_review' => 'Manual review',
            'monthly_upload_limit' => 'Monthly biodata uploads',
            'no' => 'No',
            'none' => 'None',
            /*
             * Generic "we have nothing to show in this slot" wording.
             * Promoted out of `customer_portal.show.*` when the public consent
             * page and PublicConsentController needed the SAME two words: a
             * per-page copy of "Not available" is exactly the duplication the
             * frozen rule forbids.
             */
            'not_available' => 'Not available',
            'to_be_confirmed' => 'To be confirmed',
            'not_requested' => 'Not requested',
            'paid' => 'Paid',
            'payu_test_mode' => 'PayU test mode',
            'paused' => 'Paused',
            'pending' => 'Pending',
            'pending_review' => 'Pending review',
            'pdf_download_share_limit' => 'Daily biodata PDF / QR shares',
            'priority_support' => 'Priority admin support',
            'published' => 'Published',
            'rejected' => 'Rejected',
            'requested' => 'Requested',
            'revoked' => 'Revoked',
            'scheduled' => 'Scheduled',
            'sent' => 'Sent',
            'suspended' => 'Suspended',
            'system' => 'System',
            'user' => 'User',
            'verified' => 'Verified',
            'whatsapp_copy' => 'WhatsApp copy',
            'whatsapp_deep_link' => 'WhatsApp deep link',
            'yes' => 'Yes',
        ],

        /*
         * Group-specific status wording. SuchakLocalizedText::label() checks the
         * group first and falls back to `common`, so the same raw token can read
         * correctly in each domain ("pending" as a representation is not
         * "pending" as a payment). Shown to Suchaks on the customer list.
         */
        'unknown' => 'Unknown status',

        // SuchakProfileRepresentation::STATUSES
        'representation' => [
            'pending' => 'Waiting',
            'consent_pending' => 'Consent pending',
            'active' => 'Active',
            'revoked' => 'Consent withdrawn',
            'expired' => 'Expired',
            'rejected' => 'Rejected',
            'suspended' => 'Temporarily stopped',
            'candidate_deactivated' => 'Stopped by candidate',
        ],

        // SuchakProfileRepresentation::CONSENT_STATUSES plus the extra
        // SuchakConsent::STATUSES steps of the consent flow itself — one consent
        // vocabulary shared by the customer list, the customer detail endpoint
        // and the pending-consent list.
        'consent' => [
            'not_requested' => 'Consent not requested',
            'requested' => 'Consent requested',
            'link_opened' => 'Link opened',
            'otp_sent' => 'OTP sent',
            'otp_verified' => 'OTP verified',
            'accepted' => 'Consent given',
            'rejected' => 'Consent declined',
            'expired' => 'Consent expired',
            'cancelled' => 'Consent request cancelled',
            'suchak_declared' => 'Consent declared by Suchak',
            'revoked' => 'Consent withdrawn',
        ],

        // SuchakBiodataIntakeLink::STATUS_* — a scanned biodata that is not a
        // customer yet, so the wording says what it is AND what is left to do.
        'intake_source' => [
            'intake_uploaded' => 'Biodata received, profile pending',
            'intake_parsed' => 'Details read, review pending',
            'review_pending' => 'Review pending',
            'linked_to_existing_profile' => 'Linked to existing profile',
            'created_new_profile' => 'New profile created',
            'duplicate_pending_consent' => 'Already on record, consent pending',
            'cancelled' => 'Cancelled',
        ],

        // MatrimonyProfile::LIFECYCLE_STATES
        'lifecycle' => [
            'draft' => 'Draft',
            'intake_uploaded' => 'Biodata received',
            'parsed' => 'Details read',
            'awaiting_user_approval' => 'Awaiting candidate approval',
            'approved_pending_mutation' => 'Approved, changes pending',
            'conflict_pending' => 'Details conflict',
            'active' => 'Active',
            'suspended' => 'Suspended',
            'archived' => 'Archived',
            'archived_due_to_marriage' => 'Archived (married)',
        ],

        // BiodataIntake::parse_status
        'intake_parse' => [
            'pending' => 'Reading pending',
            'parsed' => 'Details read',
            'error' => 'Could not read',
        ],

        /*
         * SuchakCollaborationStageEvent::STAGE_LADDER — the marketplace stage
         * vocabulary (blueprint 6a), and the ONLY place its wording lives.
         *
         * Read through SuchakCollaborationStageEvent::stageLabel(), which every
         * `stage_label` / `trigger_stage_label` / `evidence_stage_label` /
         * `anchor_stage_label` / `released_by_stage_label` field in the Suchak
         * API payloads goes through. The keys ARE the ladder keys, so a rung
         * added to the ladder without wording here reports as its raw key —
         * visible and reportable, never blank.
         *
         * The last four read as trigger points ("Once …", "After …") rather
         * than as past events, because the same words label a success-fee
         * installment's trigger as well as a rung. One vocabulary, so the
         * wording has to carry both readings.
         */
        'stage_ladder' => [
            'registration' => 'Registration',
            'agreement_proposed' => 'Agreement sent',
            'agreement_accepted' => 'Agreement accepted',
            'published_to_marketplace' => 'Published to marketplace',
            'profile_suggested' => 'Profile suggested',
            'viewed' => 'Profile viewed',
            'interested' => 'Interest shown',
            'meeting_scheduled' => 'Meeting arranged',
            'meeting_completed' => 'Meeting held',
            'meeting_confirmed' => 'Meeting confirmed',
            'marriage_settled' => 'Once the match is settled',
            'engagement' => 'After the engagement',
            'marriage' => 'After the marriage',
            'share_settled' => 'Once the share is paid',
        ],
    ],
    /*
     * Every sentence the Suchak API says back to a caller.
     *
     * These were Marathi string literals inside the controllers, so a Suchak
     * whose app was in English still got Marathi refusals and confirmations —
     * SetApiLocale had already worked out the caller's language and nothing
     * used the answer. They also repeated: "सूचक खाते आवश्यक आहे." was written
     * out ELEVEN times across eleven controllers, so the eleven copies were
     * free to drift into eleven slightly different refusals for one rule.
     * One key each, both languages, one place to correct the wording.
     */
    'api' => [
        // Refusals. Each names WHY, never merely "not found", because a Suchak
        // who cannot tell "does not exist" from "not yours" retries forever.
        'errors' => [
            'suchak_account_required' => 'A Suchak account is required.',
            'verified_suchaks_only' => 'Only verified Suchaks can see this information.',
            'agreement_not_found' => 'That agreement is not in your account.',
            'profile_not_found' => 'That profile is not in your account.',
            'engagement_not_found' => 'That collaboration is not in your account.',
            'customer_not_found' => 'That customer is not in your account.',
            'challenge_not_found' => 'That challenge is not in your account.',
            'obligation_not_found' => 'That record is not in your account.',
            'payment_not_found' => 'That payment is not in your account.',
            'tranche_not_found' => 'That installment is not in this collaboration\'s agreement.',
            'marriage_outcome_not_found' => 'No marriage has been recorded for this collaboration yet.',
            'customer_payment_owner_only' => 'A customer\'s payment can only be recorded by that customer\'s own Suchak.',
        ],

        'stage' => [
            'recorded' => 'Stage recorded.',
            'family_confirmed' => 'Your confirmation has been recorded.',

            // Both refusals a CONFIRMER can actually run into, and the family reads them on the
            // customer portal now that the portal has a confirm door — so they cannot stay English
            // literals in the service the way the developer-facing row invariants do.
            'not_claimed_yet' => 'Nobody has recorded this stage yet, so there is nothing to confirm.',
            'already_confirmed' => 'This stage has already been confirmed.',
        ],

        'agreement' => [
            'linked_to_engagement' => 'Agreement linked to this collaboration.',
            'link_created' => 'The agreement link is ready.',

            // Why this agreement cannot be sent, by the state it is actually in.
            'already_accepted' => 'The customer has already accepted this agreement.',
            'superseded' => 'This agreement is no longer in use. Create and send a new one.',
            'declined' => 'The customer declined this agreement. Create and send a new one.',
            'expired' => 'This agreement has expired. Create and send a new one.',
            'not_required' => 'This agreement does not need the customer\'s acceptance.',
            'services_changed' => 'The service details have changed. Create and send a new agreement.',
            'not_permitted' => 'You are not allowed to send this agreement.',
            'cannot_send' => 'A link cannot be sent for this agreement right now.',
        ],

        'obligation' => [
            'declared_due' => 'The declared share has been recorded as due.',
            'settlement_recorded' => 'Receipt of the share has been recorded.',
        ],

        'challenge' => [
            'published' => 'The challenge has been published to the marketplace.',
            'withdrawn' => 'The challenge has been withdrawn.',
            'proposal_sent' => 'Profile suggested. Now wait for the publishing Suchak to respond.',
        ],

        'marriage' => [
            'recorded' => 'The marriage has been recorded.',
        ],

        'meeting' => [
            'dispute_recorded' => 'Dispute recorded. The amount is held until the review is complete.',
        ],

        'success_fee' => [
            'tranches_released' => 'Success-fee installments recorded against the latest stages.',
            'payment_recorded' => 'The installment payment has been recorded.',
        ],

        /*
         * Refusals for re-sending a plan whose terms the customer already
         * accepted. Each names WHAT the customer is holding, because a Suchak
         * looking at a WhatsApp message quoting a different figure cannot act
         * on "already accepted" alone.
         *
         * Amounts arrive pre-formatted through MoneyFormat (Latin digits,
         * Indian grouping) and are injected as :changes / :tranches, so the
         * money formatting is decided in exactly one place and is the same in
         * both languages.
         */
        'plan' => [
            'tranche_change_refusal' => 'This customer has already accepted this plan\'s terms, so the same plan cannot be re-sent with different success-fee installments. The installments the customer currently holds — :tranches. The accepted agreement stays exactly as it is. To apply new installments, create and send a plan under a different name.',
            'tranche_none' => 'no installments are set',
            'terms_change_refusal' => 'This customer has already accepted this plan\'s terms, so the same plan cannot be re-sent with different fees (:changes). The accepted agreement stays exactly as it is. To apply new fees, create and send a plan under a different name.',
            'post_marriage_mode_changed' => 'type of post-marriage fee',

            /*
             * What the bypass row will say happened. Written as a declaration
             * BY the Suchak, never as the customer's act — that distinction is
             * the entire reason this goes to bypass instead of acceptance.
             *
             * NOTE: unlike everything else here this string is STORED on the
             * agreement, so it freezes the language the declaring Suchak's app
             * was in. That is the right behaviour for an audit record — it says
             * what was declared, at the time, in the words it was declared in —
             * but it does mean the reason column will not follow a later
             * language switch.
             */
            'offline_agreement_reason' => 'Recorded by the Suchak: the customer accepted this agreement in person / offline. The customer did not use the online acceptance link.',
            'offline_agreement_reason_with_fee' => 'Recorded by the Suchak: the customer accepted this agreement in person / offline (service fee: :fee). The customer did not use the online acceptance link.',
        ],
    ],

    /*
     * Success-fee installments (blueprint §7.4, T1–T4).
     *
     * These MUST live here rather than in the service, and for a sharper reason
     * than tidiness: most of them quote a stage name, and stage names are now
     * locale-aware. A Marathi sentence with `stageLabel()` spliced into it
     * produced things like `"Meeting held" या टप्प्यावर हप्ता ठेवता येणार नाही`
     * — half English, half Marathi, in a refusal about money.
     */
    'tranche' => [
        'row_incomplete' => 'The installment details are incomplete.',
        'invalid_stage' => 'The stage the installment is tied to is not valid.',
        'stage_not_releasable' => 'An installment cannot be placed on ":stage". A success-fee installment can only sit on the stages the customer confirms: :stages.',
        'duplicate_stage' => 'Two installments cannot sit on the same stage.',
        'order_mismatch' => 'Installments must follow the same order as the stages.',
        'one_remainder_only' => 'Only one installment can be the remaining amount.',
        'remainder_must_be_last' => 'The remaining-amount installment must be the last one.',
        'percent_must_total_100' => 'The installment percentages must add up to 100%. They currently add up to :percent%.',
        'sum_mismatch' => 'The installments do not add up to the total fee.',
        'first_should_be_smallest' => 'It is better to keep the first installment the smallest — it is earned on the least evidence.',
        'no_success_fee' => 'Installments cannot be set when no success fee has been agreed.',

        'released_cannot_remove' => 'The installment for ":stage" has already been released; it cannot be dropped from a new split.',
        'released_cannot_recut' => 'The installment for ":stage" has already been released; its percentage can no longer be changed. Later installments that have not been released can still be changed.',

        'blocked_terms_pending' => 'This installment does not apply until the customer accepts the agreement.',
        'blocked_stage_never_releases' => '":stage" is not a stage the customer confirms, so an installment on this stage will never be released. In a new split, move this installment to one of :stages.',
        'blocked_stage_already_charged' => 'The installment for ":stage" has already been released under another plan for this same customer; the same stage will not be charged twice.',
        'blocked_family_allowance' => ':committed of this family\'s success fee has already been charged under another plan. Of the :total on this agreement only :remaining is left, so this installment of :amount will not be released. A success fee is taken from each customer only once.',
        'blocked_no_tranche_until_accept' => 'No success-fee installment applies until the customer accepts the agreement.',
        'no_agreement_linked' => 'No customer agreement is linked to this collaboration, so no installment can be released.',

        'settle_not_released' => 'A payment cannot be recorded against an installment that has not been released yet.',
        'settle_payment_not_this_agreement' => 'This payment does not belong to this agreement.',
        'settle_payment_incomplete' => 'An installment cannot be marked paid while the payment itself is not recorded as complete.',
        'settle_already_bound' => 'This installment is already tied to another payment.',
        'settle_exceeds_receipt' => 'This installment is :tranche; of the :receipt in this payment only :remaining is left. An installment larger than the payment cannot be recorded as paid.',

        'percent_required' => 'Every installment must state its percentage.',
        'percent_range' => 'Every installment percentage must be more than 0 and at most 100.',
    ],

    /*
     * The fee vocabulary, shared on purpose.
     *
     * The customer's acceptance page (resources/views/suchak/agreements/
     * public.blade.php) and the API's "you already accepted these terms"
     * refusal have to name a fee with THE SAME WORDS, or a Suchak reads back a
     * row the family cannot find on their own screen. They used to be two sets
     * of literals in two files, kept identical by hand.
     *
     * Keys are the agreement/package COLUMN names, so the refusal builder can
     * look a label up by the column that drifted.
     */
    'fees' => [
        'price_amount' => 'Registration fee',
        'per_meeting_fee_amount' => 'In-person meeting fee',
        'per_meeting_online_fee_amount' => 'Online meeting fee',
        'post_marriage_fee_amount' => 'Fee after the match is settled',

        // T2 makes the last installment a REMAINDER, not a percentage.
        'final_tranche_remainder' => 'Remaining amount',
        'not_quoted' => 'not set',
    ],

    /*
     * The CUSTOMER-facing portal — the only pages the FAMILY is ever sent.
     *
     * These were written straight into the blades in Marathi (stages) and in
     * English (show), so the en/mr switcher on the layout changed nothing on
     * either page. Both now read through __(), which means the family gets the
     * language they asked for and an admin can correct the wording from the
     * translations table without a deploy.
     */
    'customer_portal' => [
        'stages' => [
            'title' => 'Profiles suggested for you',
            'intro' => 'Record what you did about each profile here. This record stays yours — your Suchak cannot make it on your behalf.',

            // The link text is a phrase inside the sentence, so the sentence
            // takes it as a placeholder: word order around it is not the same
            // in both languages, and three glued fragments would fix Marathi's
            // order onto English.
            'identify' => 'So it is clear who made the record, first record :link.',
            'identify_link' => 'your name and relationship',
            'link_user' => 'Using this link:',

            'unnamed_profile' => 'Suggested profile',

            // D11 / D21 in the family's own words. Latin digits, no rupee
            // figure — the amount lives on their payments screen (D17).
            'clause_binds' => 'You have viewed this profile. If a marriage with this profile takes place on or before :date, your Suchak\'s agreed marriage fee still applies — even if the service is stopped in between.',
            'clause_released_prior' => 'You have recorded that you already knew this family, so no marriage fee applies to this profile.',

            // SuchakCollaborationStageEvent stage keys — what the family is
            // actually being asked to confirm on each button.
            'help' => [
                'viewed' => 'You have seen this profile.',
                'interested' => 'You like this profile.',
                'meeting_confirmed' => 'The arranged meeting actually took place.',
            ],

            // 9a A6 — one tap, at view time, on the rung the clause binds at.
            'prior_acquaintance_label' => 'We already knew this family.',
            'prior_acquaintance_help' => 'If so, the :months-month marriage-fee condition will not apply to this profile.',

            // The stage name arrives already translated, so the sentence
            // around it takes it as a placeholder rather than being glued on.
            'recorded' => 'Recorded: :stage',

            // D26 — the last three rungs are claimed by a Suchak and confirmed by the family. The
            // stage name and the date arrive already formatted, so both sentences take them as
            // placeholders rather than being glued together from fragments.
            'confirm_title' => 'Recorded by your Suchak — your confirmation is needed',
            'confirm_claim' => 'Your Suchak says: :stage — :date.',
            'confirm_submit' => 'This is correct, I confirm',
            'confirm_done' => 'You confirmed: :stage — :date.',
            // D27 — a sentence the reader acts on. No rupee figure: the amount lives on their
            // payments screen (D17), which is where they approve it.
            'confirm_consequence' => 'Once you confirm, the agreed marriage fee for this stage becomes due.',
            'confirm_disagree' => 'If this is not correct, do not confirm — speak to your Suchak first.',
            'confirm_refused_suchak' => 'Only the family can give this confirmation. A Suchak cannot confirm his own claim.',
            'confirmed' => 'Your confirmation is recorded: :stage',

            'empty' => 'There is nothing for you to record right now.',

            // D23 / section 8 — said to the family, not only in the code.
            'link_proof_note' => 'A record made through this link says only that "the person holding this link made the record". Do not give this link to anyone else.',
        ],

        'show' => [
            'eyebrow' => 'Suchak Customer Portal',
            'fallback_title' => 'Customer service context',
            'intro' => 'Verify package, terms, payment, invoice, and receipt status for this Suchak customer context.',

            'portal_status' => 'Portal status',
            'terms' => 'Terms',
            'payment_request' => 'Payment request',
            'expires' => 'Expires',
            // `not_available` and `to_be_confirmed` used to live here. They now
            // live in `suchak.labels.common.*` because the public consent and
            // payment pages say the same thing — see the note there.
            'no_expiry' => 'No expiry set',

            'stages_link' => 'Profiles suggested for you, and your record',

            'package_terms_title' => 'Package and terms',
            'agreement_unavailable' => 'Agreement not available',
            'revision' => 'Revision :number',
            'amount_due' => 'Amount due',
            'collector' => 'Collector',

            'payments_title' => 'Payments and documents',
            'direct_payment_warning' => 'Platform-collected customers should not make direct Suchak payments. If any Suchak asks for payment outside this verified platform context, use your logged-in account to report it with evidence.',
            'status' => 'Status',
            'received' => 'Received',
            'balance' => 'Balance',
            'mode' => 'Mode',
            'documents' => 'Documents',
            'issued_on' => 'issued :date',
            'verify_receipt' => 'Verify receipt',
            'no_payments' => 'No payment record has been posted for this request yet.',

            'corrections_title' => 'Corrections and service actions',
            'financial_corrections' => 'Financial corrections',
            'no_corrections' => 'No correction posted.',
            'overdue_actions' => 'Overdue service actions',
            'no_overdue_actions' => 'No overdue service action.',

            'family_title' => 'Family and payer context',
            'relationship' => 'Relationship: :value',
            'not_specified' => 'Not specified',
            'payer_role' => 'Payer role: :value',
            'member_status' => 'Status: :value',
            'no_family' => 'No shared family member context has been linked yet.',

            'claim_title' => 'Claim portal link',
            'claim_name' => 'Name',
            'claim_relationship' => 'Relationship',
            'claim_submit' => 'Claim',

            'revoke_title' => 'Revoke portal link',
            'revoke_reason' => 'Reason',
            'revoke_submit' => 'Revoke access',
        ],
    ],

    /*
     * The tokenised pages a family opens from a link, with no login.
     *
     * All three were written as Devanagari string literals with no __() at all
     * — the agreement page even declared `<html lang="mr">` — so the layout's
     * en/mr switcher changed nothing on the pages where a family gives consent
     * and agrees a price. The payment page was worse than untranslated: every
     * line carried BOTH languages glued together with a `·`, so neither reader
     * got a clean page.
     *
     * The damage that forced this: `stage_label` became locale-aware first, so
     * the agreement page's installment rows started printing "Once the match is
     * settled" inside Marathi prose. Half a translation is worse than none.
     *
     * Fee NAMES are not here. They belong to `suchak.fees.*`, which the API's
     * "you already accepted these terms" refusal also reads — a family must be
     * able to find on their own screen the row a Suchak reads back to them.
     * Stage names are not here either: they come from
     * SuchakCollaborationStageEvent::stageLabel() already translated.
     */
    'public_pages' => [
        // Said by both the consent page and the agreement page, so said once.
        'link_invalid' => 'This link is invalid.',
        'link_expired' => 'This link has expired. Ask the Suchak for a new link.',

        'agreement' => [
            'title' => 'Fee agreement',
            'og_description' => 'Review the fees and accept them.',
            'intro' => 'Please review the fees below and accept them.',

            'fees_heading' => 'Fees',
            // The fee NAME comes from suchak.fees.*; this only adds the fact
            // that the fee recurs, which a family reading a price agreement
            // must not have to infer.
            'fee_per_meeting' => ':fee (per meeting)',
            'tranche_heading' => ':fee — when and how much',

            // A success fee is a mode first and an amount second: quoting a
            // rupee figure for either of these would invent a price.
            'success_fee_as_wished' => 'As you wish',
            'success_fee_none' => 'None',

            'name_required' => 'Please enter the name of the person accepting.',
            'accepted' => 'Your acceptance has been recorded. The amounts above are now fixed.',
            'inactive' => 'This agreement is no longer active.',
            'acceptance_failed' => 'This acceptance could not be recorded.',

            'freeze_note' => 'Once you accept, the amounts above are fixed. The Suchak cannot change them afterwards.',
            'evidence_note' => 'Your name, the time of acceptance, your IP address and basic technical details of your device will be stored as evidence. This page does not verify you by OTP.',

            'accepted_by_name' => 'Name of the person accepting',
            'accept_button' => 'Yes, I accept these fees',
        ],

        'consent' => [
            'title' => 'Consent letter',
            'og_description' => 'Review the profile summary and choose Yes or No.',
            'intro' => 'Please review the details below and choose your response.',

            'mobile' => 'Mobile',
            'profile_card' => 'Profile summary',
            'age' => 'Age',

            // Whose name the summary is showing. The gendered wording is the
            // point — a Marathi family reads "वधूचे नाव", not a neutral label.
            'name_label' => [
                'bride' => 'Bride\'s name',
                'groom' => 'Groom\'s name',
                'candidate' => 'Candidate name',
            ],

            'consent_text' => 'Your consent',
            'consent_intro' => 'Mr./Ms. :suchak_name needs your consent to take this marriage profile to suitable families.',
            'if_yes' => 'If you choose Yes:',
            'point_biodata' => ':suchak_name can safely show your biodata to parents of suitable profiles.',
            'point_summary' => 'They can use the short profile summary while discussing suitable matches.',
            'point_contact' => 'They can contact you or your parents for further conversation and introductions.',
            'privacy' => 'Your private information will not be shared with anyone without your permission.',
            'evidence' => 'Your decision, time, and required technical record will be stored as secure evidence.',

            'yes' => 'Yes, I give consent',
            'no' => 'No, I do not give consent',

            // Also the controller's post-decision message: one sentence, said
            // once, whether it arrives as a banner or as a state.
            'accepted' => 'Consent accepted.',
            'rejected' => 'Consent rejected.',
            'inactive' => 'This request is no longer active.',
        ],

        'payment_request' => [
            'og_title' => 'Payment request',
            'og_title_for_candidate' => 'Payment request for :name',
            'og_description' => 'Pay by UPI',

            'requested_by' => 'Requested by',
            'secure_payment' => 'Secure payment · authorised Suchak',

            'candidate' => 'Candidate',
            'plan' => 'Plan',
            'plan_fallback' => 'Service plan',
            'amount_to_pay' => 'Amount to pay',

            'what_you_get' => 'What you get',
            'services_confirmed_by_suchak' => 'The services in this plan will be confirmed directly by the Suchak.',

            'how_to_pay' => 'How to pay',
            'how_to_pay_note' => 'Directly to this Suchak',
            'scan_qr' => 'Scan the QR code',
            'qr_alt' => 'Suchak payment QR',
            'or_use_upi' => 'Or use the UPI ID',
            'copy' => 'Copy',
            'copied' => 'Copied ✓',
            'any_upi_app' => 'You can pay using the QR or UPI ID above from any UPI app on your phone (PhonePe, Google Pay, Paytm).',
            'no_upi_published' => 'This Suchak has not published a UPI ID or payment QR yet. Contact them using this verified request, or wait for an updated request.',

            // Paying IS the acceptance here — there is no checkbox — so the
            // sentence that says so has to be as plain as the amount above it.
            'paying_accepts_terms' => 'Paying after reviewing the plan and services means accepting these service terms.',

            'billed_by_platform' => 'This customer is billed by the platform, so direct Suchak UPI/QR is not shown here.',
            'suchak_collection_only' => 'The UPI / QR above are for this Suchak\'s customer collection only, not platform subscription billing.',
            'report_outside_payment' => 'If any Suchak asks for payment outside this verified page, report it with evidence from your account.',
        ],
    ],

    'dashboard' => [
        // Text the Suchak composes on his own screen and a family then reads in WhatsApp. It
        // follows the SENDER's language — the Suchak's — so it is translated like everything else.
        // `:number` is substituted, never formatted: digits stay Latin 0-9 in both languages.
        'share_card_whatsapp_line' => 'WhatsApp: :number',
        'share_card_tagline' => 'Trusted matchmaking service for arranging marriages. Get in touch for more details.',
        'profile_request_reply_shown_to_family' => 'I will show this profile to the family. I will let you know as soon as they answer.',
        'profile_request_reply_ask_contact' => 'Please send your contact number and a convenient time so I can share more about this profile.',
        'profile_request_reply_under_discussion' => 'This profile is already under discussion. I will update you as soon as I know more.',

        'customer_list_title' => 'Customer list',
        'customer_list_intro' => 'All your customers in one place — photo, profile ID, basic details, and quick actions.',
        'customer_list_empty' => 'No customers yet. Add biodata or create a profile manually to get started.',
        'add_biodata' => 'Add biodata',
        'add_manual_profile' => 'Manual profile form',
        'customer_col_photo' => 'Photo',
        'customer_col_id' => 'Matrimony ID',
        'customer_col_name' => 'Name',
        'customer_col_age' => 'Age',
        'customer_col_gender' => 'Gender',
        'customer_col_address' => 'Address',
        'customer_col_status' => 'Status',
        'customer_col_profile_status' => 'Profile status',
        'customer_col_consent_status' => 'Consent status',
        'customer_col_actions' => 'Actions',
        'customer_intake_label' => 'Intake #:id',
        'customer_consent' => 'Consent',
        'customer_view' => 'View',
        'customer_edit_profile' => 'Edit profile',
        'customer_manage' => 'Manage',
        'customer_review' => 'Review',
    ],
];
