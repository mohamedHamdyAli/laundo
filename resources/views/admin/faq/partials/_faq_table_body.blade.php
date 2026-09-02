@forelse ($faqs as $faq)
    <div class="stack-row">
        <div>
            <span class="row-lead">
                @if (canDo('faq.view'))
                    <a href="{{ route('admin.faq.show', $faq->id) }}">{{ getLocalizedValueDashboard($faq, 'question') ?: '—' }}</a>
                @else
                    {{ getLocalizedValueDashboard($faq, 'question') ?: '—' }}
                @endif
            </span>
            <span class="row-sub">{{ __('Order') }} {{ $faq->order }}</span>
        </div>
        <div>
            <span class="row-sub">{{ \Illuminate\Support\Str::limit(getLocalizedValueDashboard($faq, 'answer') ?: '—', 120) }}</span>
        </div>
        <div>
            {{-- The driver app shows the same section as the customer app and the
                 answers are not the same, so the audience is worth its own field. --}}
            @if ($faq->audience === 'customer')
                <span class="status-pill tone-live">{{ __('Customers') }}</span>
            @elseif ($faq->audience === 'driver')
                <span class="status-pill tone-warn">{{ __('Drivers') }}</span>
            @else
                <span class="status-pill tone-ok">{{ __('Both apps') }}</span>
            @endif
        </div>
        <div>
            <x-status-toggle-button :id="$faq->id" :status="$faq->status"
                endpoint="{{ route('admin.faq.toggleStatus', $faq->id) }}" permission="faq.toggle" />
        </div>
        <div class="stack-actions">
            @include('admin.faq.shared.controlBut', ['row' => $faq])
        </div>
    </div>
@empty
    <div class="stack-empty">{{ __('No data found') }}</div>
@endforelse
