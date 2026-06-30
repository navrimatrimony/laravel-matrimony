<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\MatrimonyProfile;
use App\Models\User;
use App\Services\BiodataExport\BiodataExportPolicyService;
use App\Services\BiodataExport\BiodataImageRenderer;
use App\Services\BiodataExport\BiodataPayloadBuilder;
use App\Services\BiodataExport\BiodataPdfRenderer;
use App\Services\BiodataExport\BiodataTemplateRegistry;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;
use Throwable;

class MobileBiodataExportApiController extends Controller
{
    private const LINK_TTL_MINUTES = 15;

    public function options(
        Request $request,
        BiodataTemplateRegistry $templates,
        BiodataExportPolicyService $policy,
    ): JsonResponse {
        $user = $request->user();
        if (! $user instanceof User) {
            return $this->error('Unauthenticated.', 401);
        }

        $profile = $this->ownProfile($user);
        $formats = $this->supportedFormats();
        $state = $policy->exportState($user);
        $hasProfile = $profile instanceof MatrimonyProfile;
        $pdfAvailable = in_array('pdf', $formats, true);
        $canExport = $hasProfile && $pdfAvailable && (bool) ($state['allowed'] ?? false);
        $message = $this->optionsMessage($hasProfile, $pdfAvailable, $state);
        $canUsePremiumTemplate = $policy->canUsePremiumTemplate($user);

        return response()->json([
            'success' => true,
            'message' => $message,
            'available' => $hasProfile && $pdfAvailable,
            'can_export' => $canExport,
            'supported_formats' => $formats,
            'unsupported_formats' => $this->unsupportedFormats($formats),
            'default_format' => in_array('pdf', $formats, true) ? 'pdf' : ($formats[0] ?? null),
            'default_template' => $this->defaultTemplateKey($templates, $canUsePremiumTemplate),
            'templates' => collect($templates->all())
                ->map(fn (array $template): array => $this->templatePayload($template, $canUsePremiumTemplate))
                ->values()
                ->all(),
            'export_state' => $this->exportStatePayload($state),
            'warnings' => $profile instanceof MatrimonyProfile ? $this->profileWarnings($profile) : [
                'Create your matrimony profile before exporting biodata.',
            ],
        ]);
    }

    public function export(
        Request $request,
        BiodataTemplateRegistry $templates,
        BiodataExportPolicyService $policy,
    ): JsonResponse {
        $user = $request->user();
        if (! $user instanceof User) {
            return $this->error('Unauthenticated.', 401);
        }

        $profile = $this->ownProfile($user);
        if (! $profile instanceof MatrimonyProfile) {
            return $this->error('Create your matrimony profile before exporting biodata.', 422, 'profile_missing');
        }

        $data = $request->validate([
            'format' => ['nullable', 'string', 'max:10'],
            'template' => ['nullable', 'string', 'max:80', 'regex:/^[A-Za-z0-9_-]+$/'],
        ]);

        $formats = $this->supportedFormats();
        $format = strtolower(trim((string) ($data['format'] ?? 'pdf'))) ?: 'pdf';
        if (! in_array($format, $formats, true)) {
            return $this->error($this->unsupportedFormatMessage($format), 422, 'unsupported_format');
        }

        $canUsePremiumTemplate = $policy->canUsePremiumTemplate($user);
        $templateKey = trim((string) ($data['template'] ?? ''));
        if ($templateKey === '') {
            $templateKey = $this->defaultTemplateKey($templates, $canUsePremiumTemplate);
        }

        $template = $templates->find($templateKey);
        if ($template === null) {
            return $this->error('Biodata template not found.', 422, 'template_not_found');
        }

        if ($this->isPremiumTemplateLocked($template, $canUsePremiumTemplate)) {
            return $this->error('This biodata template is not included in your current plan.', 422, 'premium_template_required');
        }

        $state = $policy->exportState($user);
        if (($state['allowed'] ?? false) !== true) {
            return $this->error($this->quotaMessage($state), 422, 'biodata_export_quota_exhausted');
        }

        $expiresAt = now()->addMinutes(self::LINK_TTL_MINUTES);
        $signedPath = URL::temporarySignedRoute(
            'mobile.biodata.export.download',
            $expiresAt,
            [
                'user' => (int) $user->id,
                'profile' => (int) $profile->id,
                'template' => $templateKey,
                'format' => $format,
            ],
            false,
        );

        return response()->json([
            'success' => true,
            'message' => 'Biodata export link created.',
            'format' => $format,
            'template' => $this->templatePayload($template, $canUsePremiumTemplate),
            'download_url' => rtrim($request->getSchemeAndHttpHost(), '/').$signedPath,
            'expires_at' => $expiresAt->toIso8601String(),
            'expires_in_seconds' => self::LINK_TTL_MINUTES * 60,
        ]);
    }

    public function download(
        Request $request,
        BiodataTemplateRegistry $templates,
        BiodataExportPolicyService $policy,
        BiodataPayloadBuilder $payloadBuilder,
        BiodataPdfRenderer $pdfRenderer,
        BiodataImageRenderer $imageRenderer,
    ): Response {
        if (! $request->hasValidSignature(false)) {
            return $this->blockedPage(
                'Biodata link expired',
                'This biodata export link is invalid or expired. Please create a fresh link from the app.',
                403,
            );
        }

        $user = User::query()->find((int) $request->query('user', 0));
        $profile = MatrimonyProfile::query()->find((int) $request->query('profile', 0));
        if (! $user instanceof User || ! $profile instanceof MatrimonyProfile || (int) $profile->user_id !== (int) $user->id) {
            return $this->blockedPage(
                'Biodata unavailable',
                'This biodata export link could not be matched to the profile owner.',
                403,
            );
        }

        $format = strtolower(trim((string) $request->query('format', 'pdf'))) ?: 'pdf';
        if (! in_array($format, $this->supportedFormats(), true)) {
            return $this->blockedPage('Format unavailable', $this->unsupportedFormatMessage($format), 422);
        }

        $templateKey = trim((string) $request->query('template', ''));
        $template = $templates->find($templateKey);
        if ($template === null) {
            return $this->blockedPage('Template unavailable', 'Biodata template not found.', 422);
        }

        if ($this->isPremiumTemplateLocked($template, $policy->canUsePremiumTemplate($user))) {
            return $this->blockedPage(
                'Template locked',
                'This biodata template is not included in your current plan.',
                422,
            );
        }

        $state = $policy->exportState($user);
        if (($state['allowed'] ?? false) !== true) {
            return $this->blockedPage('Download limit reached', $this->quotaMessage($state), 422);
        }

        try {
            $rendered = $this->renderExport(
                $format,
                $payloadBuilder->build($profile),
                $template,
                $pdfRenderer,
                $imageRenderer,
            );
        } catch (Throwable $exception) {
            report($exception);

            return $this->blockedPage(
                'Biodata export unavailable',
                $format === 'jpg'
                    ? 'JPG export is not available on this server right now. Please download PDF.'
                    : 'Biodata PDF export is not available right now. Please try again later.',
                422,
            );
        }

        if (! $policy->consumeExport($user)) {
            return $this->blockedPage('Download limit reached', $this->quotaMessage($policy->exportState($user)), 422);
        }

        return response($rendered['binary'], 200, [
            'Content-Type' => $rendered['content_type'],
            'Content-Disposition' => 'attachment; filename="'.$this->filename($profile, $template, $rendered['extension']).'"',
        ]);
    }

    private function ownProfile(User $user): ?MatrimonyProfile
    {
        $user->loadMissing('matrimonyProfile');

        return $user->matrimonyProfile instanceof MatrimonyProfile ? $user->matrimonyProfile : null;
    }

    /**
     * @return list<string>
     */
    private function supportedFormats(): array
    {
        $formats = [];

        if (class_exists(Pdf::class) && class_exists(BiodataPdfRenderer::class)) {
            $formats[] = 'pdf';
        }

        if (in_array('pdf', $formats, true) && class_exists(BiodataImageRenderer::class) && class_exists(\Imagick::class)) {
            $formats[] = 'jpg';
        }

        return $formats;
    }

    /**
     * @param  list<string>  $supportedFormats
     * @return list<array<string, string>>
     */
    private function unsupportedFormats(array $supportedFormats): array
    {
        $unsupported = [];
        if (! in_array('jpg', $supportedFormats, true)) {
            $unsupported[] = [
                'format' => 'jpg',
                'reason' => 'JPG export needs Imagick on the server. PDF export is available.',
            ];
        }

        return $unsupported;
    }

    private function defaultTemplateKey(BiodataTemplateRegistry $templates, bool $canUsePremiumTemplate): string
    {
        foreach ($templates->all() as $template) {
            if (! $this->isPremiumTemplateLocked($template, $canUsePremiumTemplate)) {
                return (string) $template['key'];
            }
        }

        $first = array_values($templates->all())[0] ?? [];

        return (string) ($first['key'] ?? '');
    }

    /**
     * @param  array<string, mixed>  $template
     * @return array<string, mixed>
     */
    private function templatePayload(array $template, bool $canUsePremiumTemplate): array
    {
        $locked = $this->isPremiumTemplateLocked($template, $canUsePremiumTemplate);

        return [
            'key' => (string) ($template['key'] ?? ''),
            'label' => (string) ($template['label'] ?? ''),
            'description' => (string) ($template['description'] ?? ''),
            'orientation' => (string) ($template['orientation'] ?? 'portrait'),
            'with_photo' => (bool) ($template['with_photo'] ?? false),
            'premium' => (bool) ($template['premium'] ?? false),
            'available' => ! $locked,
            'locked_reason' => $locked ? 'This biodata template is not included in your current plan.' : null,
        ];
    }

    /**
     * @param  array<string, mixed>  $template
     */
    private function isPremiumTemplateLocked(array $template, bool $canUsePremiumTemplate): bool
    {
        return (bool) ($template['premium'] ?? false) && ! $canUsePremiumTemplate;
    }

    /**
     * @param  array<string, mixed>  $state
     * @return array<string, mixed>
     */
    private function exportStatePayload(array $state): array
    {
        return [
            'allowed' => (bool) ($state['allowed'] ?? false),
            'limit' => $state['limit'] ?? null,
            'used' => isset($state['used']) ? (int) $state['used'] : 0,
            'remaining' => $state['remaining'] ?? null,
            'unlimited' => (bool) ($state['unlimited'] ?? false),
            'reset_at' => $this->dateValue($state['reset_at'] ?? null),
            'reason' => $state['reason'] ?? null,
        ];
    }

    /**
     * @return list<string>
     */
    private function profileWarnings(MatrimonyProfile $profile): array
    {
        $warnings = [];

        if (trim((string) ($profile->full_name ?? '')) === '') {
            $warnings[] = 'Full name is missing.';
        }
        if (empty($profile->date_of_birth)) {
            $warnings[] = 'Date of birth is missing.';
        }
        if (empty($profile->gender_id)) {
            $warnings[] = 'Gender is missing.';
        }
        if (empty($profile->location_id)) {
            $warnings[] = 'Current location is missing.';
        }
        if (trim((string) ($profile->highest_education ?? '')) === '') {
            $warnings[] = 'Education is missing.';
        }
        if (trim((string) ($profile->occupation_title ?? '')) === '') {
            $warnings[] = 'Occupation is missing.';
        }

        return $warnings;
    }

    /**
     * @param  array<string, mixed>  $state
     */
    private function optionsMessage(bool $hasProfile, bool $pdfAvailable, array $state): string
    {
        if (! $hasProfile) {
            return 'Create your matrimony profile before exporting biodata.';
        }
        if (! $pdfAvailable) {
            return 'PDF export is not available because the server PDF renderer is missing.';
        }
        if (($state['allowed'] ?? false) !== true) {
            return $this->quotaMessage($state);
        }

        return 'Biodata export is available.';
    }

    /**
     * @param  array<string, mixed>  $state
     */
    private function quotaMessage(array $state): string
    {
        $reason = trim((string) ($state['reason'] ?? ''));

        return $reason !== ''
            ? $reason
            : 'Your biodata download limit is over for this period. Please upgrade your plan.';
    }

    private function unsupportedFormatMessage(string $format): string
    {
        if ($format === 'jpg') {
            return 'JPG export is not available on this server right now. Please download PDF.';
        }

        return 'Unsupported biodata export format.';
    }

    /**
     * @param  array<string, mixed>  $payload
     * @param  array<string, mixed>  $template
     * @return array{binary: string, content_type: string, extension: string}
     */
    private function renderExport(
        string $format,
        array $payload,
        array $template,
        BiodataPdfRenderer $pdfRenderer,
        BiodataImageRenderer $imageRenderer,
    ): array {
        $pdfBinary = $pdfRenderer->binary($payload, $template);

        if ($format === 'jpg') {
            return [
                'binary' => $imageRenderer->jpgFromPdfBinary($pdfBinary),
                'content_type' => 'image/jpeg',
                'extension' => 'jpg',
            ];
        }

        return [
            'binary' => $pdfBinary,
            'content_type' => 'application/pdf',
            'extension' => 'pdf',
        ];
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

    private function dateValue(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }
        if (is_object($value) && method_exists($value, 'toIso8601String')) {
            return $value->toIso8601String();
        }

        return (string) $value;
    }

    private function error(string $message, int $status, ?string $blockedReason = null): JsonResponse
    {
        $payload = [
            'success' => false,
            'message' => $message,
        ];
        if ($blockedReason !== null) {
            $payload['blocked_reason'] = $blockedReason;
        }

        return response()->json($payload, $status);
    }

    private function blockedPage(string $title, string $message, int $status): Response
    {
        $html = '<!doctype html>'
            .'<html lang="en">'
            .'<head>'
            .'<meta charset="utf-8">'
            .'<meta name="viewport" content="width=device-width, initial-scale=1">'
            .'<title>'.e($title).'</title>'
            .'<style>'
            .'body{margin:0;font-family:system-ui,-apple-system,BlinkMacSystemFont,"Segoe UI",sans-serif;background:#f8f4ef;color:#2f1f1f;}'
            .'.wrap{min-height:100vh;display:flex;align-items:center;justify-content:center;padding:24px;}'
            .'.box{max-width:540px;width:100%;background:#fff;border:1px solid #eadbd4;border-radius:12px;padding:24px;box-shadow:0 10px 30px rgba(47,31,31,.08);}'
            .'h1{margin:0 0 10px;font-size:22px;line-height:1.2;color:#9f1239;}'
            .'p{margin:0;color:#5f4a45;line-height:1.5;}'
            .'</style>'
            .'</head>'
            .'<body><div class="wrap"><main class="box">'
            .'<h1>'.e($title).'</h1>'
            .'<p>'.e($message).'</p>'
            .'</main></div></body></html>';

        return response($html, $status)->header('Content-Type', 'text/html; charset=UTF-8');
    }
}
