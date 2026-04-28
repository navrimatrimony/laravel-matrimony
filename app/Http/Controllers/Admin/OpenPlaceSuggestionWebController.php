<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use Illuminate\View\View;

class OpenPlaceSuggestionWebController extends Controller
{
    public function index(): View
    {
        return view('admin.open_place_suggestions.index');
    }
}
