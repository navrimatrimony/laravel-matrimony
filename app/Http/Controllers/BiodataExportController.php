<?php

namespace App\Http\Controllers;

use App\Models\MatrimonyProfile;
use App\Services\BiodataExport\BiodataExportPolicyService;
use App\Services\BiodataExport\BiodataImageRenderer;
use App\Services\BiodataExport\BiodataPayloadBuilder;
use App\Services\BiodataExport\BiodataPdfRenderer;
use App\Services\BiodataExport\BiodataTemplateRegistry;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Str;
use Illuminate\View\View;

class BiodataExportController extends Controller
{
    public function index(
        Request $request,
        BiodataTemplateRegistry $templates,
        BiodataExportPolicyService $policy,
    ): View|RedirectResponse {
        $profile = $this->ownProfile($request);
        if (! $profile) {
            return $this->missingProfileRedirect();
        }

        return view('biodata.index', [
            'profile' => $profile,
            'templates' => $templates->all(),
            'exportState' => $policy->exportState($request->user()),
            'canUsePremiumTemplate' => $policy->canUsePremiumTemplate($request->user()),
        ]);
    }

    public function preview(
        Request $request,
        string $template,
        BiodataTemplateRegistry $templates,
        BiodataExportPolicyService $policy,
        BiodataPayloadBuilder $payloadBuilder,
    ): View|RedirectResponse {
        $profile = $this->ownProfile($request);
        if (! $profile) {
            return $this->missingProfileRedirect();
        }

        $templateData = $this->templateOrAbort($templates, $template);
        if ($redirect = $this->premiumRedirectIfNeeded($request, $policy, $templateData)) {
            return $redirect;
        }

        return view('biodata.preview', [
            'profile' => $profile,
            'template' => $templateData,
            'payload' => $payloadBuilder->build($profile),
            'exportState' => $policy->exportState($request->user()),
        ]);
    }

    public function pdf(
        Request $request,
        string $template,
        BiodataTemplateRegistry $templates,
        BiodataExportPolicyService $policy,
        BiodataPayloadBuilder $payloadBuilder,
        BiodataPdfRenderer $renderer,
    ): Response|RedirectResponse {
        return $this->downloadPdf($request, $template, $templates, $policy, $payloadBuilder, $renderer, true);
    }

    public function print(
        Request $request,
        string $template,
        BiodataTemplateRegistry $templates,
        BiodataExportPolicyService $policy,
        BiodataPayloadBuilder $payloadBuilder,
        BiodataPdfRenderer $renderer,
    ): Response|RedirectResponse {
        return $this->downloadPdf($request, $template, $templates, $policy, $payloadBuilder, $renderer, false);
    }

    public function jpg(
        Request $request,
        string $template,
        BiodataTemplateRegistry $templates,
        BiodataExportPolicyService $policy,
        BiodataPayloadBuilder $payloadBuilder,
        BiodataPdfRenderer $pdfRenderer,
        BiodataImageRenderer $imageRenderer,
    ): Response|RedirectResponse {
        $profile = $this->ownProfile($request);
        if (! $profile) {
            return $this->missingProfileRedirect();
        }

        $templateData = $this->templateOrAbort($templates, $template);
        if ($redirect = $this->premiumRedirectIfNeeded($request, $policy, $templateData)) {
            return $redirect;
        }
        if ($redirect = $this->quotaRedirectIfNeeded($request, $policy)) {
            return $redirect;
        }

        try {
            $payload = $payloadBuilder->build($profile);
            $pdfBinary = $pdfRenderer->binary($payload, $templateData);
            $jpgBinary = $imageRenderer->jpgFromPdfBinary($pdfBinary);
        } catch (\Throwable $e) {
            report($e);

            return redirect()
                ->route('matrimony.profile.biodata.index')
                ->with('error', 'JPG export is not available on this server right now. Please download PDF.');
        }

        if (! $policy->consumeExport($request->user())) {
            return $this->quotaRedirectIfNeeded($request, $policy) ?? redirect()->route('matrimony.profile.biodata.index');
        }

        return response($jpgBinary, 200, [
            'Content-Type' => 'image/jpeg',
            'Content-Disposition' => 'attachment; filename="'.$this->filename($profile, $templateData, 'jpg').'"',
        ]);
    }

    private function downloadPdf(
        Request $request,
        string $template,
        BiodataTemplateRegistry $templates,
        BiodataExportPolicyService $policy,
        BiodataPayloadBuilder $payloadBuilder,
        BiodataPdfRenderer $renderer,
        bool $attachment,
    ): Response|RedirectResponse {
        $profile = $this->ownProfile($request);
        if (! $profile) {
            return $this->missingProfileRedirect();
        }

        $templateData = $this->templateOrAbort($templates, $template);
        if ($redirect = $this->premiumRedirectIfNeeded($request, $policy, $templateData)) {
            return $redirect;
        }
        if ($redirect = $this->quotaRedirectIfNeeded($request, $policy)) {
            return $redirect;
        }

        $binary = $renderer->binary($payloadBuilder->build($profile), $templateData);

        if (! $policy->consumeExport($request->user())) {
            return $this->quotaRedirectIfNeeded($request, $policy) ?? redirect()->route('matrimony.profile.biodata.index');
        }

        $disposition = $attachment ? 'attachment' : 'inline';

        return response($binary, 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => $disposition.'; filename="'.$this->filename($profile, $templateData, 'pdf').'"',
        ]);
    }

    private function ownProfile(Request $request): ?MatrimonyProfile
    {
        $user = $request->user();
        if (! $user) {
            return null;
        }

        $user->loadMissing('matrimonyProfile');

        return $user->matrimonyProfile;
    }

    private function missingProfileRedirect(): RedirectResponse
    {
        return redirect()
            ->route('matrimony.profile.wizard.section', ['section' => 'basic-info'])
            ->with('error', __('interest.create_profile_first'));
    }

    /**
     * @return array<string, mixed>
     */
    private function templateOrAbort(BiodataTemplateRegistry $templates, string $template): array
    {
        $templateData = $templates->find($template);
        abort_if($templateData === null, 404, 'Biodata template not found.');

        return $templateData;
    }

    /**
     * @param  array<string, mixed>  $template
     */
    private function premiumRedirectIfNeeded(Request $request, BiodataExportPolicyService $policy, array $template): ?RedirectResponse
    {
        if (empty($template['premium']) || $policy->canUsePremiumTemplate($request->user())) {
            return null;
        }

        return redirect()
            ->route('matrimony.profile.biodata.index')
            ->with('error', 'This biodata template is not included in your current plan.');
    }

    private function quotaRedirectIfNeeded(Request $request, BiodataExportPolicyService $policy): ?RedirectResponse
    {
        $state = $policy->exportState($request->user());
        if (($state['allowed'] ?? false) === true) {
            return null;
        }

        return redirect()
            ->route('matrimony.profile.biodata.index')
            ->with('error', 'Your biodata download limit is over for this period. Please upgrade your plan.');
    }

    /**
     * @param  array<string, mixed>  $template
     */
    private function filename(MatrimonyProfile $profile, array $template, string $extension): string
    {
        $name = Str::slug(trim((string) ($profile->full_name ?? '')) ?: 'profile');
        if ($name === '') {
            $name = 'profile';
        }

        return 'biodata-'.$name.'-'.$profile->id.'-'.$template['key'].'.'.$extension;
    }
}
