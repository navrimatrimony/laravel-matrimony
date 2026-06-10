<?php

namespace App\Modules\Suchak\Services;

use App\Models\SuchakAccount;
use App\Models\User;
use InvalidArgumentException;

class SuchakAccessService
{
    public function canOperate(?SuchakAccount $account): bool
    {
        return $account !== null
            && $account->verification_status === SuchakAccount::VERIFICATION_VERIFIED;
    }

    public function canPubliclyRoute(?SuchakAccount $account): bool
    {
        return $this->canOperate($account)
            && $account?->public_status === SuchakAccount::PUBLIC_ACTIVE;
    }

    public function owns(SuchakAccount $account, User $actor): bool
    {
        return (int) $account->user_id === (int) $actor->id;
    }

    public function canOwnerOperate(SuchakAccount $account, User $actor): bool
    {
        return $this->owns($account, $actor) && $this->canOperate($account);
    }

    public function isAdmin(User $actor): bool
    {
        return (bool) $actor->is_admin;
    }

    public function assertCanOperate(SuchakAccount $account, string $message): void
    {
        if (! $this->canOperate($account)) {
            throw new InvalidArgumentException($message);
        }
    }

    public function assertOwner(SuchakAccount $account, User $actor, string $message): void
    {
        if (! $this->owns($account, $actor)) {
            throw new InvalidArgumentException($message);
        }
    }

    public function assertOwnerCanOperate(
        SuchakAccount $account,
        User $actor,
        string $ownerMessage,
        string $statusMessage,
    ): void {
        $this->assertOwner($account, $actor, $ownerMessage);
        $this->assertCanOperate($account, $statusMessage);
    }

    public function assertPubliclyRoutable(SuchakAccount $account, string $message): void
    {
        if (! $this->canPubliclyRoute($account)) {
            throw new InvalidArgumentException($message);
        }
    }

    public function assertAdmin(User $actor, string $message): void
    {
        if (! $this->isAdmin($actor)) {
            throw new InvalidArgumentException($message);
        }
    }
}
