@props([
    'categories' => null,
    'namePrefix' => null,
    'categoryName' => 'education_category',
    'degreeName' => 'education_degree',
    'selectedCategory' => null,
    'selectedDegree' => null,
    'mode' => 'dependent',
    'labelCategory' => 'Education Category',
    'labelDegree' => 'Education Degree',
    'required' => false,
])
@php
    use App\Models\EducationCategory;

    $categories = $categories ?? EducationCategory::where('is_active', true)->with(['degrees' => fn ($q) => $q->orderBy('sort_order')])->orderBy('sort_order')->get();
    $namePrefix = $namePrefix ?? '';
    $n = fn($base) => $namePrefix ? $namePrefix.'['.$base.']' : $base;
    $inputCls = 'w-full rounded border border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-100 px-3 py-2';
    $labelCls = 'block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1';
    $wrapperId = 'edu-hier-' . bin2hex(random_bytes(4));
@endphp
<div class="education-hierarchy-select space-y-4" id="{{ $wrapperId }}">
    @if($mode === 'dependent')
        <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
            <div>
                <label class="{{ $labelCls }}">{{ $labelCategory }}</label>
                <select name="{{ $n($categoryName) }}" class="{{ $inputCls }} education-category-select">
                    <option value="">Select category</option>
                    @foreach($categories as $cat)
                        <option value="{{ $cat->name }}" {{ (string)$selectedCategory === (string)$cat->name ? 'selected' : '' }}>{{ $cat->name }}</option>
                    @endforeach
                </select>
            </div>
            <div class="education-degree-wrap">
                <label class="{{ $labelCls }}">{{ $labelDegree }}</label>
                <select name="{{ $n($degreeName) }}" class="{{ $inputCls }} education-degree-select">
                    <option value="">Select degree</option>
                    @foreach($categories as $cat)
                        @foreach($cat->degrees as $deg)
                            <option value="{{ $deg->code }}" data-category="{{ $cat->name }}" data-fullform="{{ e($deg->full_form ?? '') }}" {{ (string)$selectedDegree === (string)$deg->code ? 'selected' : '' }}>{{ $deg->title }}</option>
                        @endforeach
                    @endforeach
                </select>
            </div>
        </div>
        <p class="text-xs text-gray-500 dark:text-gray-400 mt-1 education-degree-fullform" style="display:none;"></p>
        <script>
        (function() {
            var wrap = document.getElementById('{{ $wrapperId }}');
            if (!wrap) return;
            var catSelect = wrap.querySelector('.education-category-select');
            var degSelect = wrap.querySelector('.education-degree-select');
            var fullformP = wrap.querySelector('.education-degree-fullform');
            var allDegreeOptions = degSelect ? Array.from(degSelect.querySelectorAll('option[data-category]')) : [];
            function filterDegrees(clearSelection) {
                var cat = catSelect ? catSelect.value : '';
                allDegreeOptions.forEach(function(opt) {
                    opt.style.display = (cat === '' || opt.getAttribute('data-category') === cat) ? '' : 'none';
                });
                if (degSelect && clearSelection) {
                    degSelect.value = '';
                }
                if (fullformP) fullformP.style.display = 'none';
            }
            function showFullForm() {
                var sel = degSelect ? degSelect.options[degSelect.selectedIndex] : null;
                if (fullformP && sel && sel.getAttribute('data-fullform')) {
                    fullformP.textContent = 'Full form: ' + sel.getAttribute('data-fullform');
                    fullformP.style.display = 'block';
                } else if (fullformP) fullformP.style.display = 'none';
            }
            if (catSelect) catSelect.addEventListener('change', function() { filterDegrees(true); });
            if (degSelect) degSelect.addEventListener('change', showFullForm);
            filterDegrees(false);
            showFullForm();
        })();
        </script>
    @else
        <div>
            <label class="{{ $labelCls }}">{{ $labelDegree }} (grouped)</label>
            <select name="{{ $n($degreeName) }}" class="{{ $inputCls }}">
                <option value="">Select degree</option>
                @foreach($categories as $cat)
                    <optgroup label="{{ $cat->name }}">
                        @foreach($cat->degrees as $deg)
                            <option value="{{ $deg->code }}" {{ (string)$selectedDegree === (string)$deg->code ? 'selected' : '' }}>{{ $deg->title }}</option>
                        @endforeach
                    </optgroup>
                @endforeach
            </select>
        </div>
    @endif
</div>
