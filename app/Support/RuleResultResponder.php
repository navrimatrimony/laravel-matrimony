<?php

namespace App\Support;

use App\DTO\RuleResult;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;

final class RuleResultResponder
{
    /**
     * JSON: merges {@see RuleResult::toArray()} with success=false. Web: flash error + rule_action.
     */
    public static function toResponse(RuleResult $result, int $jsonStatus = 422): JsonResponse|RedirectResponse
    {
        if (request()->expectsJson()) {
            return response()->json(array_merge(['success' => false], $result->toArray()), $jsonStatus);
        }

        return back()->with('error', $result->message)->with('rule_action', $result->action ?? []);
    }

    public static function redirectBack(RuleResult $result): RedirectResponse
    {
        return redirect()->back()->with('error', $result->message)->with('rule_action', $result->action ?? []);
    }
}
