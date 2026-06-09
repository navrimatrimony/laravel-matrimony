<?php

namespace App\Http\Controllers\Suchak;

use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\View\View;

class AccountRequestController extends Controller
{
    public function registrationInfo(): View
    {
        return view('suchak.register');
    }

    public function create(): RedirectResponse
    {
        return $this->redirectToSeparateRegistration();
    }

    public function store(): RedirectResponse
    {
        return $this->redirectToSeparateRegistration();
    }

    private function redirectToSeparateRegistration(): RedirectResponse
    {
        return redirect()
            ->route('suchak.register.info')
            ->with('info', 'Suchak registration is separate from regular user accounts.');
    }
}
