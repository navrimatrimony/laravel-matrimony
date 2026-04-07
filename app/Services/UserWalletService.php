<?php

namespace App\Services;

use App\Models\User;
use App\Models\UserWallet;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Stored balance in paise (integer). All debits/credits are explicit for user-visible accounting.
 */
class UserWalletService
{
    public function getOrCreateWallet(int $userId): UserWallet
    {
        if (! config('monetization.wallet.enabled', true)) {
            return new UserWallet(['user_id' => $userId, 'balance_paise' => 0]);
        }

        return UserWallet::query()->firstOrCreate(
            ['user_id' => $userId],
            ['balance_paise' => 0],
        );
    }

    public function balancePaise(int $userId): int
    {
        return (int) $this->getOrCreateWallet($userId)->balance_paise;
    }

    /**
     * Add funds (support / admin credit). Returns new balance in paise.
     */
    public function credit(int $userId, int $amountPaise, ?string $note = null): int
    {
        if ($amountPaise <= 0) {
            throw new InvalidArgumentException('Credit amount must be positive.');
        }

        if (! config('monetization.wallet.enabled', true)) {
            throw new InvalidArgumentException('Wallet is disabled.');
        }

        return (int) DB::transaction(function () use ($userId, $amountPaise) {
            $wallet = UserWallet::query()->where('user_id', $userId)->lockForUpdate()->first()
                ?? UserWallet::query()->create(['user_id' => $userId, 'balance_paise' => 0]);

            $wallet->increment('balance_paise', $amountPaise);

            return (int) $wallet->fresh()->balance_paise;
        });
    }

    /**
     * Deduct if sufficient balance. Returns false when disabled or insufficient funds.
     */
    public function debit(int $userId, int $amountPaise, ?string $note = null): bool
    {
        if ($amountPaise <= 0) {
            return false;
        }

        if (! config('monetization.wallet.enabled', true)) {
            return false;
        }

        return DB::transaction(function () use ($userId, $amountPaise) {
            $wallet = UserWallet::query()->where('user_id', $userId)->lockForUpdate()->first();
            if (! $wallet || (int) $wallet->balance_paise < $amountPaise) {
                return false;
            }

            $wallet->decrement('balance_paise', $amountPaise);

            return true;
        });
    }

    public function displayRupeesFromPaise(int $paise): string
    {
        return number_format($paise / 100, 2, '.', '');
    }

    public function walletSummary(User $user): array
    {
        $paise = $this->balancePaise((int) $user->id);

        return [
            'balance_paise' => $paise,
            'balance_rupees_display' => $this->displayRupeesFromPaise($paise),
            'wallet_enabled' => (bool) config('monetization.wallet.enabled', true),
        ];
    }
}
