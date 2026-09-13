@forelse ($rules as $rule)
    <div class="stack-row {{ $rule->status === 'active' ? '' : 'tone-bad' }}">
        <div>
            <span class="row-lead">
                @if (canDo('commission_rule.view'))
                    <a href="{{ route('admin.commission_rule.show', $rule->id) }}">
                        {{ getLocalizedValueDashboard($rule, 'name') }}
                    </a>
                @else
                    {{ getLocalizedValueDashboard($rule, 'name') }}
                @endif
            </span>
            <span class="row-sub">#{{ $rule->id }}</span>
        </div>
        <div>
            {{-- The terms in words, so an operator does not have to open every
                 charge to find the one they meant. --}}
            <span class="row-main">{{ $rule->explain() }}</span>
            <span class="row-sub">{{ __($rule->basis->short()) }}</span>
        </div>
        <div>
            {{-- How many contracts this actually touches. Editing a charge on
                 forty laundries is a different act from editing an unused one. --}}
            <span class="row-main">{{ $rule->laundries_count }}</span>
            <span class="row-sub">{{ __('laundries') }}</span>
        </div>
        <div>
            <x-status-toggle-button :id="$rule->id" :status="$rule->status"
                endpoint="{{ route('admin.commission_rule.toggleStatus', $rule->id) }}"
                permission="commission_rule.toggle" />
        </div>
        <div class="stack-actions">
            @include('admin.commission_rule.shared.controlBut', ['row' => $rule])
        </div>
    </div>
@empty
    <div class="stack-empty">{{ __('No data found') }}</div>
@endforelse
