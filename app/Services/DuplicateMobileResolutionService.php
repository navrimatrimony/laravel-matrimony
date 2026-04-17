<?php

namespace App\Services;

use App\Models\User;
use App\Support\MobileNumber;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Resolves duplicate {@code users.mobile} values without deleting users (suffix strategy).
 */
final class DuplicateMobileResolutionService
{
    /**
     * Group key: canonical 10-digit mobile for rows that look like primary numbers.
     * Rows already suffixed with {@code _dup_} are grouped by extracting the leading digits before {@code _dup_}.
     *
     * @return int Number of user rows updated
     */
    public function dedupeAll(): int
    {
        return DB::transaction(function (): int {
            return $this->dedupeAllWithinTransaction();
        });
    }

    private function dedupeAllWithinTransaction(): int
    {
        $users = User::query()
            ->whereNotNull('mobile')
            ->where('mobile', '!=', '')
            ->orderBy('id')
            ->get(['id', 'mobile']);

        /** @var Collection<string, Collection<int, User>> $groups */
        $groups = collect();

        foreach ($users as $user) {
            $key = $this->canonicalGroupKey((string) $user->mobile);
            if ($key === null) {
                continue;
            }
            if (! $groups->has($key)) {
                $groups[$key] = collect();
            }
            $groups[$key]->push($user);
        }

        $updated = 0;

        foreach ($groups as $canonical => $group) {
            if ($group->count() <= 1) {
                continue;
            }

            $winner = $this->pickWinner($group);
            foreach ($group as $row) {
                if ((int) $row->id === (int) $winner->id) {
                    continue;
                }

                $fresh = User::query()->find($row->id);
                if (! $fresh) {
                    continue;
                }

                $originalMobile = (string) $fresh->mobile;
                $suffix = $canonical.'_dup_'.$fresh->id;

                DB::table('users')->where('id', $fresh->id)->update([
                    'mobile_backup' => strlen($originalMobile) <= 32 ? $originalMobile : substr($originalMobile, 0, 32),
                    'mobile_duplicate_of_user_id' => $winner->id,
                    'mobile' => strlen($suffix) <= 64 ? $suffix : substr($suffix, 0, 64),
                    'updated_at' => now(),
                ]);

                $updated++;
            }
        }

        return $updated;
    }

    /**
     * @param  Collection<int, User>  $group
     */
    public function pickWinner(Collection $group): User
    {
        $ids = $group->pluck('id')->map(fn ($id) => (int) $id)->all();

        $withActiveSub = User::query()
            ->whereIn('id', $ids)
            ->whereHas('subscriptions', function ($q): void {
                $q->effectivelyActiveForAccess();
            })
            ->orderBy('id')
            ->first();

        if ($withActiveSub !== null) {
            return $withActiveSub;
        }

        return $group->sortBy('id')->first();
    }

    private function canonicalGroupKey(string $mobile): ?string
    {
        if (str_contains($mobile, '_dup_')) {
            $before = explode('_dup_', $mobile, 2)[0];
            $norm = MobileNumber::normalize($before);

            return $norm ?? preg_replace('/\D/', '', $before) ?: null;
        }

        return MobileNumber::normalize($mobile);
    }
}
