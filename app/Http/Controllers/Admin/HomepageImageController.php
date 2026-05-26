<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\HomepageSectionImage;
use App\Services\Admin\HomepageImageService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Legacy routes redirect into Homepage settings → Images tab.
 */
class HomepageImageController extends Controller
{
    public function __construct(
        private HomepageImageService $homepageImageService
    ) {
    }

    public function index(): RedirectResponse
    {
        return redirect()->route('admin.homepage-settings.index', ['tab' => 'images']);
    }

    public function store(Request $request): RedirectResponse
    {
        return $this->persistImage($request);
    }

    public function clear(Request $request): RedirectResponse
    {
        $request->validate([
            'section_key' => ['required', 'string', Rule::in(array_keys(HomepageSectionImage::SECTIONS))],
        ]);

        $sectionKey = (string) $request->input('section_key');
        $this->homepageImageService->set($sectionKey, null);

        return redirect()->route('admin.homepage-settings.index', ['tab' => 'images'])
            ->with('success', 'Homepage image cleared for '.HomepageSectionImage::SECTIONS[$sectionKey].'.');
    }

    private function persistImage(Request $request): RedirectResponse
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
        $this->homepageImageService->set($sectionKey, 'images/homepage/'.$filename);

        return redirect()->route('admin.homepage-settings.index', ['tab' => 'images'])
            ->with('success', 'Homepage image updated for '.HomepageSectionImage::SECTIONS[$sectionKey].'.');
    }
}
