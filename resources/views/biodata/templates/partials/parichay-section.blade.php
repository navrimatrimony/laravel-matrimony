<section class="parichay-section">
    <h2 class="parichay-section-title">|| {{ $section['title'] }} ||</h2>

    @foreach (($section['rows'] ?? []) as $row)
        <div class="parichay-row">
            <div class="parichay-label">{{ $row['label'] ?? '' }}</div>
            <div class="parichay-colon">:</div>
            <div class="parichay-value">
                @if (! empty($row['locked']) && trim((string) ($row['value'] ?? '')) === '')
                    {{ __('profile.biodata_export_not_disclosed') }}
                @else
                    {{ $row['value'] ?? '' }}
                @endif
            </div>
        </div>
    @endforeach

    @foreach (($section['groups'] ?? []) as $group)
        <div class="parichay-group">
            @if (! empty($group['heading']))
                <div class="parichay-group-title">{{ $group['heading'] }}</div>
            @endif
            <ul class="parichay-list">
                @foreach (($group['lines'] ?? []) as $line)
                    <li>{{ $line }}</li>
                @endforeach
            </ul>
        </div>
    @endforeach

    @foreach (($section['marriage_blocks'] ?? []) as $block)
        <div class="parichay-group">
            <div class="parichay-group-title">{{ __('profile.biodata_export_marriage_details') }}</div>
            <ul class="parichay-list">
                @foreach ($block as $line)
                    <li>{{ $line }}</li>
                @endforeach
            </ul>
        </div>
    @endforeach
</section>
