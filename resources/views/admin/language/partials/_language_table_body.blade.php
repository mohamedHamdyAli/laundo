@forelse ($languages as $language)
    <div class="stack-row">
        <div>
            <span class="row-thumb">{!! getImageDashboardUrl($language->icon) !!}</span>
        </div>
        <div>
            <span class="row-lead">
                @if (canDo('language.view'))
                    <a href="{{ route('admin.language.show', $language->id) }}">{{ $language->name }}</a>
                @else
                    {{ $language->name }}
                @endif
            </span>
            {{-- The endonym leads and the English name qualifies it: the list is
                 read by somebody looking for «العربية», not for «Arabic». --}}
            <span class="row-sub">{{ $language->name_en }}</span>
        </div>
        <div>
            <span class="row-main">{{ $language->code ?? '-' }}</span>
            <span class="row-sub">{{ $language->country_code ?? '-' }}</span>
        </div>
        <div>
            <span class="row-sub">{{ humanDate($language->created_at, 'Y-m-d H:i') }}</span>
        </div>
        <div class="stack-actions">
            @include('admin.language.shared.controlBut', ['row' => $language])
        </div>
    </div>
@empty
    <div class="stack-empty">{{ __('No data found') }}</div>
@endforelse
