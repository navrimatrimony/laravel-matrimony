<?php

namespace App\Http\Controllers\Suchak;

use App\Http\Controllers\Controller;
use App\Models\SuchakLedgerEntry;
use App\Models\SuchakProfileNote;
use App\Models\SuchakProfileRepresentation;
use App\Modules\Suchak\Services\SuchakCrmLedgerService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use InvalidArgumentException;

class CrmLedgerController extends Controller
{
    public function storeNote(
        Request $request,
        SuchakProfileRepresentation $representation,
        SuchakCrmLedgerService $crmLedgerService,
    ): RedirectResponse {
        $account = $request->user()?->suchakAccount;
        if (! $account) {
            abort(403, 'Suchak account is required.');
        }

        $this->assertOwnedRepresentation($representation, (int) $account->id);

        $validated = $request->validate([
            'note_type' => ['required', 'string', Rule::in(SuchakProfileNote::TYPES)],
            'note_text' => ['required', 'string', 'max:4000'],
            'follow_up_at' => ['nullable', 'date'],
        ]);

        try {
            $crmLedgerService->createProfileNote(
                $account,
                $request->user(),
                $representation->matrimonyProfile()->firstOrFail(),
                $validated,
                $request->ip(),
                $request->userAgent(),
            );
        } catch (InvalidArgumentException $exception) {
            return back()
                ->withInput()
                ->with('error', $exception->getMessage());
        }

        return back()->with('success', 'Suchak CRM note added.');
    }

    public function storeLedgerEntry(
        Request $request,
        SuchakProfileRepresentation $representation,
        SuchakCrmLedgerService $crmLedgerService,
    ): RedirectResponse {
        $account = $request->user()?->suchakAccount;
        if (! $account) {
            abort(403, 'Suchak account is required.');
        }

        $this->assertOwnedRepresentation($representation, (int) $account->id);

        $validated = $request->validate([
            'entry_type' => ['required', 'string', Rule::in(SuchakLedgerEntry::TYPES)],
            'amount' => ['nullable', 'numeric', 'min:0', 'max:999999999.99'],
            'currency' => ['required', 'string', 'size:3'],
            'status' => ['required', 'string', Rule::in(SuchakLedgerEntry::STATUSES)],
            'due_date' => ['nullable', 'date'],
            'paid_at' => ['nullable', 'date'],
            'note' => ['nullable', 'string', 'max:2000'],
        ]);

        try {
            $crmLedgerService->createLedgerEntry(
                $account,
                $request->user(),
                $representation->matrimonyProfile()->firstOrFail(),
                $validated,
                $request->ip(),
                $request->userAgent(),
            );
        } catch (InvalidArgumentException $exception) {
            return back()
                ->withInput()
                ->with('error', $exception->getMessage());
        }

        return back()->with('success', 'Suchak ledger entry added.');
    }

    private function assertOwnedRepresentation(SuchakProfileRepresentation $representation, int $accountId): void
    {
        if ((int) $representation->suchak_account_id !== $accountId) {
            abort(403, 'Only the owning Suchak account can use this representation.');
        }
    }
}
