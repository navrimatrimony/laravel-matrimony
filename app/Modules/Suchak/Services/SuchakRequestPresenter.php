<?php

namespace App\Modules\Suchak\Services;

use App\Models\MatrimonyProfile;
use App\Models\SuchakAccount;
use App\Models\SuchakProfileRepresentation;
use App\Models\SuchakProfileRequest;
use App\Support\Suchak\SuchakContactRouting;
use Carbon\Carbon;
use Illuminate\Support\Facades\Storage;

/**
 * ONE payload shape for the Suchak request pipeline, shared by:
 *   - the member profile-detail contact block (MobileProfileDisplayPresenter)
 *   - the member request API
 *   - the Suchak request API
 *
 * Written once so a status label, a Suchak identity block or an "answered by"
 * attribution can never mean two different things on two surfaces.
 *
 * PRIVACY RULE, enforced here and nowhere else: the candidate's contact number
 * never appears in any payload this class builds. The only phone value it can
 * emit is the SUCHAK's own business number, and only masked.
 */
class SuchakRequestPresenter
{
    public const CONTACT_STATE_AVAILABLE = 'suchak_request_available';
    public const CONTACT_STATE_PENDING = 'suchak_request_pending';
    public const CONTACT_STATE_ANSWERED = 'suchak_request_answered';
    public const CONTACT_STATE_CLOSED = 'suchak_request_closed';

    public function __construct(
        private readonly SuchakRequestPipelineService $pipelineService,
    ) {
    }

    /**
     * The member reads this on a card that stays on screen, so it must state a
     * DURABLE fact, not an event. "सूचकांकडून उत्तर आले" is true only for the
     * first moment — after the member has replied in the chat themselves it
     * still announced the Suchak's reply as fresh news. Only that one state
     * differs; every other status reuses the shared vocabulary, and the
     * Suchak's own list keeps the original action wording.
     */
    public function memberStatusLabel(?string $status): ?string
    {
        if ((string) $status === SuchakProfileRequest::STATUS_ACCEPTED_BY_SUCHAK) {
            return (string) __('profile.suchak_request_status_member_conversation_open');
        }

        return $this->statusLabel($status);
    }

    public function statusLabel(?string $status): ?string
    {
        if ($status === null || $status === '') {
            return null;
        }

        $key = 'profile.suchak_request_status_'.$status;
        $label = __($key);

        return is_string($label) && $label !== $key ? $label : $status;
    }

    /**
     * Identity of the Suchak handling a profile. Safe for a member to see.
     *
     * @return array<string, mixed>
     */
    public function suchakBlock(SuchakProfileRepresentation $representation): array
    {
        $representation->loadMissing('suchakAccount.contactNumbers');
        /** @var SuchakAccount|null $account */
        $account = $representation->suchakAccount;
        $name = SuchakContactRouting::displayName($account);

        return [
            'representation_id' => (int) $representation->id,
            'suchak_account_id' => (int) $representation->suchak_account_id,
            'name' => $name,
            'subtitle' => __('profile.suchak_contact_subtitle'),
            'initial' => $this->initialOf($name),
            'photo_url' => $this->photoUrl($account),
            // The Suchak's OWN number, masked. Never the candidate's.
            'masked_phone' => SuchakContactRouting::maskedAccountPhone($account),
        ];
    }

    /**
     * Member-facing view of one request (the member who asked, or the candidate
     * being asked about — both get the same shape).
     *
     * @return array<string, mixed>
     */
    public function memberRequestPayload(SuchakProfileRequest $request): array
    {
        $request->loadMissing(['pipeline', 'representation.suchakAccount.contactNumbers', 'targetMatrimonyProfile']);
        $decidedBy = $this->pipelineService->decisionActorFor($request);

        return [
            'id' => (int) $request->id,
            'status' => $request->request_status,
            'status_label' => $this->memberStatusLabel($request->request_status),
            'is_open' => $request->isOpen(),
            'request_reason' => $request->request_reason,
            'message' => $request->message,
            'created_at' => $this->dateString($request->created_at),
            'replied_at' => $this->dateString($request->replied_at),
            'sla_expires_at' => $this->dateString($request->pipeline?->lock_expires_at),
            'chat_conversation_id' => $request->chat_conversation_id !== null
                ? (int) $request->chat_conversation_id
                : null,
            'answered_by' => $decidedBy['role'] ?? null,
            'answered_by_label' => $this->answeredByLabel($decidedBy['role'] ?? null),
            'answered_at' => $this->dateString($decidedBy['at'] ?? null),
            // The SLA close is what re-opens the door: a closed/expired request
            // frees the member to send a fresh one to the same profile.
            'can_resend' => $this->canResend($request),
            'candidate_can_answer' => $this->pipelineService->candidateCanAnswer($request),
            'target_profile_id' => (int) $request->target_matrimony_profile_id,
            'suchak' => $request->representation !== null
                ? $this->suchakBlock($request->representation)
                : null,
        ];
    }

    /**
     * Suchak-facing view of one incoming request. Carries a masked summary of
     * the member who asked — name, age, community, location — and never their
     * contact number (the Suchak reaches them through the chat reply).
     *
     * @return array<string, mixed>
     */
    public function suchakRequestPayload(SuchakProfileRequest $request): array
    {
        $request->loadMissing([
            'pipeline',
            'requestingMatrimonyProfile.gender',
            'requestingMatrimonyProfile.religion',
            'requestingMatrimonyProfile.caste',
            'requestingMatrimonyProfile.location',
            'targetMatrimonyProfile',
            'representation',
        ]);

        $decidedBy = $this->pipelineService->decisionActorFor($request);
        $representation = $request->representation;

        return [
            'id' => (int) $request->id,
            'status' => $request->request_status,
            'status_label' => $this->statusLabel($request->request_status),
            'is_open' => $request->isOpen(),
            'request_reason' => $request->request_reason,
            'message' => $request->message,
            'created_at' => $this->dateString($request->created_at),
            'replied_at' => $this->dateString($request->replied_at),
            'sla_expires_at' => $this->dateString($request->pipeline?->lock_expires_at),
            'sla_status' => $request->pipeline?->sla_status,
            'chat_conversation_id' => $request->chat_conversation_id !== null
                ? (int) $request->chat_conversation_id
                : null,
            'answered_by' => $decidedBy['role'] ?? null,
            'answered_by_label' => $this->answeredByLabel($decidedBy['role'] ?? null),
            'answered_at' => $this->dateString($decidedBy['at'] ?? null),
            'candidate_can_answer' => $this->pipelineService->candidateCanAnswer($request),
            'from_profile' => $this->profileSummary($request->requestingMatrimonyProfile),
            'customer' => [
                'representation_id' => $representation !== null ? (int) $representation->id : null,
                'matrimony_profile_id' => (int) $request->target_matrimony_profile_id,
                'name' => $this->cleanString($request->targetMatrimonyProfile?->full_name),
                'consent_valid' => $representation?->hasValidConsent() === true,
            ],
            'actions' => [
                'can_reply' => $this->suchakMayAct($request),
                'can_forward' => $this->suchakMayAct($request)
                    && ! $this->pipelineService->isAlreadyAnswered($request),
                'can_record_decision' => $this->suchakMayAct($request)
                    && ! $this->pipelineService->isAlreadyAnswered($request),
            ],
        ];
    }

    /**
     * The contact-card state + copy the member app renders for a Suchak-routed
     * profile. Returned as data so MobileProfileDisplayPresenter stays the only
     * place that knows the contact envelope shape.
     *
     * @return array{state: string, message: string, cta_label: string, cta_action: string, cta_enabled: bool}
     */
    public function contactStateFor(SuchakProfileRepresentation $representation, ?SuchakProfileRequest $request): array
    {
        $name = SuchakContactRouting::displayName($representation->suchakAccount);

        if ($request === null) {
            return [
                'state' => self::CONTACT_STATE_AVAILABLE,
                'message' => __('profile.suchak_request_available_message', ['name' => $name]),
                'cta_label' => __('profile.suchak_request_send_button'),
                'cta_action' => 'send_suchak_request',
                'cta_enabled' => true,
            ];
        }

        if ($this->pipelineService->isAlreadyAnswered($request)
            || $request->request_status === SuchakProfileRequest::STATUS_ACCEPTED_BY_SUCHAK) {
            return [
                'state' => self::CONTACT_STATE_ANSWERED,
                'message' => __('profile.suchak_request_answered_message', ['name' => $name]),
                'cta_label' => __('profile.suchak_request_open_chat_button'),
                'cta_action' => 'open_suchak_chat',
                'cta_enabled' => $request->chat_conversation_id !== null,
            ];
        }

        if ($request->isOpen()) {
            return [
                'state' => self::CONTACT_STATE_PENDING,
                'message' => __('profile.suchak_request_pending_message', ['name' => $name]),
                'cta_label' => __('profile.suchak_contact_pending_badge'),
                'cta_action' => 'none',
                'cta_enabled' => false,
            ];
        }

        return [
            'state' => self::CONTACT_STATE_CLOSED,
            'message' => __('profile.suchak_request_closed_message', ['name' => $name]),
            'cta_label' => __('profile.suchak_request_resend_button'),
            'cta_action' => 'send_suchak_request',
            'cta_enabled' => true,
        ];
    }

    public function canResend(SuchakProfileRequest $request): bool
    {
        return ! $request->isOpen();
    }

    /**
     * The one response body for a candidate decision, used by BOTH the member
     * app (candidate answering for themselves) and the Suchak app (Suchak
     * relaying the family's answer). Both can lose the race, so both need the
     * identical, localized "already answered by …" shape — writing it once is
     * what guarantees the two apps can read each other's outcome.
     *
     * @param  array{request: SuchakProfileRequest, already_answered: bool, answered_by: string|null, answered_at: mixed, message: mixed}  $result
     * @param  callable(SuchakProfileRequest): array<string, mixed>  $payloadBuilder
     * @return array<string, mixed>
     */
    public function decisionResponse(array $result, callable $payloadBuilder): array
    {
        $alreadyAnswered = (bool) $result['already_answered'];
        $answeredBy = $result['answered_by'] ?? null;
        $answeredByLabel = $this->answeredByLabel($answeredBy);

        return [
            'success' => true,
            'code' => $alreadyAnswered ? 'already_answered' : 'decision_recorded',
            'message' => $alreadyAnswered
                ? __('profile.suchak_request_already_answered', [
                    'by' => $answeredByLabel ?? __('profile.suchak_default_name'),
                ])
                : __('profile.suchak_request_decision_recorded'),
            'data' => [
                'already_answered' => $alreadyAnswered,
                'answered_by' => $answeredBy,
                'answered_by_label' => $answeredByLabel,
                'answered_at' => $this->dateString($result['answered_at'] ?? null),
                'suchak_request' => $payloadBuilder($result['request']),
            ],
        ];
    }

    private function suchakMayAct(SuchakProfileRequest $request): bool
    {
        $request->loadMissing('representation');

        return $request->isOpen()
            && $request->representation?->representation_status === SuchakProfileRepresentation::STATUS_ACTIVE
            && $request->representation?->hasValidConsent() === true;
    }

    private function answeredByLabel(?string $role): ?string
    {
        return match ($role) {
            'suchak' => __('profile.suchak_request_answered_by_suchak'),
            'candidate' => __('profile.suchak_request_answered_by_candidate'),
            default => null,
        };
    }

    /**
     * @return array<string, mixed>|null
     */
    private function profileSummary(?MatrimonyProfile $profile): ?array
    {
        if (! $profile instanceof MatrimonyProfile) {
            return null;
        }

        return [
            'id' => (int) $profile->id,
            'name' => $this->cleanString($profile->full_name) ?? __('profile.suchak_default_name'),
            'age' => $this->age($profile),
            'gender' => $this->cleanString($profile->gender?->name ?? $profile->gender?->label ?? null),
            'community' => $this->joinClean([
                $this->cleanString($profile->religion?->name ?? $profile->religion?->label ?? null),
                $this->cleanString($profile->caste?->name ?? $profile->caste?->label ?? null),
            ]),
            'location' => $this->cleanString(
                method_exists($profile, 'residenceLocationDisplayLine')
                    ? $profile->residenceLocationDisplayLine()
                    : ($profile->location?->name ?? null),
            ),
            'profile_photo_url' => $profile->photo_approved !== false ? $profile->profile_photo_url : null,
        ];
    }

    private function photoUrl(?SuchakAccount $account): ?string
    {
        $path = trim((string) ($account?->profile_photo_path ?? ''));

        return $path !== '' ? Storage::disk('public')->url($path) : null;
    }

    private function initialOf(string $name): string
    {
        $normalized = preg_replace('/\s+/', ' ', $name) ?: $name;
        $initial = mb_substr($normalized, 0, 1, 'UTF-8');

        return $initial !== '' ? $initial : 'S';
    }

    private function age(MatrimonyProfile $profile): ?int
    {
        $date = $this->cleanString($profile->date_of_birth);
        if ($date === null) {
            return null;
        }

        try {
            return Carbon::parse($date)->age;
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * @param  array<int, string|null>  $parts
     */
    private function joinClean(array $parts): ?string
    {
        $parts = array_values(array_filter($parts, fn ($value): bool => $this->cleanString($value) !== null));

        // Drop repeats, case- and space-insensitively. Religion and caste are
        // genuinely the same word for several communities (Buddhist, Jain,
        // Sikh...), and the Suchak was reading "Buddhist • Buddhist" on the
        // request card — which looks like a bug in the data rather than a fact
        // about the person.
        $seen = [];
        $unique = [];
        foreach ($parts as $part) {
            $key = mb_strtolower(trim((string) $part));
            if ($key === '' || isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;
            $unique[] = $part;
        }

        return $unique === [] ? null : implode(' • ', $unique);
    }

    private function dateString(mixed $value): ?string
    {
        if ($value instanceof \DateTimeInterface) {
            return $value->format(DATE_ATOM);
        }

        return $this->cleanString($value);
    }

    private function cleanString(mixed $value): ?string
    {
        $text = trim((string) $value);

        return $text === '' ? null : $text;
    }
}
