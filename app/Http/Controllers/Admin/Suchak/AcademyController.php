<?php

namespace App\Http\Controllers\Admin\Suchak;

use App\Http\Controllers\Controller;
use App\Models\SuchakAccount;
use App\Models\SuchakMessageTemplate;
use App\Models\SuchakTrainingModule;
use App\Modules\Suchak\Services\SuchakTrainingAcademyService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use InvalidArgumentException;

class AcademyController extends Controller
{
    public function index(SuchakTrainingAcademyService $academyService): View
    {
        return view('admin.suchak.academy.index', [
            'summary' => $academyService->adminSummary(),
            'moduleCategories' => SuchakTrainingModule::CATEGORIES,
            'templateCategories' => SuchakMessageTemplate::CATEGORIES,
            'templateChannels' => SuchakMessageTemplate::CHANNELS,
        ]);
    }

    public function storeModule(
        Request $request,
        SuchakTrainingAcademyService $academyService,
    ): RedirectResponse {
        $validated = $request->validate([
            'module_key' => ['required', 'string', 'max:96'],
            'module_title' => ['required', 'string', 'min:8', 'max:160'],
            'module_title_mr' => ['nullable', 'string', 'max:160'],
            'module_category' => ['required', 'string', Rule::in(SuchakTrainingModule::CATEGORIES)],
            'summary' => ['required', 'string', 'min:10', 'max:1000'],
            'summary_mr' => ['nullable', 'string', 'max:1000'],
            'content_outline' => ['required', 'string', 'min:10', 'max:4000'],
            'content_outline_mr' => ['nullable', 'string', 'max:4000'],
            'is_required_for_certificate' => ['nullable', 'boolean'],
            'sort_order' => ['nullable', 'integer', 'min:0', 'max:65535'],
        ]);

        return $this->runAcademyAction(
            fn () => $academyService->createTrainingModule($request->user(), $validated),
            'Suchak training module created.',
        );
    }

    public function completeModule(
        Request $request,
        SuchakTrainingModule $trainingModule,
        SuchakTrainingAcademyService $academyService,
    ): RedirectResponse {
        $validated = $request->validate([
            'suchak_account_id' => ['required', 'integer', 'exists:suchak_accounts,id'],
            'score_percent' => ['nullable', 'integer', 'min:0', 'max:100'],
            'completion_note' => ['required', 'string', 'min:10', 'max:1000'],
        ]);
        $account = SuchakAccount::query()->findOrFail((int) $validated['suchak_account_id']);

        return $this->runAcademyAction(
            fn () => $academyService->completeModule($account, $trainingModule, $request->user(), $validated),
            'Suchak training completion recorded.',
        );
    }

    public function issueCertificate(
        Request $request,
        SuchakAccount $suchakAccount,
        SuchakTrainingAcademyService $academyService,
    ): RedirectResponse {
        $validated = $request->validate([
            'certificate_note' => ['required', 'string', 'min:10', 'max:1000'],
        ]);

        return $this->runAcademyAction(
            fn () => $academyService->issueInternalCertificate($suchakAccount, $request->user(), $validated['certificate_note']),
            'Suchak internal training certificate issued.',
        );
    }

    public function storeTemplate(
        Request $request,
        SuchakTrainingAcademyService $academyService,
    ): RedirectResponse {
        $validated = $request->validate([
            'template_key' => ['required', 'string', 'max:96'],
            'template_title' => ['required', 'string', 'min:8', 'max:160'],
            'template_title_mr' => ['nullable', 'string', 'max:160'],
            'template_category' => ['required', 'string', Rule::in(SuchakMessageTemplate::CATEGORIES)],
            'template_channel' => ['required', 'string', Rule::in(SuchakMessageTemplate::CHANNELS)],
            'body_text' => ['required', 'string', 'min:10', 'max:4000'],
            'body_text_mr' => ['nullable', 'string', 'max:4000'],
            'usage_guidance' => ['nullable', 'string', 'max:1000'],
            'usage_guidance_mr' => ['nullable', 'string', 'max:1000'],
        ]);

        return $this->runAcademyAction(
            fn () => $academyService->createMessageTemplate($request->user(), $validated),
            'Suchak message template created.',
        );
    }

    private function runAcademyAction(callable $callback, string $successMessage): RedirectResponse
    {
        try {
            $callback();
        } catch (InvalidArgumentException $exception) {
            return back()->with('error', $exception->getMessage())->withInput();
        }

        return redirect()
            ->route('admin.suchak.academy.index')
            ->with('success', $successMessage);
    }
}
