<?php

namespace App\Http\Controllers\Admin\Suchak;

use App\Http\Controllers\Controller;
use App\Models\SuchakPolicy;
use App\Modules\Suchak\Services\SuchakPolicyService;
use App\Services\AuditLogService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

/**
 * Goal 4: Admin Suchak APK branding settings (theme, homepage photo, logos).
 */
class ApkSettingsController extends Controller
{
    public function index(SuchakPolicyService $policyService): View
    {
        $config = $policyService->apkConfig();

        return view('admin.suchak.apk-settings', [
            'config' => $config,
            'specs' => [
                'homepage' => '9:16 portrait · recommend 1080×1920 · JPG/WebP · max 2 MB',
                'logo' => '1:1 · 512×512 · PNG transparent · max 500 KB',
            ],
        ]);
    }

    public function update(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'reason' => ['required', 'string', 'min:10', 'max:500'],
            'theme_color' => ['required', 'string', 'regex:/^#[0-9a-fA-F]{6}$/'],
            'tagline_mr' => ['nullable', 'string', 'max:240'],
            'tagline_en' => ['nullable', 'string', 'max:240'],
            'homepage_photo' => ['nullable', 'image', 'mimes:jpg,jpeg,png,webp', 'max:2048'],
            'logo_light' => ['nullable', 'image', 'mimes:png', 'max:512'],
            'logo_dark' => ['nullable', 'image', 'mimes:png', 'max:512'],
            'remove_homepage_photo' => ['nullable', 'boolean'],
            'remove_logo_light' => ['nullable', 'boolean'],
            'remove_logo_dark' => ['nullable', 'boolean'],
        ]);

        $old = app(SuchakPolicyService::class)->apkConfig();

        $homepagePath = $this->storeOrKeep(
            $request,
            'homepage_photo',
            'remove_homepage_photo',
            $old['homepage_photo_path'],
            'suchak/apk/homepage',
        );
        $logoLight = $this->storeOrKeep(
            $request,
            'logo_light',
            'remove_logo_light',
            $old['logo_light_path'],
            'suchak/apk/logos',
        );
        $logoDark = $this->storeOrKeep(
            $request,
            'logo_dark',
            'remove_logo_dark',
            $old['logo_dark_path'],
            'suchak/apk/logos',
        );

        $tagline = [
            'mr' => trim((string) ($validated['tagline_mr'] ?? '')),
            'en' => trim((string) ($validated['tagline_en'] ?? '')),
        ];

        $rows = [
            SuchakPolicyService::KEY_SUCHAK_APK_THEME_COLOR => [
                'policy_value' => (string) $validated['theme_color'],
                'value_type' => SuchakPolicy::TYPE_STRING,
                'description' => 'Suchak APK primary theme color (#RRGGBB).',
            ],
            SuchakPolicyService::KEY_SUCHAK_APK_HOMEPAGE_PHOTO_PATH => [
                'policy_value' => $homepagePath,
                'value_type' => SuchakPolicy::TYPE_STRING,
                'description' => 'Suchak APK welcome homepage photo (public disk path).',
            ],
            SuchakPolicyService::KEY_SUCHAK_APK_LOGO_LIGHT_PATH => [
                'policy_value' => $logoLight,
                'value_type' => SuchakPolicy::TYPE_STRING,
                'description' => 'Suchak APK light-theme logo (public disk path).',
            ],
            SuchakPolicyService::KEY_SUCHAK_APK_LOGO_DARK_PATH => [
                'policy_value' => $logoDark,
                'value_type' => SuchakPolicy::TYPE_STRING,
                'description' => 'Suchak APK dark-theme logo (public disk path).',
            ],
            SuchakPolicyService::KEY_SUCHAK_APK_TAGLINE_JSON => [
                'policy_value' => json_encode($tagline, JSON_THROW_ON_ERROR),
                'value_type' => SuchakPolicy::TYPE_JSON,
                'description' => 'Suchak APK welcome tagline (mr/en).',
            ],
        ];

        DB::transaction(function () use ($rows, $request, $old): void {
            foreach ($rows as $key => $row) {
                SuchakPolicy::query()->updateOrCreate(
                    ['policy_key' => $key],
                    [
                        'policy_value' => $row['policy_value'],
                        'value_type' => $row['value_type'],
                        'description' => $row['description'],
                        'is_active' => true,
                    ],
                );
            }

            AuditLogService::log(
                $request->user(),
                'suchak_apk_settings_update',
                'suchak_policy',
                null,
                'Suchak APK settings update. Reason: '.trim((string) $request->input('reason')).'. Before: '.json_encode($old, JSON_THROW_ON_ERROR),
                false,
            );
        });

        return redirect()
            ->route('admin.suchak.apk-settings.index')
            ->with('success', 'Suchak APK settings saved.');
    }

    private function storeOrKeep(
        Request $request,
        string $fileField,
        string $removeField,
        string $currentPath,
        string $directory,
    ): string {
        if ($request->boolean($removeField)) {
            $this->deletePublicPath($currentPath);

            return '';
        }

        if ($request->hasFile($fileField)) {
            $stored = $request->file($fileField)->store($directory, 'public');
            if (! is_string($stored) || $stored === '') {
                throw ValidationException::withMessages([
                    $fileField => 'Could not store the uploaded file.',
                ]);
            }
            if ($currentPath !== '' && $currentPath !== $stored) {
                $this->deletePublicPath($currentPath);
            }

            return $stored;
        }

        return $currentPath;
    }

    private function deletePublicPath(string $path): void
    {
        $path = trim($path);
        if ($path === '' || ! str_starts_with($path, 'suchak/apk/')) {
            return;
        }
        Storage::disk('public')->delete($path);
    }
}
