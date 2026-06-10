<?php

namespace App\Http\Controllers\Suchak;

use App\Http\Controllers\Controller;
use App\Modules\Suchak\Services\SuchakCustomerPortalService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;
use InvalidArgumentException;

class CustomerPortalController extends Controller
{
    public function show(
        Request $request,
        string $token,
        SuchakCustomerPortalService $portalService,
    ): View {
        try {
            $portalLink = $portalService->openPortalLink(
                $token,
                $request->ip(),
                $request->userAgent(),
            );
        } catch (InvalidArgumentException $exception) {
            abort(410, $exception->getMessage());
        }

        return view('suchak.customer-portal.show', [
            'token' => $token,
            'portalLink' => $portalLink,
            'customerContext' => $portalLink->customerContext,
            'paymentRequest' => $portalLink->paymentRequest,
        ]);
    }

    public function claim(
        Request $request,
        string $token,
        SuchakCustomerPortalService $portalService,
    ): RedirectResponse {
        try {
            $portalLink = $portalService->openPortalLink(
                $token,
                $request->ip(),
                $request->userAgent(),
            );
            $portalService->claimPortalLink(
                $portalLink,
                $request->only(['claimed_name', 'claimed_relationship_to_candidate']),
                $request->ip(),
                $request->userAgent(),
            );
        } catch (InvalidArgumentException $exception) {
            return back()->withErrors(['customer_portal' => $exception->getMessage()]);
        }

        return redirect()
            ->route('suchak.customer-portal.show', ['token' => $token])
            ->with('success', 'Customer portal link claimed.');
    }

    public function revoke(
        Request $request,
        string $token,
        SuchakCustomerPortalService $portalService,
    ): RedirectResponse {
        try {
            $portalLink = $portalService->openPortalLink(
                $token,
                $request->ip(),
                $request->userAgent(),
            );
            $portalService->revokePortalLink(
                $portalLink,
                null,
                (string) $request->input('revoke_reason', ''),
                $request->ip(),
                $request->userAgent(),
            );
        } catch (InvalidArgumentException $exception) {
            return back()->withErrors(['customer_portal' => $exception->getMessage()]);
        }

        return redirect()
            ->route('suchak.home')
            ->with('success', 'Customer portal link revoked.');
    }
}
