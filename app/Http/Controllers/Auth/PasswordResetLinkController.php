<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;
use Illuminate\View\View;

class PasswordResetLinkController extends Controller
{
    /**
     * Display the password reset link request view.
     */
    public function create(): View
    {
        return view('auth.forgot-password');
    }

    /**
     * Handle an incoming password reset link request.
     *
     * @throws \Illuminate\Validation\ValidationException
     */
    public function store(Request $request): RedirectResponse
    {
        $request->validate([
            'login' => ['nullable', 'string', 'max:191', 'required_without:email'],
            'email' => ['nullable', 'email', 'max:191', 'required_without:login'],
        ]);

        $loginInput = trim((string) ($request->input('login') ?? ''));
        if ($loginInput === '') {
            $loginInput = trim((string) ($request->input('email') ?? ''));
        }

        $resolvedEmail = null;
        $mobileDigits = preg_replace('/\D/', '', $loginInput);
        if (strlen($mobileDigits) === 10) {
            $resolvedEmail = User::query()
                ->where('mobile', $mobileDigits)
                ->value('email');
        } elseif (filter_var($loginInput, FILTER_VALIDATE_EMAIL)) {
            $resolvedEmail = Str::lower($loginInput);
        } else {
            $resolvedEmail = User::query()
                ->whereRaw('LOWER(name) = ?', [Str::lower($loginInput)])
                ->value('email');
        }

        if (! $resolvedEmail) {
            return back()
                ->withInput(['login' => $loginInput])
                ->withErrors(['login' => __('We could not find an account with a reset-enabled email for this login.')]);
        }

        // We will send the password reset link to this user. Once we have attempted
        // to send the link, we will examine the response then see the message we
        // need to show to the user. Finally, we'll send out a proper response.
        $status = Password::sendResetLink(
            ['email' => $resolvedEmail]
        );

        return $status == Password::RESET_LINK_SENT
                    ? back()->with('status', __($status))
                    : back()->withInput(['login' => $loginInput])
                        ->withErrors(['login' => __($status)]);
    }
}
