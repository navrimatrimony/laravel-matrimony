{{-- Phase-5 SSOT: Horoscope (single row) --}}
<div class="space-y-6">
    <h2 class="text-lg font-semibold text-gray-900 dark:text-gray-100 border-b border-gray-200 dark:border-gray-700 pb-2">Horoscope</h2>
    @php $h = old('horoscope', $profile_horoscope_data ?? new \stdClass()); @endphp
    @if(is_object($h) && isset($h->id))<input type="hidden" name="horoscope[id]" value="{{ $h->id }}">@endif
    <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
        <div class="flex flex-col gap-1">
            <label class="text-sm text-gray-600 dark:text-gray-400">Rashi</label>
            <select name="horoscope[rashi_id]" class="form-select rounded border border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-100 px-3 py-2">
                <option value="">— Select —</option>
                @foreach($rashis ?? [] as $item)
                    <option value="{{ $item->id }}" {{ (string)(is_object($h) ? ($h->rashi_id ?? '') : ($h['rashi_id'] ?? '')) === (string)$item->id ? 'selected' : '' }}>{{ $item->label }}</option>
                @endforeach
            </select>
        </div>
        <div class="flex flex-col gap-1">
            <label class="text-sm text-gray-600 dark:text-gray-400">Nakshatra</label>
            <select name="horoscope[nakshatra_id]" class="form-select rounded border border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-100 px-3 py-2">
                <option value="">— Select —</option>
                @foreach($nakshatras ?? [] as $item)
                    <option value="{{ $item->id }}" {{ (string)(is_object($h) ? ($h->nakshatra_id ?? '') : ($h['nakshatra_id'] ?? '')) === (string)$item->id ? 'selected' : '' }}>{{ $item->label }}</option>
                @endforeach
            </select>
        </div>
        <div class="flex flex-col gap-1">
            <label class="text-sm text-gray-600 dark:text-gray-400">Charan</label>
            <input type="number" name="horoscope[charan]" value="{{ is_object($h) ? ($h->charan ?? '') : ($h['charan'] ?? '') }}" placeholder="Charan" class="rounded border border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-100 px-3 py-2 w-24">
        </div>
        <div class="flex flex-col gap-1">
            <label class="text-sm text-gray-600 dark:text-gray-400">Gan</label>
            <select name="horoscope[gan_id]" class="form-select rounded border border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-100 px-3 py-2">
                <option value="">— Select —</option>
                @foreach($gans ?? [] as $item)
                    <option value="{{ $item->id }}" {{ (string)(is_object($h) ? ($h->gan_id ?? '') : ($h['gan_id'] ?? '')) === (string)$item->id ? 'selected' : '' }}>{{ $item->label }}</option>
                @endforeach
            </select>
        </div>
        <div class="flex flex-col gap-1">
            <label class="text-sm text-gray-600 dark:text-gray-400">Nadi</label>
            <select name="horoscope[nadi_id]" class="form-select rounded border border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-100 px-3 py-2">
                <option value="">— Select —</option>
                @foreach($nadis ?? [] as $item)
                    <option value="{{ $item->id }}" {{ (string)(is_object($h) ? ($h->nadi_id ?? '') : ($h['nadi_id'] ?? '')) === (string)$item->id ? 'selected' : '' }}>{{ $item->label }}</option>
                @endforeach
            </select>
        </div>
        <div class="flex flex-col gap-1">
            <label class="text-sm text-gray-600 dark:text-gray-400">Yoni</label>
            <select name="horoscope[yoni_id]" class="form-select rounded border border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-100 px-3 py-2">
                <option value="">— Select —</option>
                @foreach($yonis ?? [] as $item)
                    <option value="{{ $item->id }}" {{ (string)(is_object($h) ? ($h->yoni_id ?? '') : ($h['yoni_id'] ?? '')) === (string)$item->id ? 'selected' : '' }}>{{ $item->label }}</option>
                @endforeach
            </select>
        </div>
        <div class="flex flex-col gap-1">
            <label class="text-sm text-gray-600 dark:text-gray-400">Mangal Dosh</label>
            <select name="horoscope[mangal_dosh_type_id]" class="form-select rounded border border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-100 px-3 py-2">
                <option value="">— Select —</option>
                @foreach($mangalDoshTypes ?? [] as $item)
                    <option value="{{ $item->id }}" {{ (string)(is_object($h) ? ($h->mangal_dosh_type_id ?? '') : ($h['mangal_dosh_type_id'] ?? '')) === (string)$item->id ? 'selected' : '' }}>{{ $item->label }}</option>
                @endforeach
            </select>
        </div>
        <div class="flex flex-col gap-1">
            <label class="text-sm text-gray-600 dark:text-gray-400">Devak</label>
            <input type="text" name="horoscope[devak]" value="{{ is_object($h) ? ($h->devak ?? '') : ($h['devak'] ?? '') }}" placeholder="Devak" class="rounded border border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-100 px-3 py-2">
        </div>
        <div class="flex flex-col gap-1">
            <label class="text-sm text-gray-600 dark:text-gray-400">Kul</label>
            <input type="text" name="horoscope[kul]" value="{{ is_object($h) ? ($h->kul ?? '') : ($h['kul'] ?? '') }}" placeholder="Kul" class="rounded border border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-100 px-3 py-2">
        </div>
        <div class="flex flex-col gap-1">
            <label class="text-sm text-gray-600 dark:text-gray-400">Gotra</label>
            <input type="text" name="horoscope[gotra]" value="{{ is_object($h) ? ($h->gotra ?? '') : ($h['gotra'] ?? '') }}" placeholder="Gotra" class="rounded border border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-100 px-3 py-2">
        </div>
    </div>
</div>
