<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Location;
use Illuminate\View\View;

class LocationManageWebController extends Controller
{
    public function index(): View
    {
        return view('admin.locations.index');
    }

    public function edit(Location $location): View
    {
        return view('admin.locations.edit', [
            'location' => $location,
        ]);
    }

    public function merge(): View
    {
        return view('admin.locations.merge');
    }
}
