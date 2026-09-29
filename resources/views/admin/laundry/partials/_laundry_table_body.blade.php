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
            {{-- What this laundry receives from the washing — its own share, or
                 the general one it falls back to, or neither. The three are said
                 in words rather than as a bare percentage, because an operator
                 reading «90%» cannot tell whether somebody agreed it with this
                 laundry or it is inherited, and «not set» is a laundry whose
                 settlements are waiting on somebody. --}}
            @php
                $share = $laundry->commissionRules
                    ->where('status', 'active')
                    ->where('basis', \App\Modules\Payment\Enums\CommissionBasis::Percent)
                    ->sortBy('id')
                    ->first();
                $generalShare = $share ? null : app(\App\Modules\Payment\Services\SettlementService::class)->defaultShareRate();
            @endphp
            @if ($share)
                <span class="row-main">{{ $share->explain() }}</span>
                <span class="row-sub">{{ getLocalizedValueDashboard($share, 'name') }}</span>
            @elseif ($generalShare !== null)
                <span class="row-main">{{ rtrim(rtrim(number_format($generalShare, 2), '0'), '.') }}%</span>
                <span class="row-sub">{{ __('General share') }}</span>
            @else
                <span class="row-main text-danger">{{ __('Not set') }}</span>
                <span class="row-sub">{{ __('Settlements wait') }}</span>
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
