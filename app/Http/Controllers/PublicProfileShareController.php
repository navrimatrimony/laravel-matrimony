<?php

namespace App\Http\Controllers;

use App\Models\MatrimonyProfile;
use App\Services\Api\MobileProfileDisplayPresenter;
use App\Services\ProfileLifecycleService;
use App\Services\SiteIdentityService;
use Illuminate\Support\Facades\Route;

class PublicProfileShareController extends Controller
{
    public function show(int $id)
    {
        $profile = MatrimonyProfile::query()
            ->with([
                'user',
                'gender',
                'religion',
                'caste',
                'subCaste',
                'location',
                'occupationMaster',
                'occupationCustom',
            ])
            ->findOrFail($id);

        if (! ProfileLifecycleService::isVisibleToOthers($profile)) {
            abort(404);
        }

        $display = app(MobileProfileDisplayPresenter::class)->forProfile($profile);
        $hero = is_array($display['hero'] ?? null) ? $display['hero'] : [];
        $about = is_array($display['about'] ?? null) ? $display['about'] : [];
        $share = is_array($display['share'] ?? null) ? $display['share'] : [];

        $siteIdentity = app(SiteIdentityService::class);
        $siteName = trim((string) ($siteIdentity->get('site_name_en', 'Navri Mile Navryala') ?: 'Navri Mile Navryala'));
        $fallbackImageUrl = $siteIdentity->assetUrl('default_seo_image')
            ?: $siteIdentity->assetUrl('logo_light')
            ?: asset('images/default-profile.png');

        $primaryPhotoUrl = trim((string) ($hero['primary_photo_url'] ?? ''));
        $ogImageUrl = $primaryPhotoUrl !== '' ? $primaryPhotoUrl : $fallbackImageUrl;
        $canonicalUrl = route('profile.share.public', ['id' => $profile->id]);
        $profileUrl = Route::has('matrimony.profile.show')
            ? route('matrimony.profile.show', ['matrimony_profile_id' => $profile->id])
            : url('/');

        $description = $this->descriptionFromHero($hero);
        $title = trim((string) ($share['title'] ?? ''));
        if ($title === '') {
            $name = trim((string) ($hero['name'] ?? 'Profile'));
            $age = $hero['age'] ?? null;
            $title = is_numeric($age)
                ? $name.', '.(int) $age.' - '.$siteName
                : $name.' - '.$siteName;
        }

        return response()
            ->view('public.profile-share', [
                'profile' => $profile,
                'display' => $display,
                'hero' => $hero,
                'about' => $about,
                'share' => $share,
                'siteName' => $siteName,
                'title' => $title,
                'description' => $description,
                'ogImageUrl' => $ogImageUrl,
                'canonicalUrl' => $canonicalUrl,
                'profileUrl' => $profileUrl,
            ])
            ->header('X-Robots-Tag', 'noindex, nofollow');
    }

    /**
     * @param  array<string, mixed>  $hero
     */
    private function descriptionFromHero(array $hero): string
    {
        $parts = [];
        foreach (['height_label', 'community_label', 'occupation_label', 'location_label'] as $key) {
            $value = trim((string) ($hero[$key] ?? ''));
            if ($value !== '') {
                $parts[] = $value;
            }
        }

        return $parts !== []
            ? implode(' • ', array_values(array_unique($parts)))
            : 'View this profile on Navri Mile Navryala.';
    }
}
