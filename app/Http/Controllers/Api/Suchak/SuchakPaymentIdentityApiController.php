<?php

namespace App\Http\Controllers\Api\Suchak;

use App\Http\Controllers\Controller;
use App\Models\SuchakAccount;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Track A payment identity (UPI VPA + QR) on existing suchak_accounts.
 * PO D1 approved. Does not touch Track B / PayU.
 */
class SuchakPaymentIdentityApiController extends Controller
{
    public function show(Request $request): JsonResponse
    {
        $account = $this->requireAccount($request);
        if ($account instanceof JsonResponse) {
            return $account;
        }

        return response()->json([
            'success' => true,
            'message' => 'Suchak Track A payment identity loaded.',
            'data' => [
                'account_id' => $account->id,
                'payment_identity' => $account->trackAPaymentIdentity(),
                'track' => 'A',
            ],
        ]);
    }

    public function update(Request $request): JsonResponse
    {
        $account = $this->requireAccount($request);
        if ($account instanceof JsonResponse) {
            return $account;
        }

        $validated = $request->validate([
            'upi_vpa' => ['nullable', 'string', 'max:191'],
            'clear_payment_qr' => ['nullable', 'boolean'],
            'payment_qr' => ['nullable', 'image', 'max:5120', 'mimes:jpg,jpeg,png,webp'],
        ]);

        $upiRaw = array_key_exists('upi_vpa', $validated)
            ? trim((string) ($validated['upi_vpa'] ?? ''))
            : null;

        if ($upiRaw !== null && $upiRaw !== '' && ! $this->isValidUpiVpa($upiRaw)) {
            throw ValidationException::withMessages([
                'upi_vpa' => 'Enter a valid UPI ID (example: name@bank).',
            ]);
        }

        $updates = [];
        if ($upiRaw !== null) {
            $updates['upi_vpa'] = $upiRaw !== '' ? $upiRaw : null;
        }

        $clearQr = $request->boolean('clear_payment_qr');
        $file = $request->file('payment_qr');

        if ($clearQr && $file !== null) {
            throw ValidationException::withMessages([
                'payment_qr' => 'Cannot upload and clear the QR image in the same request.',
            ]);
        }

        if ($clearQr) {
            $this->deleteStoredQr($account);
            $updates['payment_qr_path'] = null;
            $updates['payment_qr_updated_at'] = null;
        } elseif ($file !== null) {
            $extension = strtolower((string) $file->getClientOriginalExtension());
            if (! in_array($extension, ['jpg', 'jpeg', 'png', 'webp'], true)) {
                $extension = 'jpg';
            }

            $publicPath = $file->storeAs(
                'suchak/payment-qr/'.$account->id,
                Str::uuid()->toString().'.'.$extension,
                'public',
            );

            $this->deleteStoredQr($account);
            $updates['payment_qr_path'] = $publicPath;
            $updates['payment_qr_updated_at'] = now();
        }

        if ($updates !== []) {
            $account->forceFill($updates)->save();
        }

        $account->refresh();

        return response()->json([
            'success' => true,
            'message' => 'Suchak Track A payment identity updated.',
            'data' => [
                'account_id' => $account->id,
                'payment_identity' => $account->trackAPaymentIdentity(),
                'track' => 'A',
            ],
        ]);
    }

    private function requireAccount(Request $request): SuchakAccount|JsonResponse
    {
        $user = $request->user();
        if (! $user instanceof User) {
            return response()->json([
                'success' => false,
                'message' => 'Unauthenticated.',
            ], 401);
        }

        /** @var SuchakAccount|null $account */
        $account = $user->suchakAccount;
        if ($account === null) {
            return response()->json([
                'success' => false,
                'message' => 'Suchak account is required to access this section.',
            ], 403);
        }

        return $account;
    }

    private function isValidUpiVpa(string $vpa): bool
    {
        return (bool) preg_match('/^[a-zA-Z0-9.\-_]{2,256}@[a-zA-Z][a-zA-Z0-9.\-]{1,63}$/', $vpa);
    }

    private function deleteStoredQr(SuchakAccount $account): void
    {
        $oldPath = trim((string) ($account->payment_qr_path ?? ''));
        if ($oldPath === '') {
            return;
        }

        if (Storage::disk('public')->exists($oldPath)) {
            Storage::disk('public')->delete($oldPath);
        }
    }
}
