<?php

namespace App\Http\Requests\Auth;

use App\Models\User;
use Illuminate\Auth\Events\Lockout;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class LoginRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'login' => ['required', 'string', 'max:191'],
            'password' => ['required', 'string'],
        ];
    }

    /**
     * Attempt to authenticate the request's credentials.
     *
     * @throws \Illuminate\Validation\ValidationException
     */
    public function authenticate(): void
    {
        $this->ensureIsNotRateLimited();

        if (! $this->attemptByLoginInput()) {
            RateLimiter::hit($this->throttleKey());

            throw ValidationException::withMessages([
                'login' => trans('auth.failed'),
            ]);
        }

        RateLimiter::clear($this->throttleKey());
    }

    /**
     * Ensure the login request is not rate limited.
     *
     * @throws \Illuminate\Validation\ValidationException
     */
    public function ensureIsNotRateLimited(): void
    {
        if (! RateLimiter::tooManyAttempts($this->throttleKey(), 5)) {
            return;
        }

        event(new Lockout($this));

        $seconds = RateLimiter::availableIn($this->throttleKey());

        throw ValidationException::withMessages([
            'login' => trans('auth.throttle', [
                'seconds' => $seconds,
                'minutes' => ceil($seconds / 60),
            ]),
        ]);
    }

    /**
     * Get the rate limiting throttle key for the request.
     */
    public function throttleKey(): string
    {
        $value = trim((string) $this->string('login'));

        return Str::transliterate(Str::lower($value).'|'.$this->ip());
    }

    protected function attemptByLoginInput(): bool
    {
        $rawLogin = trim((string) $this->input('login', ''));
        $password = (string) $this->input('password');

        if ($rawLogin === '') {
            return false;
        }

        $mobileDigits = preg_replace('/\D/', '', $rawLogin);
        if (strlen($mobileDigits) === 10) {
            return Auth::attempt(['mobile' => $mobileDigits, 'password' => $password], $this->boolean('remember'));
        }

        if (filter_var($rawLogin, FILTER_VALIDATE_EMAIL)) {
            return Auth::attempt(['email' => Str::lower($rawLogin), 'password' => $password], $this->boolean('remember'));
        }

        $normalized = Str::lower($rawLogin);
        $candidates = User::query()
            ->whereRaw('LOWER(name) = ?', [$normalized])
            ->limit(5)
            ->get();

        foreach ($candidates as $candidate) {
            if (Hash::check($password, $candidate->password)) {
                Auth::login($candidate, $this->boolean('remember'));

                return true;
            }
        }

        return false;
    }
}
