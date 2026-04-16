@extends('layouts.admin-showcase')

@section('showcase_content')
<div class="bg-white dark:bg-gray-800 shadow rounded-lg p-6 max-w-5xl">
    <h1 class="text-2xl font-bold text-gray-800 dark:text-gray-100 mb-2">Showcase — interest controls</h1>
    <p class="text-gray-500 dark:text-gray-400 text-sm mb-6">
        (1) <strong>Showcase → real</strong> — पाठवणे / वेळ / यादृच्छिक. (2) <strong>Real → showcase inbox</strong> — showcase ला आलेल्या pending interest वर आपोआप accept/reject (सिस्टम user ला login न लागता).
        Keys: <code class="text-xs bg-gray-100 dark:bg-gray-700 px-1 rounded">showcase_interest_*</code>. Scheduler: <code class="text-xs">showcase:respond-incoming-interests</code> + <code class="text-xs">showcase:send-outgoing-interests</code> (१५ मि.).
    </p>

    @if (session('success'))
        <div class="mb-4 rounded-lg bg-emerald-50 text-emerald-800 dark:bg-emerald-950/40 dark:text-emerald-200 px-4 py-3 text-sm font-medium border border-emerald-200/80 dark:border-emerald-800/60">
            {{ session('success') }}
        </div>
    @endif

    <form action="{{ route('admin.showcase-interest-settings.update') }}" method="post" class="space-y-10">
        @csrf

        <section>
            <h2 class="text-lg font-semibold text-gray-900 dark:text-gray-100 mb-3 border-b border-gray-200 dark:border-gray-700 pb-2">Master (showcase → real)</h2>
            <label class="flex items-start gap-3 cursor-pointer">
                <input type="checkbox" name="showcase_interest_rules_enabled" value="1" class="mt-1 rounded border-gray-300"
                    @checked($rulesEnabled) />
                <span>
                    <span class="font-medium text-gray-900 dark:text-gray-100">Enable these rules (showcase → real sends)</span>
                    <span class="block text-sm text-gray-500 dark:text-gray-400">बंद असेल तर खालील direction / वारंवारता लागू होणार नाहीत; इतर प्रणाली (plan quota वगैरे) साधारणच राहतात.</span>
                </span>
            </label>
            <label class="flex items-start gap-3 cursor-pointer mt-4">
                <input type="checkbox" name="showcase_interest_bypass_plan_send_quota_for_showcase_sender" value="1" class="mt-1 rounded border-gray-300"
                    @checked($bypassPlanSendQuotaForShowcaseSender) />
                <span>
                    <span class="font-medium text-gray-900 dark:text-gray-100">Showcase पाठवणाऱ्यासाठी दैनिक plan send quota bypass</span>
                    <span class="block text-sm text-gray-500 dark:text-gray-400">Showcase खात्याच्या daily interest send count ला count करू नका (master toggle पेक्षा स्वतंत्र).</span>
                </span>
            </label>
        </section>

        <section>
            <h2 class="text-lg font-semibold text-gray-900 dark:text-gray-100 mb-3 border-b border-gray-200 dark:border-gray-700 pb-2">Showcase → real (auto send engine)</h2>
            <p class="text-sm text-gray-500 dark:text-gray-400 mb-4">
                Showcase system profiles कडून real सदस्यांकडे pending interest आपोआप create करण्यासाठी scheduler command:
                <code class="text-xs">showcase:send-outgoing-interests</code>.
            </p>
            <label class="flex items-start gap-3 cursor-pointer mb-4">
                <input type="checkbox" name="showcase_interest_outgoing_auto_send_enabled" value="1" class="mt-1 rounded border-gray-300" @checked($outgoingAutoSendEnabled) />
                <span class="font-medium text-gray-900 dark:text-gray-100">Outgoing auto-send चालू</span>
            </label>
            <div class="grid sm:grid-cols-3 gap-4 text-sm">
                <div>
                    <label class="block font-medium text-gray-700 dark:text-gray-300 mb-1">Batch per run (showcase profiles)</label>
                    <input type="number" name="showcase_interest_outgoing_auto_batch_per_run" min="1" max="2000" class="w-full rounded-md border-gray-300 dark:border-gray-600 dark:bg-gray-700 shadow-sm" value="{{ old('showcase_interest_outgoing_auto_batch_per_run', $outgoingAutoBatchPerRun) }}" />
                </div>
                <div>
                    <label class="block font-medium text-gray-700 dark:text-gray-300 mb-1">Max sends per showcase / run</label>
                    <input type="number" name="showcase_interest_outgoing_auto_max_sends_per_showcase_per_run" min="1" max="20" class="w-full rounded-md border-gray-300 dark:border-gray-600 dark:bg-gray-700 shadow-sm" value="{{ old('showcase_interest_outgoing_auto_max_sends_per_showcase_per_run', $outgoingAutoMaxSendsPerShowcasePerRun) }}" />
                </div>
                <div>
                    <label class="block font-medium text-gray-700 dark:text-gray-300 mb-1">Candidate pool / showcase</label>
                    <input type="number" name="showcase_interest_outgoing_auto_candidate_pool" min="10" max="1000" class="w-full rounded-md border-gray-300 dark:border-gray-600 dark:bg-gray-700 shadow-sm" value="{{ old('showcase_interest_outgoing_auto_candidate_pool', $outgoingAutoCandidatePool) }}" />
                </div>
            </div>
        </section>

        <section>
            <h2 class="text-lg font-semibold text-gray-900 dark:text-gray-100 mb-3 border-b border-gray-200 dark:border-gray-700 pb-2">Real → showcase inbox (auto प्रतिसाद)</h2>
            <p class="text-sm text-gray-500 dark:text-gray-400 mb-4">
                खरे सदस्य showcase ला interest पाठवतात — showcase खाती <code class="text-xs">@system.local</code> असल्याने कोणी log in करून Accept दाबत नाही. हे चालू केल्यावर क्रॉन/schedule pending रकमांची accept/reject प्रक्रिया करते (policy मधील direction allow असल्यास).
            </p>
            <label class="flex items-start gap-3 cursor-pointer mb-4">
                <input type="checkbox" name="showcase_interest_incoming_auto_respond_enabled" value="1" class="mt-1 rounded border-gray-300" @checked($incomingAutoRespondEnabled) />
                <span class="font-medium text-gray-900 dark:text-gray-100">Incoming auto-respond चालू</span>
            </label>
            <div class="max-w-xs text-sm">
                <label class="block font-medium text-gray-700 dark:text-gray-300 mb-1">Accept ची संभाव्यता (% — उर्वरित म्हणजे reject)</label>
                <input type="number" name="showcase_interest_incoming_auto_accept_pct" min="0" max="100" class="w-full rounded-md border-gray-300 dark:border-gray-600 dark:bg-gray-700 shadow-sm" value="{{ old('showcase_interest_incoming_auto_accept_pct', $incomingAutoAcceptPct) }}" />
            </div>
        </section>

        <section>
            <h2 class="text-lg font-semibold text-gray-900 dark:text-gray-100 mb-3 border-b border-gray-200 dark:border-gray-700 pb-2">यादृच्छिक % (showcase send only)</h2>
            <p class="text-sm text-gray-500 dark:text-gray-400 mb-4">
                फक्त जेव्हा <strong>interest पाठवणारा sender = showcase</strong> असतो (showcase → real). Real सदस्यांकडून पाठवणारी interest यावर अवलंबून नाही.
                “Scale” सुरू असेल तर खालील वजनांनुसार जुळणार्‍या real प्रोफाइलसाठी effective % वाढू शकते. Default: gates बंद.
            </p>
            <label class="flex items-start gap-3 cursor-pointer mb-4">
                <input type="checkbox" name="showcase_interest_stochastic_gates_enabled" value="1" class="mt-1 rounded border-gray-300" @checked($stochasticGatesEnabled) />
                <span class="font-medium text-gray-900 dark:text-gray-100">Stochastic gates चालू (send वर random check)</span>
            </label>
            <label class="flex items-start gap-3 cursor-pointer mb-6">
                <input type="checkbox" name="showcase_interest_scale_prob_by_match_weight" value="1" class="mt-1 rounded border-gray-300" @checked($scaleProbByMatchWeight) />
                <span>
                    <span class="font-medium text-gray-900 dark:text-gray-100">संभाव्यता — मॅच स्कोअर नुसार scale करा</span>
                    <span class="block text-sm text-gray-500 dark:text-gray-400">Effective % ≈ base % × (match score ÷ वजनांची बेरीज).</span>
                </span>
            </label>
            <div class="max-w-xs text-sm">
                <label class="block font-medium text-gray-700 dark:text-gray-300 mb-1">Send पार पडण्याची संभाव्यता (%)</label>
                <input type="number" name="showcase_interest_prob_send_pct" min="0" max="100" class="w-full rounded-md border-gray-300 dark:border-gray-600 dark:bg-gray-700 shadow-sm" value="{{ old('showcase_interest_prob_send_pct', $probSendPct) }}" />
            </div>
        </section>

        <section>
            <h2 class="text-lg font-semibold text-gray-900 dark:text-gray-100 mb-3 border-b border-gray-200 dark:border-gray-700 pb-2">Real सदस्याशी जुळण्याची वजने (showcase send निवड)</h2>
            <p class="text-sm text-gray-500 dark:text-gray-400 mb-4">Showcase ने real ला interest ठेवत असताना — जास्त जुळणार्‍यांना scale मध्ये जास्त फायदा. Age: दोन्हींच्या वयात फरक &lt;= खालील कमाल वर्षे.</p>
            <div class="grid sm:grid-cols-2 lg:grid-cols-4 gap-4 text-sm">
                <div>
                    <label class="block font-medium text-gray-700 dark:text-gray-300 mb-1">Weight — age (within gap)</label>
                    <input type="number" name="showcase_interest_weight_age" min="0" max="500" class="w-full rounded-md border-gray-300 dark:border-gray-600 dark:bg-gray-700 shadow-sm" value="{{ old('showcase_interest_weight_age', $weightAge) }}" />
                </div>
                <div>
                    <label class="block font-medium text-gray-700 dark:text-gray-300 mb-1">Weight — religion</label>
                    <input type="number" name="showcase_interest_weight_religion" min="0" max="500" class="w-full rounded-md border-gray-300 dark:border-gray-600 dark:bg-gray-700 shadow-sm" value="{{ old('showcase_interest_weight_religion', $weightReligion) }}" />
                </div>
                <div>
                    <label class="block font-medium text-gray-700 dark:text-gray-300 mb-1">Weight — caste</label>
                    <input type="number" name="showcase_interest_weight_caste" min="0" max="500" class="w-full rounded-md border-gray-300 dark:border-gray-600 dark:bg-gray-700 shadow-sm" value="{{ old('showcase_interest_weight_caste', $weightCaste) }}" />
                </div>
                <div>
                    <label class="block font-medium text-gray-700 dark:text-gray-300 mb-1">Weight — native district</label>
                    <input type="number" name="showcase_interest_weight_district" min="0" max="500" class="w-full rounded-md border-gray-300 dark:border-gray-600 dark:bg-gray-700 shadow-sm" value="{{ old('showcase_interest_weight_district', $weightDistrict) }}" />
                </div>
            </div>
            <div class="mt-4 max-w-xs">
                <label class="block font-medium text-gray-700 dark:text-gray-300 mb-1">Age “match” साठी कमाल वर्ष फरक</label>
                <input type="number" name="showcase_interest_age_match_max_year_diff" min="0" max="30" class="w-full rounded-md border-gray-300 dark:border-gray-600 dark:bg-gray-700 shadow-sm" value="{{ old('showcase_interest_age_match_max_year_diff', $ageMatchMaxYearDiff) }}" />
            </div>
        </section>

        <section>
            <h2 class="text-lg font-semibold text-gray-900 dark:text-gray-100 mb-3 border-b border-gray-200 dark:border-gray-700 pb-2">Direction — showcase ते real</h2>
            <div class="space-y-3">
                <label class="flex items-start gap-3 cursor-pointer">
                    <input type="checkbox" name="showcase_interest_allow_showcase_to_real" value="1" class="mt-1 rounded border-gray-300" @checked($allowShowcaseToReal) />
                    <span class="font-medium text-gray-900 dark:text-gray-100">Showcase → real सदस्य ला interest पाठवणे परवानगी</span>
                </label>
                <label class="flex items-start gap-3 cursor-pointer">
                    <input type="checkbox" name="showcase_interest_require_opposite_gender_when_any_showcase" value="1" class="mt-1 rounded border-gray-300" @checked($requireOppositeGenderWhenAnyShowcase) />
                    <span>
                        <span class="font-medium text-gray-900 dark:text-gray-100">जेव्हा showcase पाठवतो तेव्हा विरुद्ध लिंग अपेक्षित (policy मध्ये showcase असलेल्या जोड्यांसाठी)</span>
                        <span class="block text-sm text-gray-500 dark:text-gray-400">gender_id नसेल तर तपासणी टाळली जाते.</span>
                    </span>
                </label>
            </div>
        </section>

        <section>
            <h2 class="text-lg font-semibold text-gray-900 dark:text-gray-100 mb-3 border-b border-gray-200 dark:border-gray-700 pb-2">वारंवारता — showcase sender (real ला पाठवताना)</h2>
            <div class="grid sm:grid-cols-2 gap-4 text-sm">
                <div>
                    <label class="block font-medium text-gray-700 dark:text-gray-300 mb-1">किमान सेकंद दोन sends दरम्यान</label>
                    <input type="number" name="showcase_interest_showcase_sender_min_seconds_between_sends" min="0" max="864000" class="w-full rounded-md border-gray-300 dark:border-gray-600 dark:bg-gray-700 shadow-sm"
                        value="{{ old('showcase_interest_showcase_sender_min_seconds_between_sends', $showcaseSenderMinSecondsBetweenSends) }}" />
                </div>
                <div>
                    <label class="block font-medium text-gray-700 dark:text-gray-300 mb-1">जास्तीत जास्त sends / २४ तास</label>
                    <input type="number" name="showcase_interest_showcase_sender_max_sends_per_24h" min="0" max="99999" class="w-full rounded-md border-gray-300 dark:border-gray-600 dark:bg-gray-700 shadow-sm"
                        value="{{ old('showcase_interest_showcase_sender_max_sends_per_24h', $showcaseSenderMaxSendsPer24h) }}" />
                </div>
                <div>
                    <label class="block font-medium text-gray-700 dark:text-gray-300 mb-1">जास्तीत जास्त sends / ७ दिवस</label>
                    <input type="number" name="showcase_interest_showcase_sender_max_sends_per_7d" min="0" max="999999" class="w-full rounded-md border-gray-300 dark:border-gray-600 dark:bg-gray-700 shadow-sm"
                        value="{{ old('showcase_interest_showcase_sender_max_sends_per_7d', $showcaseSenderMaxSendsPer7d) }}" />
                </div>
                <div class="sm:col-span-2">
                    <label class="block font-medium text-gray-700 dark:text-gray-300 mb-1">२४ तासांत वेगवेगळ्या receivers कमाल (anti–repeat-spam)</label>
                    <input type="number" name="showcase_interest_showcase_sender_max_distinct_receivers_24h" min="0" max="99999" class="w-full max-w-md rounded-md border-gray-300 dark:border-gray-600 dark:bg-gray-700 shadow-sm"
                        value="{{ old('showcase_interest_showcase_sender_max_distinct_receivers_24h', $showcaseSenderMaxDistinctReceivers24h) }}" />
                    <p class="text-xs text-gray-500 mt-1"><strong>0</strong> = बंद. नवीन receiver — जास्तीत जास्त इतके वेगळे real प्रोफाइल / २४ तास.</p>
                </div>
            </div>
        </section>

        <section>
            <h2 class="text-lg font-semibold text-gray-900 dark:text-gray-100 mb-3 border-b border-gray-200 dark:border-gray-700 pb-2">Withdraw — showcase ने पाठवलेली pending interest</h2>
            <label class="flex items-start gap-3 cursor-pointer">
                <input type="checkbox" name="showcase_interest_allow_showcase_sender_withdraw" value="1" class="mt-1 rounded border-gray-300" @checked($allowShowcaseSenderWithdraw) />
                <span class="font-medium text-gray-900 dark:text-gray-100">Showcase sender withdraw करू शकतो (pending)</span>
            </label>
        </section>

        <div class="flex justify-end pt-2">
            <button type="submit" class="inline-flex items-center rounded-lg bg-violet-600 hover:bg-violet-700 text-white font-semibold px-5 py-2.5 shadow-sm">
                Save
            </button>
        </div>
    </form>
</div>
@endsection
