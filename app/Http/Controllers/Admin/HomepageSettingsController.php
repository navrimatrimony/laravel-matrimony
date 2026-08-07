<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\HomepageSectionImage;
use App\Models\HomepageSuccessStory;
use App\Services\Admin\HomepageContentService;
use App\Services\Admin\HomepageImageService;
use App\Services\AuditLogService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class HomepageSettingsController extends Controller
{
    public function __construct(
        private HomepageContentService $homepageContent,
        private HomepageImageService $homepageImages,
    ) {
    }

    public function index(): View
    {
        $settings = $this->homepageContent->settings();
        $imagePaths = $this->homepageImages->allPaths();
        $sections = [];

        foreach (HomepageSectionImage::SECTIONS as $key => $label) {
            $sections[] = [
                'key' => $key,
                'label' => $label,
                'current_path' => $imagePaths[$key] ?? null,
                'current_url' => $this->homepageImages->url($key),
            ];
        }

        return view('admin.homepage-settings.index', [
            'settings' => $settings,
            'sections' => $sections,
            'stories' => HomepageSuccessStory::query()
                ->orderBy('sort_order')
                ->orderByDesc('is_featured')
                ->latest('id')
                ->get(),
        ]);
    }

    public function update(Request $request): RedirectResponse
    {
        $defaults = $this->homepageContent->defaults();
        $sectionKeys = array_keys($defaults['sections']);
        $searchFieldKeys = array_keys($defaults['search_fields']);

        // Homepage prose is no longer posted here — it lives in
        // lang/{mr,en}/homepage.php and is overridable through Admin ->
        // Translations. Section order is fixed in HomepageContentService.
        $request->validate([
            'app_android_url' => ['nullable', 'url', 'max:500'],
            'app_ios_url' => ['nullable', 'url', 'max:500'],
            'hero_search_age_control' => ['required', Rule::in(['inputs', 'slider'])],
            'hero_search_community_mode' => ['required', Rule::in(['none', 'caste', 'religion_caste'])],
            'hero_search_location_mode' => ['required', Rule::in(['none', 'state', 'state_district'])],
            'section_enabled' => ['array'],
            'section_enabled.*' => ['string', Rule::in($sectionKeys)],
            'search_fields' => ['array'],
            'search_fields.*' => ['string', Rule::in($searchFieldKeys)],
        ]);

        $current = $this->homepageContent->settings();
        // story_limit has no form control; carry the stored value through so a
        // save does not quietly reset it.
        $preserveKeys = ['sections', 'search_fields', 'story_limit'];

        $settings = [];
        foreach (array_keys($defaults) as $key) {
            if (in_array($key, $preserveKeys, true)) {
                continue;
            }
            $settings[$key] = $request->input($key, $defaults[$key]);
        }

        $settings['story_limit'] = $current['story_limit'] ?? $defaults['story_limit'];
        $settings['app_show_android'] = $request->boolean('app_show_android');
        $settings['app_show_ios'] = $request->boolean('app_show_ios');

        $enabledSections = collect($request->input('section_enabled', []))->map(fn ($v) => (string) $v)->all();
        foreach ($sectionKeys as $key) {
            $settings['sections'][$key] = [
                'enabled' => in_array($key, $enabledSections, true),
            ];
        }

        $enabledSearchFields = collect($request->input('search_fields', []))->map(fn ($v) => (string) $v)->all();
        foreach ($searchFieldKeys as $key) {
            $settings['search_fields'][$key] = in_array($key, $enabledSearchFields, true);
        }

        $this->homepageContent->save($settings);

        AuditLogService::log(
            $request->user(),
            'update_homepage_settings',
            'AdminSetting',
            null,
            'Homepage section visibility, app store links, and search fields updated.',
            false
        );

        return redirect()->route('admin.homepage-settings.index')
            ->with('success', 'Homepage settings updated.');
    }

    /**
     * Retired. The success-story slider knobs it wrote were readable by the
     * homepage and writable by this route, but their only form lived in a
     * partial that was never included anywhere — configuration that looked
     * live and was not. The values are now constants in the homepage view.
     *
     * Kept as a harmless redirect because the route that points at it lives in
     * routes/web/admin.php, which this change does not own. Delete this method
     * and that route line together.
     */
    public function updateStoriesDisplay(Request $request): RedirectResponse
    {
        return redirect()->route('admin.homepage-settings.index', ['tab' => 'stories'])
            ->with('success', 'Success story display settings are no longer configurable.');
    }

    public function storeImage(Request $request): RedirectResponse
    {
        $request->validate([
            'section_key' => ['required', 'string', Rule::in(array_keys(HomepageSectionImage::SECTIONS))],
            'image' => ['required', 'image', 'max:5120'],
        ]);

        $sectionKey = (string) $request->input('section_key');
        $file = $request->file('image');
        $extension = strtolower($file->getClientOriginalExtension() ?: 'jpg');
        $filename = $sectionKey.'_'.time().'.'.$extension;
        $directory = public_path('images/homepage');
        if (! is_dir($directory)) {
            mkdir($directory, 0775, true);
        }
        $file->move($directory, $filename);
        $this->homepageImages->set($sectionKey, 'images/homepage/'.$filename);

        AuditLogService::log(
            $request->user(),
            'update_homepage_section_image',
            'HomepageSectionImage',
            null,
            'Homepage image updated for '.$sectionKey.'.',
            false
        );

        return redirect()->route('admin.homepage-settings.index', ['tab' => 'images'])
            ->with('success', 'Homepage image updated for '.HomepageSectionImage::SECTIONS[$sectionKey].'.');
    }

    public function clearImage(Request $request): RedirectResponse
    {
        $request->validate([
            'section_key' => ['required', 'string', Rule::in(array_keys(HomepageSectionImage::SECTIONS))],
        ]);

        $sectionKey = (string) $request->input('section_key');
        $this->homepageImages->set($sectionKey, null);

        AuditLogService::log(
            $request->user(),
            'clear_homepage_section_image',
            'HomepageSectionImage',
            null,
            'Homepage image cleared for '.$sectionKey.'.',
            false
        );

        return redirect()->route('admin.homepage-settings.index', ['tab' => 'images'])
            ->with('success', 'Homepage image cleared for '.HomepageSectionImage::SECTIONS[$sectionKey].'.');
    }

    public function storeStory(Request $request): RedirectResponse
    {
        $data = $this->validateStory($request);
        $data['created_by_admin_id'] = $request->user()?->id;
        $data['image_path'] = $this->storeStoryImage($request) ?: null;

        HomepageSuccessStory::create($data);

        AuditLogService::log($request->user(), 'create_homepage_success_story', 'HomepageSuccessStory', null, 'Created homepage success story.', false);

        return redirect()->route('admin.homepage-settings.index', ['tab' => 'stories'])
            ->with('success', 'Success story added.');
    }

    public function updateStory(Request $request, HomepageSuccessStory $story): RedirectResponse
    {
        $data = $this->validateStory($request);
        $newImage = $this->storeStoryImage($request);
        if ($newImage) {
            $data['image_path'] = $newImage;
        }

        $story->update($data);

        AuditLogService::log($request->user(), 'update_homepage_success_story', 'HomepageSuccessStory', $story->id, 'Updated homepage success story.', false);

        return redirect()->route('admin.homepage-settings.index', ['tab' => 'stories'])
            ->with('success', 'Success story updated.');
    }

    public function destroyStory(Request $request, HomepageSuccessStory $story): RedirectResponse
    {
        $storyId = $story->id;
        $story->delete();

        AuditLogService::log($request->user(), 'delete_homepage_success_story', 'HomepageSuccessStory', $storyId, 'Deleted homepage success story.', false);

        return redirect()->route('admin.homepage-settings.index', ['tab' => 'stories'])
            ->with('success', 'Success story deleted.');
    }

    /**
     * @return array<string, mixed>
     */
    private function validateStory(Request $request): array
    {
        $validated = $request->validate([
            'couple_names' => ['required', 'string', 'max:160'],
            'location' => ['nullable', 'string', 'max:160'],
            'wedding_date' => ['nullable', 'date'],
            'story_mr' => ['nullable', 'string', 'max:3000'],
            'story_en' => ['nullable', 'string', 'max:3000'],
            'image' => ['nullable', 'image', 'max:5120'],
            'is_published' => ['nullable', 'in:0,1'],
            'is_featured' => ['nullable', 'in:0,1'],
            'consent_confirmed' => ['nullable', 'in:0,1'],
            'sort_order' => ['nullable', 'integer', 'min:0', 'max:999'],
        ]);

        return [
            'couple_names' => trim((string) $validated['couple_names']),
            'location' => trim((string) ($validated['location'] ?? '')),
            'wedding_date' => $validated['wedding_date'] ?? null,
            'story_mr' => trim((string) ($validated['story_mr'] ?? '')),
            'story_en' => trim((string) ($validated['story_en'] ?? '')),
            'is_published' => $request->boolean('is_published'),
            'is_featured' => $request->boolean('is_featured'),
            'consent_confirmed' => $request->boolean('consent_confirmed'),
            'sort_order' => (int) ($validated['sort_order'] ?? 0),
        ];
    }

    private function storeStoryImage(Request $request): ?string
    {
        if (! $request->hasFile('image')) {
            return null;
        }

        $directory = public_path('images/homepage/success-stories');
        if (! is_dir($directory)) {
            mkdir($directory, 0775, true);
        }

        $file = $request->file('image');
        $extension = strtolower($file->getClientOriginalExtension() ?: 'jpg');
        $filename = 'story_'.time().'_'.bin2hex(random_bytes(3)).'.'.$extension;
        $file->move($directory, $filename);

        return 'images/homepage/success-stories/'.$filename;
    }
}
