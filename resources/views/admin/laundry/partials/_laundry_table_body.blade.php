@forelse ($laundries as $laundry)
    <div class="stack-row {{ $laundry->status === 'active' ? '' : 'tone-bad' }}">
        <div>
            <span class="row-thumb">{!! getImageDashboardUrl($laundry->logo) !!}</span>
        </div>
        <div>
            <span class="row-lead">
                @if (canDo('laundry.view'))
                    <a href="{{ route('admin.laundry.show', $laundry->id) }}">{{ getLocalizedValueDashboard($laundry, 'name') }}</a>
                @else
                    {{ getLocalizedValueDashboard($laundry, 'name') }}
                @endif
            </span>
            <span class="row-sub">#{{ $laundry->id }}</span>
        </div>
        <div>
            <span class="row-main">{{ $laundry->phone ?? '-' }}</span>
        </div>
        <div>
            {{-- Which city a laundry sits in decides which orders can reach it,
                 so it belongs beside the name rather than three columns away. --}}
            <span class="row-main">{{ $laundry->city ? getLocalizedValueDashboard($laundry->city, 'name') : '-' }}</span>
        </div>
        <div>
            {{-- The effective rate, never a blank. A laundry on the general rate
                 is charged just as surely as one with its own, and a column that
                 shows nothing for the common case teaches an operator that most
                 laundries pay no commission. --}}
            {{-- Never a blank. A laundry on nothing is charged the general
                 rate, which is a fact, and an empty cell would read as «not
                 loaded» rather than «follows the default». --}}
            @php $charges = $laundry->commissionRules->where('status', 'active'); @endphp
            @if ($charges->isEmpty())
                <span class="row-main">
                    {{ rtrim(rtrim(number_format((float) (getSettingValue('Commission_Rate') ?? 0), 2), '0'), '.') }}%
                </span>
                <span class="row-sub">{{ __('General rate') }}</span>
            @else
                {{-- Every charge, not a blended number: «10% + 5 ج» is what the
                     agreement says, and collapsing it to one figure is what made
                     a single column insufficient in the first place. --}}
                <span class="row-main">{{ $charges->map(fn ($c) => $c->explain())->implode(' + ') }}</span>
                <span class="row-sub">
                    {{ trans_choice(':count charge|:count charges', $charges->count(), ['count' => $charges->count()]) }}
                </span>
            @endif
        </div>
        <div>
            <x-status-toggle-button :id="$laundry->id" :status="$laundry->status"
                endpoint="{{ route('admin.laundry.toggleStatus', $laundry->id) }}" permission="laundry.toggle" />
        </div>
        <div class="stack-actions">
            @if (canDo('setting.update'))
                {{-- One modal for the whole list, filled from these attributes.
                     A modal per row would be forty copies of the same markup on
                     a page that already renders forty rows. --}}
                <button type="button" class="btn btn-sm action-btn action-commission js-commission-btn"
                    data-id="{{ $laundry->id }}"
                    data-name="{{ getLocalizedValueDashboard($laundry, 'name') }}"
                    data-rules="{{ $laundry->commissionRules->pluck('id')->implode(',') }}"
                    data-action="{{ route('admin.laundry.commission', $laundry->id) }}"
                    title="{{ __('Commission') }}" aria-label="{{ __('Commission') }}">
                    {{-- Font Awesome, not bootstrap-icons: its three neighbours
                         are `fa`, and two icon fonts side by side in one row do
                         not share a baseline or a stroke weight. --}}
                    <i class="fa fa-percent"></i>
                </button>
            @endif
            @include('admin.laundry.shared.controlBut', ['row' => $laundry])
        </div>
    </div>
@empty
    <div class="stack-empty">{{ __('No data found') }}</div>
@endforelse
