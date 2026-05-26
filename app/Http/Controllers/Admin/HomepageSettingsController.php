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

        $request->validate([
            'hero_badge_mr' => ['nullable', 'string', 'max:180'],
            'hero_badge_en' => ['nullable', 'string', 'max:180'],
            'hero_title_mr' => ['required', 'string', 'max:220'],
            'hero_title_en' => ['required', 'string', 'max:220'],
            'hero_subtitle_mr' => ['nullable', 'string', 'max:500'],
            'hero_subtitle_en' => ['nullable', 'string', 'max:500'],
            'primary_cta_mr' => ['required', 'string', 'max:80'],
            'primary_cta_en' => ['required', 'string', 'max:80'],
            'secondary_cta_mr' => ['required', 'string', 'max:80'],
            'secondary_cta_en' => ['required', 'string', 'max:80'],
            'assisted_title_mr' => ['nullable', 'string', 'max:160'],
            'assisted_title_en' => ['nullable', 'string', 'max:160'],
            'assisted_body_mr' => ['nullable', 'string', 'max:700'],
            'assisted_body_en' => ['nullable', 'string', 'max:700'],
            'success_title_mr' => ['nullable', 'string', 'max:160'],
            'success_title_en' => ['nullable', 'string', 'max:160'],
            'success_intro_mr' => ['nullable', 'string', 'max:500'],
            'success_intro_en' => ['nullable', 'string', 'max:500'],
            'final_cta_title_mr' => ['nullable', 'string', 'max:180'],
            'final_cta_title_en' => ['nullable', 'string', 'max:180'],
            'final_cta_body_mr' => ['nullable', 'string', 'max:500'],
            'final_cta_body_en' => ['nullable', 'string', 'max:500'],
            'app_title_mr' => ['nullable', 'string', 'max:160'],
            'app_title_en' => ['nullable', 'string', 'max:160'],
            'app_body_mr' => ['nullable', 'string', 'max:500'],
            'app_body_en' => ['nullable', 'string', 'max:500'],
            'app_android_url' => ['nullable', 'url', 'max:500'],
            'app_ios_url' => ['nullable', 'url', 'max:500'],
            'hero_search_age_control' => ['required', Rule::in(['inputs', 'slider'])],
            'hero_search_community_mode' => ['required', Rule::in(['none', 'caste', 'religion_caste'])],
            'hero_search_location_mode' => ['required', Rule::in(['none', 'state', 'state_district'])],
            'section_enabled' => ['array'],
            'section_enabled.*' => ['string', Rule::in($sectionKeys)],
            'section_sort_order' => ['array'],
            'section_sort_order.*' => ['nullable', 'integer', 'min:1', 'max:999'],
            'search_fields' => ['array'],
            'search_fields.*' => ['string', Rule::in($searchFieldKeys)],
        ]);

        $current = $this->homepageContent->settings();
        $preserveKeys = array_merge(['sections', 'search_fields'], $this->homepageContent->storiesDisplayKeys());

        $settings = [];
        foreach (array_keys($defaults) as $key) {
            if (in_array($key, $preserveKeys, true)) {
                continue;
            }
            $settings[$key] = $request->input($key, $defaults[$key]);
        }

        foreach ($this->homepageContent->storiesDisplayKeys() as $key) {
            $settings[$key] = $current[$key] ?? $defaults[$key];
        }

        $settings['app_show_android'] = $request->boolean('app_show_android');
        $settings['app_show_ios'] = $request->boolean('app_show_ios');

        $enabledSections = collect($request->input('section_enabled', []))->map(fn ($v) => (string) $v)->all();
        foreach ($sectionKeys as $key) {
            $settings['sections'][$key] = [
                'enabled' => in_array($key, $enabledSections, true),
                'sort_order' => max(1, min(999, (int) $request->input("section_sort_order.{$key}", $defaults['sections'][$key]['sort_order']))),
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
            'Homepage bilingual content, section visibility, ordering, and search fields updated.',
            false
        );

        return redirect()->route('admin.homepage-settings.index')
            ->with('success', 'Homepage settings updated.');
    }

    public function updateStoriesDisplay(Request $request): RedirectResponse
    {
        $request->validate([
            'story_limit' => ['required', 'integer', 'min:1', 'max:24'],
            'success_stories_display' => ['required', Rule::in(['grid', 'slider'])],
            'success_stories_autoplay_seconds' => ['required', 'integer', 'min:2', 'max:30'],
            'success_stories_slides_mobile' => ['required', 'integer', 'min:1', 'max:2'],
            'success_stories_slides_tablet' => ['required', 'integer', 'min:1', 'max:3'],
            'success_stories_slides_desktop' => ['required', 'integer', 'min:1', 'max:4'],
        ]);

        $input = $request->only([
            'story_limit',
            'success_stories_display',
            'success_stories_autoplay_seconds',
            'success_stories_slides_mobile',
            'success_stories_slides_tablet',
            'success_stories_slides_desktop',
        ]);
        $input['success_stories_autoplay'] = $request->boolean('success_stories_autoplay');
        $input['success_stories_show_arrows'] = $request->boolean('success_stories_show_arrows');
        $input['success_stories_show_dots'] = $request->boolean('success_stories_show_dots');
        $input['success_stories_pause_on_hover'] = $request->boolean('success_stories_pause_on_hover');
        $input['success_stories_loop'] = $request->boolean('success_stories_loop');

        $settings = array_merge($this->homepageContent->settings(), $this->homepageContent->normalizeStoriesDisplayInput($input));
        $this->homepageContent->save($settings);

        AuditLogService::log(
            $request->user(),
            'update_homepage_success_stories_display',
            'AdminSetting',
            null,
            'Homepage success stories display and slider settings updated.',
            false
        );

        return redirect()->route('admin.homepage-settings.index', ['tab' => 'stories'])
            ->with('success', 'Success stories display settings saved.');
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
