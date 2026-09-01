@forelse ($faqs as $faq)
    <tr>
        <td>{{ $faq->order }}</td>
        <td style="max-width: 280px;">
            <strong>{{ getLocalizedValueDashboard($faq, 'question') ?: '—' }}</strong>
        </td>
        <td style="max-width: 380px;">
            {{ \Illuminate\Support\Str::limit(getLocalizedValueDashboard($faq, 'answer') ?: '—', 140) }}
        </td>
        <td>
            {{-- The driver app shows the same section as the customer app and the
                 answers are not the same, so the audience is worth a column. --}}
            @if ($faq->audience === 'customer')
                <span class="badge bg-info">{{ __('Customers') }}</span>
            @elseif ($faq->audience === 'driver')
                <span class="badge bg-primary">{{ __('Drivers') }}</span>
            @else
                <span class="badge bg-light">{{ __('Both apps') }}</span>
            @endif
        </td>
        <td>
            <x-status-toggle-button :id="$faq->id" :status="$faq->status"
                endpoint="{{ route('admin.faq.toggleStatus', $faq->id) }}" permission="faq.toggle" />
        </td>
        <td class="text-center">
            @include('admin.faq.shared.controlBut', ['row' => $faq])
        </td>
    </tr>
@empty
    <tr>
        <td colspan="6" class="text-center">{{ __('No data found') }}</td>
    </tr>
@endforelse
