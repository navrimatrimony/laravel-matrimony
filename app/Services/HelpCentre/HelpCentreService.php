<?php

namespace App\Services\HelpCentre;

use App\Models\HelpCentreTicket;
use App\Models\HelpCentreTicketWorkflow;
use App\Models\User;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class HelpCentreService
{
    public function __construct(
        protected HelpCentreAiService $ai,
    ) {}

    /**
     * @return array{reply:string,intent:string,escalated:bool,ticket_id:?string}
     */
    public function respond(User $user, string $message): array
    {
        $normalized = $this->normalize($message);
        $intent = 'escalation';
        $reply = '';
        $escalated = false;
        $ticketId = null;

        if ($this->isSensitiveQuery($normalized)) {
            $intent = 'policy_sensitive';
            $reply = __('help_centre.sensitive_refusal');
        } elseif ($this->matchesAny($normalized, ['payment', 'refund', 'transaction', 'failed', 'declined', 'पेमेंट', 'payment failed', 'plan not active', 'कट झाले'])) {
            $intent = 'payment_help';
            $reply = __('help_centre.intent_payment');
        } elseif ($this->matchesAny($normalized, ['contact reveal', 'unlock contact', 'number not visible', 'contact not visible', 'संपर्क दिसत नाही', 'unlock number', 'show contact'])) {
            $intent = 'contact_unlock';
            $reply = __('help_centre.intent_contact_unlock');
        } elseif ($this->matchesAny($normalized, ['mediation', 'assisted matchmaking', 'matchmaking request', 'mediator', 'मॅचमेकिंग', 'मध्यस्थी'])) {
            $intent = 'mediation_help';
            $reply = __('help_centre.intent_mediation');
        } elseif ($this->matchesAny($normalized, ['chat not sending', 'message not sending', 'chat issue', 'chat failed', 'चॅट', 'message failed'])) {
            $intent = 'chat_issue';
            $reply = __('help_centre.intent_chat_issue');
        } else {
            $aiReply = $this->ai->generateReply($message);
            if (is_string($aiReply) && $aiReply !== '' && ! $this->containsSensitiveData($aiReply)) {
                $intent = 'ai_fallback';
                $reply = $aiReply;
            } else {
                $intent = 'escalation';
                $escalated = true;
                $ticketId = $this->createTicketId();
                $reply = __('help_centre.intent_escalation', ['ticket' => $ticketId]);

                Log::info('help_centre_escalated_query', [
                    'user_id' => (int) $user->id,
                    'ticket_id' => $ticketId,
                    'query' => $message,
                ]);
            }
        }

        $ticket = HelpCentreTicket::query()->create([
            'user_id' => (int) $user->id,
            'ticket_code' => $ticketId,
            'query_text' => $message,
            'normalized_query' => $normalized,
            'intent' => $intent,
            'escalated' => $escalated,
            'status' => $escalated ? 'open' : 'auto_resolved',
            'bot_reply' => $reply,
            'meta' => [
                'source' => 'help_centre_widget_or_page',
            ],
        ]);

        if ($escalated) {
            HelpCentreTicketWorkflow::query()->updateOrCreate(
                ['help_centre_ticket_id' => (int) $ticket->id],
                [
                    'priority' => 'normal',
                    'first_response_due_at' => now()->addHours(
                        max(1, (int) config('help_centre.sla.first_response_hours', 12))
                    ),
                ]
            );
        }

        return [
            'reply' => $reply,
            'intent' => $intent,
            'escalated' => $escalated,
            'ticket_id' => $ticketId,
        ];
    }

    private function normalize(string $message): string
    {
        $message = trim(Str::lower($message));
        $message = preg_replace('/\s+/', ' ', $message) ?? $message;

        return $message;
    }

    private function isSensitiveQuery(string $message): bool
    {
        return $this->matchesAny($message, [
            'phone',
            'mobile',
            'number',
            'whatsapp number',
            'email',
            'address',
            'contact details',
            'फोन',
            'मोबाइल',
            'नंबर',
            'ईमेल',
            'पत्ता',
            'संपर्क',
        ]);
    }

    /**
     * @param  list<string>  $needles
     */
    private function matchesAny(string $haystack, array $needles): bool
    {
        foreach ($needles as $needle) {
            if ($needle !== '' && str_contains($haystack, Str::lower($needle))) {
                return true;
            }
        }

        return false;
    }

    private function createTicketId(): string
    {
        return 'HC-'.strtoupper(Str::random(6));
    }

    private function containsSensitiveData(string $text): bool
    {
        $text = Str::lower($text);

        if (preg_match('/\b\d{10}\b/', $text) === 1) {
            return true;
        }

        if (preg_match('/[A-Z0-9._%+\-]+@[A-Z0-9.\-]+\.[A-Z]{2,}/i', $text) === 1) {
            return true;
        }

        return $this->matchesAny($text, [
            'phone number is',
            'mobile number is',
            'email is',
            'address is',
        ]);
    }
}
