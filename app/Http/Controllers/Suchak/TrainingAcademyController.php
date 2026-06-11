<?php

namespace App\Http\Controllers\Suchak;

use App\Http\Controllers\Controller;
use App\Models\SuchakMessageTemplate;
use App\Models\SuchakMessageTemplateUsage;
use App\Modules\Suchak\Services\SuchakTrainingAcademyService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use InvalidArgumentException;

class TrainingAcademyController extends Controller
{
    public function index(Request $request, SuchakTrainingAcademyService $academyService): View
    {
        $account = $request->user()?->suchakAccount;
        if (! $account) {
            abort(403, 'Suchak account is required.');
        }

        return view('suchak.training-academy.index', [
            'suchakAccount' => $account,
            'academy' => $academyService->academyFor($account),
            'usageContexts' => SuchakMessageTemplateUsage::CONTEXTS,
        ]);
    }

    public function useTemplate(
        Request $request,
        SuchakMessageTemplate $messageTemplate,
        SuchakTrainingAcademyService $academyService,
    ): RedirectResponse {
        $account = $request->user()?->suchakAccount;
        if (! $account) {
            abort(403, 'Suchak account is required.');
        }

        $validated = $request->validate([
            'usage_context' => ['required', 'string', Rule::in(SuchakMessageTemplateUsage::CONTEXTS)],
            'rendered_body' => ['nullable', 'string', 'max:4000'],
        ]);

        try {
            $academyService->useTemplate($account, $messageTemplate, $request->user(), $validated);
        } catch (InvalidArgumentException $exception) {
            return back()->with('error', $exception->getMessage())->withInput();
        }

        return redirect()
            ->route('suchak.training-academy.index')
            ->with('success', 'Suchak message template usage recorded.');
    }
}
