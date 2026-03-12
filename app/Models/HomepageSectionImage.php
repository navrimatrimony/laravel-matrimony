<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class HomepageSectionImage extends Model
{
    protected $table = 'homepage_section_images';

    protected $fillable = ['section_key', 'image_path'];

    /**
     * Section keys used on the welcome page.
     */
    public const SECTIONS = [
        'assisted_service' => 'Assisted Service (Relationship Manager)',
        'elite_section' => 'Service for Elites',
        'retail_outlet' => 'Retail Outlet / Store',
        'app_section' => 'App Download (phone mockup)',
        'hero' => 'Hero (main form area image)',
        'success_stories' => 'Success Stories',
    ];

    /**
     * Default fallback paths (under public/) when no upload is set.
     */
    public const DEFAULTS = [
        'assisted_service' => 'images/homepage/assisted-service.png',
        'elite_section' => null,
        'retail_outlet' => null,
        'app_section' => null,
        'hero' => 'images/matrimonial-hero.jpg',
        'success_stories' => null,
    ];
}
