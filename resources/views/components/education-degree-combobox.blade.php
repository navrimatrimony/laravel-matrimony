@props([
    'degreeFieldName' => 'highest_education',
    'queryFieldName' => 'education_degree_query',
    'selectedCode' => null,
    'queryDisplay' => '',
    'labelDegree' => 'Highest education',
])
@php
    use App\Models\EducationCategory;

    $localeMr = app()->getLocale() === 'mr';

    $categories = EducationCategory::where('is_active', true)
        ->with(['degrees' => fn ($q) => $q->orderBy('sort_order')])
        ->orderBy('sort_order')
        ->get();

    $degreesPayload = $categories->flatMap(function ($cat) use ($localeMr) {
        return $cat->degrees->map(function ($d) use ($cat, $localeMr) {
            $title = ($localeMr && filled($d->title_mr)) ? $d->title_mr : $d->title;
            $fullForm = ($localeMr && filled($d->full_form_mr)) ? $d->full_form_mr : ($d->full_form ?? '');
            $category = ($localeMr && filled($cat->name_mr)) ? $cat->name_mr : $cat->name;

            return [
                'code' => $d->code,
                'title' => $title,
                'full_form' => $fullForm,
                'category' => $category,
                'title_en' => $d->title,
                'code_en' => $d->code,
                'full_form_en' => $d->full_form ?? '',
                'title_mr' => $d->title_mr ?? '',
                'code_mr' => $d->code_mr ?? '',
                'full_form_mr' => $d->full_form_mr ?? '',
            ];
        });
    })->values();

    $wrapperId = 'edc-wrap-' . bin2hex(random_bytes(4));
    $inputCls = 'w-full rounded-xl border border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-100 px-4 py-3 text-base min-h-[48px]';
    $labelCls = 'block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1';
@endphp
<div class="education-degree-combobox relative space-y-1" id="{{ $wrapperId }}">
    <label class="{{ $labelCls }}" for="{{ $wrapperId }}-search">{{ $labelDegree }}</label>
    <input type="hidden" name="{{ $degreeFieldName }}" value="{{ old($degreeFieldName, $selectedCode ?? '') }}" class="edc-hidden-code" autocomplete="off" />
    <input
        id="{{ $wrapperId }}-search"
        type="text"
        name="{{ $queryFieldName }}"
        value="{{ old($queryFieldName, $queryDisplay ?? '') }}"
        class="{{ $inputCls }} edc-search"
        autocomplete="off"
        role="combobox"
        aria-autocomplete="list"
        aria-expanded="false"
        aria-controls="{{ $wrapperId }}-listbox"
        placeholder="{{ __('components.education.combobox_placeholder') }}"
    />
    <ul
        id="{{ $wrapperId }}-listbox"
        class="edc-list absolute left-0 right-0 top-full z-50 mt-1 hidden max-h-56 overflow-y-auto rounded-xl border border-gray-200 bg-white py-1 shadow-lg dark:border-gray-700 dark:bg-gray-900"
        role="listbox"
    ></ul>
    <p class="edc-fullform text-xs text-gray-500 dark:text-gray-400" style="display:none;"></p>
    <p class="text-xs text-gray-500 dark:text-gray-400">{{ __('components.education.combobox_hint') }}</p>
</div>

<script type="application/json" id="{{ $wrapperId }}-degrees">{!! json_encode($degreesPayload, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT) !!}</script>
<script>
(function () {
    var wrap = document.getElementById(@json($wrapperId));
    if (!wrap) return;
    var jsonEl = document.getElementById(@json($wrapperId . '-degrees'));
    var hidden = wrap.querySelector('.edc-hidden-code');
    var search = wrap.querySelector('.edc-search');
    var list = wrap.querySelector('.edc-list');
    var fullformP = wrap.querySelector('.edc-fullform');
    var fullFormPrefix = @json(__('components.education.full_form_prefix'));
    var degrees = [];
    try {
        degrees = JSON.parse(jsonEl.textContent || '[]');
        if (!Array.isArray(degrees)) degrees = [];
    } catch (_e) {}

    var selectedTitle = '';

    function norm(s) {
        return String(s || '').toLowerCase().trim();
    }

    function filterDegrees(q) {
        var qq = norm(q);
        if (qq === '') return degrees.slice(0, 40);
        var out = [];
        for (var i = 0; i < degrees.length; i++) {
            var d = degrees[i];
            var hay = norm(d.title) + ' ' + norm(d.code) + ' ' + norm(d.full_form)
                + ' ' + norm(d.title_en) + ' ' + norm(d.code_en) + ' ' + norm(d.full_form_en)
                + ' ' + norm(d.title_mr) + ' ' + norm(d.code_mr) + ' ' + norm(d.full_form_mr);
            if (hay.indexOf(qq) !== -1) out.push(d);
            if (out.length >= 60) break;
        }
        return out;
    }

    function hideList() {
        if (!list) return;
        list.classList.add('hidden');
        list.innerHTML = '';
        search.setAttribute('aria-expanded', 'false');
    }

    function showFullForm(d) {
        if (!fullformP) return;
        if (d && d.full_form) {
            fullformP.textContent = fullFormPrefix + ' ' + d.full_form;
            fullformP.style.display = 'block';
        } else {
            fullformP.style.display = 'none';
        }
    }

    function renderList(items) {
        if (!list) return;
        list.innerHTML = '';
        if (!items.length) {
            var empty = document.createElement('li');
            empty.className = 'px-3 py-2 text-xs text-gray-500 dark:text-gray-400';
            empty.textContent = @json(__('components.education.combobox_no_results'));
            empty.setAttribute('role', 'presentation');
            list.appendChild(empty);
            list.classList.remove('hidden');
            search.setAttribute('aria-expanded', 'true');
            return;
        }
        items.forEach(function (d) {
            var li = document.createElement('li');
            li.setAttribute('role', 'option');
            li.className = 'cursor-pointer px-3 py-2 text-sm text-gray-900 hover:bg-red-50 dark:text-gray-100 dark:hover:bg-red-950/40';
            li.textContent = d.title + ' (' + d.code + ')';
            li.addEventListener('mousedown', function (e) {
                e.preventDefault();
                selectedTitle = d.title;
                hidden.value = d.code;
                search.value = d.title;
                showFullForm(d);
                hideList();
            });
            list.appendChild(li);
        });
        list.classList.remove('hidden');
        search.setAttribute('aria-expanded', 'true');
    }

    if (hidden && hidden.value && search && search.value) {
        var cur = degrees.find(function (x) { return String(x.code) === String(hidden.value); });
        if (cur) {
            selectedTitle = cur.title;
            showFullForm(cur);
        }
    }

    if (search) {
        search.addEventListener('input', function () {
            var v = search.value;
            if (selectedTitle && v !== selectedTitle) {
                hidden.value = '';
                selectedTitle = '';
                showFullForm(null);
            }
            renderList(filterDegrees(v));
        });
        search.addEventListener('focus', function () {
            renderList(filterDegrees(search.value));
        });
        search.addEventListener('blur', function () {
            setTimeout(hideList, 180);
        });
    }
})();
</script>
