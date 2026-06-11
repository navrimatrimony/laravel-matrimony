<?php

namespace App\Http\Controllers;

use App\Models\MatrimonyProfile;
use App\Services\BiodataExport\BiodataImageRenderer;
use App\Services\Gunamilan\GunamilanExplanationCatalog;
use App\Services\Gunamilan\GunamilanReportRenderer;
use App\Services\Gunamilan\GunamilanReportTemplateRegistry;
use App\Services\Gunamilan\GunamilanService;
use App\Services\ProfileLifecycleService;
use App\Services\ProfileVisibilityPolicyService;
use App\Services\ViewTrackingService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Str;
use Illuminate\View\View;

class GunamilanController extends Controller
{
    public function show(
        int $matrimony_profile_id,
        Request $request,
        GunamilanService $gunamilan,
        GunamilanExplanationCatalog $explanations,
        GunamilanReportTemplateRegistry $templates,
    ): View|RedirectResponse {
        $data = $this->reportData($matrimony_profile_id, $request, $gunamilan, $explanations, $templates);
        if ($data instanceof RedirectResponse) {
            return $data;
        }

        return view('matrimony.profile.gunamilan', $data);
    }

    public function print(
        int $matrimony_profile_id,
        Request $request,
        GunamilanService $gunamilan,
        GunamilanExplanationCatalog $explanations,
        GunamilanReportTemplateRegistry $templates,
    ): View|RedirectResponse {
        $data = $this->reportData($matrimony_profile_id, $request, $gunamilan, $explanations, $templates);
        if ($data instanceof RedirectResponse) {
            return $data;
        }

        return view($data['reportTemplate']['view'], array_merge($data, [
            'pdfMode' => false,
            'previewMode' => $request->boolean('preview'),
        ]));
    }

    public function pdf(
        int $matrimony_profile_id,
        Request $request,
        GunamilanService $gunamilan,
        GunamilanExplanationCatalog $explanations,
        GunamilanReportTemplateRegistry $templates,
        GunamilanReportRenderer $renderer,
    ): Response|RedirectResponse {
        $data = $this->reportData($matrimony_profile_id, $request, $gunamilan, $explanations, $templates);
        if ($data instanceof RedirectResponse) {
            return $data;
        }

        return response($renderer->binary($data, $data['reportTemplate']), 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'attachment; filename="'.$this->filename($data, 'pdf').'"',
        ]);
    }

    public function jpg(
        int $matrimony_profile_id,
        Request $request,
        GunamilanService $gunamilan,
        GunamilanExplanationCatalog $explanations,
        GunamilanReportTemplateRegistry $templates,
        GunamilanReportRenderer $renderer,
        BiodataImageRenderer $imageRenderer,
    ): Response|RedirectResponse {
        $data = $this->reportData($matrimony_profile_id, $request, $gunamilan, $explanations, $templates);
        if ($data instanceof RedirectResponse) {
            return $data;
        }

        try {
            $jpgBinary = $imageRenderer->jpgFromPdfBinary($renderer->binary($data, $data['reportTemplate']));
        } catch (\Throwable $e) {
            report($e);

            return redirect()
                ->route('matrimony.profile.gunamilan', $data['profile'])
                ->with('error', __('profile.gunamilan_jpg_unavailable'));
        }

        return response($jpgBinary, 200, [
            'Content-Type' => 'image/jpeg',
            'Content-Disposition' => 'attachment; filename="'.$this->filename($data, 'jpg').'"',
        ]);
    }

    /**
     * @return array<string, mixed>|RedirectResponse
     */
    private function reportData(
        int $matrimony_profile_id,
        Request $request,
        GunamilanService $gunamilan,
        GunamilanExplanationCatalog $explanations,
        GunamilanReportTemplateRegistry $templates,
    ): array|RedirectResponse
    {
        $user = $request->user();
        $viewerProfile = $user?->matrimonyProfile;

        if (! $viewerProfile) {
            return redirect()
                ->route('matrimony.profile.wizard.section', ['section' => 'basic-info'])
                ->with('error', __('interest.create_profile_first'));
        }

        $profile = $this->targetProfile($matrimony_profile_id);

        if ((int) $viewerProfile->id === (int) $profile->id) {
            return redirect()
                ->route('matrimony.profile.show', $profile)
                ->with('info', __('profile.gunamilan_own_profile_unavailable'));
        }

        if (! ProfileLifecycleService::isVisibleToOthers($profile)) {
            abort(404, __('common.profile_not_found'));
        }

        if (ViewTrackingService::isBlocked((int) $viewerProfile->id, (int) $profile->id)) {
            abort(404, __('common.profile_not_found'));
        }

        if (! ProfileVisibilityPolicyService::canViewProfile($profile, $user)) {
            abort(404, __('common.profile_not_found'));
        }

        $viewerProfile->loadMissing([
            'gender',
            'horoscope.rashi',
            'horoscope.nakshatra',
            'horoscope.gan',
            'horoscope.nadi',
            'horoscope.yoni',
            'horoscope.mangalDoshType',
            'birthCity',
        ]);

        $result = $gunamilan->calculate($viewerProfile, $profile);
        $requestedFormat = $request->query('report_format');
        $reportTemplate = $templates->resolve(is_string($requestedFormat) ? $requestedFormat : null);

        return [
            'profile' => $profile,
            'viewerProfile' => $viewerProfile,
            'result' => $result,
            'explanation' => $explanations->enrich($result),
            'reportTemplates' => $templates->all(),
            'reportTemplate' => $reportTemplate,
            'selectedReportFormat' => $reportTemplate['key'],
        ];
    }

    private function targetProfile(int $matrimonyProfileId): MatrimonyProfile
    {
        return MatrimonyProfile::with([
            'user',
            'gender',
            'horoscope.rashi',
            'horoscope.nakshatra',
            'horoscope.gan',
            'horoscope.nadi',
            'horoscope.yoni',
            'horoscope.mangalDoshType',
            'birthCity',
        ])->findOrFail($matrimonyProfileId);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function filename(array $data, string $extension): string
    {
        $bride = $this->profileName($data['result']['bride_profile_id'] ?? null, $data);
        $groom = $this->profileName($data['result']['groom_profile_id'] ?? null, $data);

        $slug = Str::slug(trim($bride.'-'.$groom.'-gunamilan-report'), '-');

        return ($slug !== '' ? $slug : 'gunamilan-report').'.'.$extension;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function profileName(mixed $profileId, array $data): string
    {
        $viewerProfile = $data['viewerProfile'] ?? null;
        $profile = $data['profile'] ?? null;

        $candidate = null;
        if ($viewerProfile instanceof MatrimonyProfile && (int) $viewerProfile->id === (int) $profileId) {
            $candidate = $viewerProfile;
        }
        if ($profile instanceof MatrimonyProfile && (int) $profile->id === (int) $profileId) {
            $candidate = $profile;
        }

        return trim((string) ($candidate?->full_name ?? 'profile')) ?: 'profile';
    }
}
