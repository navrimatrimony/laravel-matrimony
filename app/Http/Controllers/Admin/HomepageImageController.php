<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\HomepageSectionImage;
use App\Services\Admin\HomepageImageService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

class HomepageImageController extends Controller
{
    public function __construct(
        private HomepageImageService $homepageImageService
    ) {
    }

    /**
     * List all homepage sections and current images. Upload form per section.
     */
    public function index()
    {
        $sections = [];
        $paths = $this->homepageImageService->allPaths();
        foreach (HomepageSectionImage::SECTIONS as $key => $label) {
            $sections[] = [
                'key' => $key,
                'label' => $label,
                'current_path' => $paths[$key] ?? null,
                'current_url' => $this->homepageImageService->url($key),
            ];
        }

        return view('admin.homepage-images.index', compact('sections'));
    }

    /**
     * Upload and set image for a section. Stores in public storage (images/homepage/).
     */
    public function store(Request $request)
    {
        $request->validate([
            'section_key' => ['required', 'string', 'in:' . implode(',', array_keys(HomepageSectionImage::SECTIONS))],
            'image' => ['required', 'image', 'max:5120'], // 5MB
        ]);

        $sectionKey = $request->input('section_key');
        $file = $request->file('image');
        $filename = $sectionKey . '_' . time() . '.' . $file->getClientOriginalExtension();
        $path = 'images/homepage/' . $filename;
        $file->move(public_path('images/homepage'), $filename);
        $this->homepageImageService->set($sectionKey, $path);

        return redirect()->route('admin.homepage-images.index')
            ->with('success', __('Homepage image updated for :section.', ['section' => HomepageSectionImage::SECTIONS[$sectionKey]]));
    }

    /**
     * Clear uploaded image for a section (revert to default if any).
     */
    public function clear(Request $request)
    {
        $request->validate(['section_key' => ['required', 'string', 'in:' . implode(',', array_keys(HomepageSectionImage::SECTIONS))]]);
        $sectionKey = $request->input('section_key');
        $this->homepageImageService->set($sectionKey, null);
        return redirect()->route('admin.homepage-images.index')
            ->with('success', __('Homepage image cleared for :section.', ['section' => HomepageSectionImage::SECTIONS[$sectionKey]]));
    }
}
