@forelse ($rules as $rule)
    <div class="stack-row {{ $rule->status === 'active' ? '' : 'tone-bad' }}">
        <div>
            <span class="row-lead">
                @if (canDo('driver_bonus_rule.view'))
                    <a href="{{ route('admin.driver_bonus_rule.show', $rule->id) }}">
                        {{ getLocalizedValueDashboard($rule, 'name') }}
                    </a>
                @else
                    {{ getLocalizedValueDashboard($rule, 'name') }}
                @endif
            </span>
            <span class="row-sub">#{{ $rule->id }}</span>
        </div>
        <div>
            {{-- The terms in words. A rule row that shows only a name makes an
                 operator open every one of them to find the right terms. --}}
            <span class="row-main">{{ $rule->explainImmediate() }}</span>
            <span class="row-sub">{{ __('As they work') }}</span>
        </div>
        <div>
            @if ($rule->tiers->isEmpty())
                <span class="row-sub">—</span>
            @else
                <span class="row-main">
                    {{ trans_choice(':count target|:count targets', $rule->tiers->count(), ['count' => $rule->tiers->count()]) }}
                </span>
                <span class="row-sub">
                    {{ __('from') }} {{ $rule->tiers->first()->min_orders }}
                    {{ __('orders') }} · {{ moneyFormat($rule->tiers->first()->amount) }}
                </span>
            @endif
        </div>
        <div>
            @php $gates = $rule->explainGates(); @endphp
            @if ($gates === [])
                <span class="row-sub">{{ __('None') }}</span>
            @else
                @foreach ($gates as $gate)
                    <span class="status-pill tone-neutral">{{ $gate }}</span>
                @endforeach
            @endif
        </div>
        <div>
            {{-- How many people this actually pays. Editing a rule with forty
                 drivers on it is a different act from editing an unused one. --}}
            <span class="row-main">{{ $rule->profiles_count }}</span>
            <span class="row-sub">{{ __('drivers') }}</span>
        </div>
        <div>
            <x-status-toggle-button :id="$rule->id" :status="$rule->status"
                endpoint="{{ route('admin.driver_bonus_rule.toggleStatus', $rule->id) }}"
                permission="driver_bonus_rule.toggle" />
        </div>
        <div class="stack-actions">
            @include('admin.driver_bonus_rule.shared.controlBut', ['row' => $rule])
        </div>
    </div>
@empty
    <div class="stack-empty">{{ __('No data found') }}</div>
@endforelse
