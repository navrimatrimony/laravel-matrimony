@extends('layouts.app')

@section('content')
<div class="mx-auto max-w-5xl px-4 py-8">
    <div class="mb-6">
        <a href="{{ route('suchak.home') }}" class="text-sm font-semibold text-red-700 hover:underline dark:text-red-300">Back to Suchak Centre</a>
        <h1 class="mt-2 text-3xl font-bold text-gray-900 dark:text-gray-100">Suchak Registration</h1>
        <p class="mt-3 max-w-3xl text-sm leading-6 text-gray-600 dark:text-gray-300">
            सूचक account regular member account पेक्षा वेगळा आहे. इथे नवीन Suchak account तयार होईल, mobile OTP verify होईल आणि request admin approval साठी जाईल.
        </p>
    </div>

    @if (session('error') || session('info') || session('status'))
        <div class="mb-6 rounded-lg border border-amber-200 bg-amber-50 p-4 text-sm text-amber-900 dark:border-amber-900 dark:bg-amber-950/40 dark:text-amber-100">
            {{ session('error') ?: session('info') ?: session('status') }}
        </div>
    @endif

    @if ($errors->any())
        <div class="mb-6 rounded-lg border border-red-200 bg-red-50 p-4 text-sm text-red-800 dark:border-red-900 dark:bg-red-950/40 dark:text-red-100">
            <p class="font-semibold">Please fix this information:</p>
            <ul class="mt-2 list-disc space-y-1 pl-5">
                @foreach ($errors->all() as $error)
                    <li>{{ $error }}</li>
                @endforeach
            </ul>
        </div>
    @endif

    @if ($authenticatedUser)
        <section class="rounded-lg border border-gray-200 bg-white p-6 shadow-sm dark:border-gray-700 dark:bg-gray-800">
            <h2 class="text-xl font-bold text-gray-900 dark:text-gray-100">Separate account required</h2>
            <p class="mt-3 text-sm leading-6 text-gray-600 dark:text-gray-300">
                तुम्ही सध्या regular member म्हणून login आहात. Existing member account Suchak मध्ये convert करता येत नाही.
                Suchak बनायचे असल्यास logout करून नवीन Suchak registration करा.
            </p>
            <form method="POST" action="{{ route('logout') }}" class="mt-5">
                @csrf
                <button type="submit" class="rounded-md bg-red-600 px-4 py-2 text-sm font-semibold text-white hover:bg-red-700">
                    Logout and register as Suchak
                </button>
            </form>
        </section>
    @else
        <div class="grid gap-6 lg:grid-cols-[2fr_1fr]">
            <section class="rounded-lg border border-gray-200 bg-white p-6 shadow-sm dark:border-gray-700 dark:bg-gray-800">
                <form id="suchak-register-form" method="POST" action="{{ route('suchak.register.store') }}" class="space-y-5">
                    @csrf

                    <div class="grid gap-4 md:grid-cols-2">
                        <div>
                            <label for="suchak_name" class="block text-sm font-semibold text-gray-700 dark:text-gray-300">सूचकाचे नाव</label>
                            <input id="suchak_name" name="suchak_name" value="{{ old('suchak_name') }}" required maxlength="255" autocomplete="name" class="mt-2 w-full rounded-md border-gray-300 text-sm dark:border-gray-600 dark:bg-gray-900 dark:text-gray-100">
                        </div>

                        <div>
                            <label for="office_name" class="block text-sm font-semibold text-gray-700 dark:text-gray-300">Office / Bureau name</label>
                            <input id="office_name" name="office_name" value="{{ old('office_name') }}" maxlength="255" class="mt-2 w-full rounded-md border-gray-300 text-sm dark:border-gray-600 dark:bg-gray-900 dark:text-gray-100">
                        </div>
                    </div>

                    <div>
                        <label for="business_type" class="block text-sm font-semibold text-gray-700 dark:text-gray-300">कामाचा प्रकार</label>
                        <select id="business_type" name="business_type" required class="mt-2 w-full rounded-md border-gray-300 text-sm dark:border-gray-600 dark:bg-gray-900 dark:text-gray-100">
                            @foreach ($businessTypes as $value => $label)
                                <option value="{{ $value }}" @selected(old('business_type', 'individual') === $value)>{{ $label }}</option>
                            @endforeach
                        </select>
                    </div>

                    <div class="grid gap-4 md:grid-cols-2">
                        <div>
                            <label for="mobile_number" class="block text-sm font-semibold text-gray-700 dark:text-gray-300">Mobile number</label>
                            <input id="mobile_number" name="mobile_number" value="{{ old('mobile_number') }}" required maxlength="32" inputmode="numeric" autocomplete="tel" placeholder="10 digit mobile" class="mt-2 w-full rounded-md border-gray-300 text-sm dark:border-gray-600 dark:bg-gray-900 dark:text-gray-100">
                            <p class="mt-1 text-xs text-gray-500 dark:text-gray-400">याच mobile वर OTP येईल.</p>
                        </div>

                        <div>
                            <label for="whatsapp_number" class="block text-sm font-semibold text-gray-700 dark:text-gray-300">WhatsApp number</label>
                            <input id="whatsapp_number" name="whatsapp_number" value="{{ old('whatsapp_number') }}" maxlength="32" inputmode="numeric" autocomplete="tel" placeholder="Optional" class="mt-2 w-full rounded-md border-gray-300 text-sm dark:border-gray-600 dark:bg-gray-900 dark:text-gray-100">
                        </div>
                    </div>

                    <div>
                        <label for="email" class="block text-sm font-semibold text-gray-700 dark:text-gray-300">Email</label>
                        <input id="email" name="email" value="{{ old('email') }}" type="email" maxlength="255" autocomplete="email" placeholder="Optional" class="mt-2 w-full rounded-md border-gray-300 text-sm dark:border-gray-600 dark:bg-gray-900 dark:text-gray-100">
                    </div>

                    <div>
                        <label for="address_line" class="block text-sm font-semibold text-gray-700 dark:text-gray-300">Address</label>
                        <textarea id="address_line" name="address_line" rows="3" maxlength="1000" class="mt-2 w-full rounded-md border-gray-300 text-sm dark:border-gray-600 dark:bg-gray-900 dark:text-gray-100">{{ old('address_line') }}</textarea>
                    </div>

                    <div class="grid gap-4 md:grid-cols-2">
                        <div>
                            <label for="password" class="block text-sm font-semibold text-gray-700 dark:text-gray-300">Password</label>
                            <input id="password" name="password" type="password" required autocomplete="new-password" class="mt-2 w-full rounded-md border-gray-300 text-sm dark:border-gray-600 dark:bg-gray-900 dark:text-gray-100">
                        </div>

                        <div>
                            <label for="password_confirmation" class="block text-sm font-semibold text-gray-700 dark:text-gray-300">Confirm password</label>
                            <input id="password_confirmation" name="password_confirmation" type="password" required autocomplete="new-password" class="mt-2 w-full rounded-md border-gray-300 text-sm dark:border-gray-600 dark:bg-gray-900 dark:text-gray-100">
                        </div>
                    </div>

                    <div class="rounded-md border border-amber-200 bg-amber-50 p-4 text-sm leading-6 text-amber-900 dark:border-amber-900 dark:bg-amber-950/40 dark:text-amber-100">
                        Registration नंतर OTP verify करा. त्यानंतर admin request approve करेपर्यंत customer entry सुरू होणार नाही.
                    </div>

                    <div class="flex flex-col gap-3 border-t border-gray-200 pt-5 dark:border-gray-700 sm:flex-row sm:items-center sm:justify-between">
                        <a href="{{ route('login') }}" class="text-sm font-semibold text-gray-600 hover:text-gray-900 dark:text-gray-300 dark:hover:text-white">Already registered? Login</a>
                        <button type="submit" class="rounded-md bg-red-600 px-5 py-2.5 text-sm font-semibold text-white hover:bg-red-700">
                            Register and send OTP
                        </button>
                    </div>
                </form>
            </section>

            <aside class="space-y-4">
                <section class="rounded-lg border border-gray-200 bg-white p-5 shadow-sm dark:border-gray-700 dark:bg-gray-800">
                    <h2 class="text-lg font-bold text-gray-900 dark:text-gray-100">पुढे काय होईल?</h2>
                    <ol class="mt-4 space-y-3 text-sm leading-6 text-gray-700 dark:text-gray-300">
                        <li>1. Mobile OTP verify.</li>
                        <li>2. Request admin कडे pending.</li>
                        <li>3. Admin approve.</li>
                        <li>4. Dashboard मधून customer biodata entry.</li>
                    </ol>
                </section>

                <section class="rounded-lg border border-gray-200 bg-white p-5 shadow-sm dark:border-gray-700 dark:bg-gray-800">
                    <h2 class="text-lg font-bold text-gray-900 dark:text-gray-100">महत्त्वाचे</h2>
                    <p class="mt-3 text-sm leading-6 text-gray-600 dark:text-gray-300">
                        Regular member account आणि Suchak account वेगळे राहतील. Customer biodata entry approved Suchak account मधूनच होईल.
                    </p>
                </section>
            </aside>
        </div>
    @endif
</div>

<script>
    (function () {
        var form = document.getElementById('suchak-register-form');
        if (!form) return;
        form.addEventListener('submit', function () {
            var button = form.querySelector('button[type="submit"]');
            if (!button || button.dataset.once) return;
            button.dataset.once = '1';
            button.disabled = true;
        });
    })();
</script>
@endsection
