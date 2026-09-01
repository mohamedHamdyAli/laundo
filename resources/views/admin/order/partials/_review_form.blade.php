{{--
    The laundry's count.

    Pre-filled with what the customer said, because the job is to correct a list,
    not to build one from nothing — and because seeing the original beside the
    field is what makes a discrepancy obvious. A piece that never arrived is
    counted down to zero rather than deleted; the service drops the zeros.

    Every piece the service is priced for is offered, not just the ones the
    customer listed, since «تم العثور على قطعة إضافية أثناء المراجعة» is exactly
    the case this screen exists for.
--}}
@php
    // «تنظيف جاف» carries no catalogue prices — it is quoted after the pieces are
    // inspected, which is what this screen is. For those the price column is an
    // input; for everything else it stays read-only, because a laundry must not
    // be able to overwrite a price the platform sets.
    $quotePriced = $row->service && ! $row->service->isPerItem();
@endphp

<div class="card mb-3 border-primary">
    <div class="card-header bg-primary bg-opacity-10 d-flex justify-content-between align-items-center">
        <h6 class="mb-0">
            <i class="fa fa-clipboard-check"></i> {{ __('Review the pieces') }}
        </h6>
        @if ($row->review_round > 0)
            <span class="badge bg-warning text-dark">
                {{ __('Review round') }} {{ $row->review_round + 1 }}
            </span>
        @endif
    </div>

    <div class="card-body">
        @if ($row->status === \App\Modules\Order\Enums\OrderStatus::ReviewDisputed)
            <div class="alert alert-warning">
                <strong>{{ __('The customer asked for a second count.') }}</strong>
                @php $lastDispute = $row->statusLogs->firstWhere('to_status', 'review_disputed'); @endphp
                @if ($lastDispute?->note)
                    <div class="mt-1">{{ $lastDispute->note }}</div>
                @endif
            </div>
        @endif

        <p class="text-muted small">
            {{ __('Count what actually arrived. The customer sees this against their own count and has to agree to the price before anything is cleaned.') }}
        </p>

        @if ($quotePriced)
            <div class="alert alert-info py-2">
                {{ __('This service is priced on inspection, so enter the price of each piece as well as the count. Leave the count at zero for anything that did not arrive.') }}
            </div>
        @endif

        <form method="POST" action="{{ route('admin.order.review', $row->id) }}">
            @csrf

            <div class="table-responsive">
                <table class="table table-sm align-middle" id="review-table">
                    <thead class="table-light">
                        <tr>
                            <th>{{ __('Item') }}</th>
                            <th style="width: 120px;">{{ __('Customer said') }}</th>
                            <th style="width: 140px;">{{ __('Actual count') }}</th>
                            <th style="width: 110px;">{{ __('Unit Price') }}</th>
                            <th style="width: 120px;" class="text-end">{{ __('Total') }}</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($reviewItems as $index => $entry)
                            <tr>
                                <td>
                                    {{ getLocalizedValueDashboard($entry['item'], 'name') }}
                                    <input type="hidden" name="lines[{{ $index }}][item_id]"
                                        value="{{ $entry['item']->id }}">
                                </td>
                                <td>
                                    @if ($entry['estimated_qty'] > 0)
                                        <span class="badge bg-light text-dark">{{ $entry['estimated_qty'] }}</span>
                                    @else
                                        <span class="text-muted">—</span>
                                    @endif
                                </td>
                                <td>
                                    <input type="number" min="0" max="999"
                                        class="form-control form-control-sm review-qty"
                                        name="lines[{{ $index }}][qty]"
                                        data-price="{{ $entry['price'] }}"
                                        value="{{ old("lines.$index.qty", $entry['final_qty']) }}">
                                </td>
                                <td>
                                    @if ($quotePriced)
                                        <input type="number" min="0" max="100000" step="0.01"
                                            class="form-control form-control-sm review-price"
                                            name="lines[{{ $index }}][unit_price]"
                                            value="{{ old("lines.$index.unit_price", $entry['price']) }}"
                                            placeholder="0.00">
                                    @else
                                        {{ moneyFormat($entry['price']) }}
                                    @endif
                                </td>
                                <td class="text-end review-line-total">
                                    {{ moneyFormat((float) $entry['price'] * $entry['final_qty']) }}
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                    <tfoot class="table-light">
                        <tr>
                            <th colspan="4" class="text-end">{{ __('Pieces subtotal') }}</th>
                            <th class="text-end" id="review-subtotal">—</th>
                        </tr>
                        <tr>
                            <td colspan="4" class="text-end text-muted">
                                {{ __('Delivery fee') }} <small>({{ __('carried over, not re-priced') }})</small>
                            </td>
                            <td class="text-end text-muted">{{ moneyFormat($row->delivery_fee) }}</td>
                        </tr>
                        @if ((float) $row->discount_total > 0)
                            <tr class="text-success">
                                <td colspan="4" class="text-end">{{ __('Discount') }}</td>
                                <td class="text-end">- {{ moneyFormat($row->discount_total) }}</td>
                            </tr>
                        @endif
                        <tr>
                            <th colspan="4" class="text-end">{{ __('Final total') }}</th>
                            <th class="text-end fs-5" id="review-total">—</th>
                        </tr>
                        <tr>
                            <td colspan="4" class="text-end text-muted">
                                {{ __('Customer agreed to') }} {{ moneyFormat($row->estimated_total) }}
                            </td>
                            <td class="text-end" id="review-difference"></td>
                        </tr>
                    </tfoot>
                </table>
            </div>

            <div class="mb-3">
                <label class="form-label">{{ __('Note to the customer') }}</label>
                <textarea name="note" class="form-control" rows="2"
                    placeholder="{{ __('e.g. An extra piece was found during the review.') }}">{{ old('note', $row->review_note) }}</textarea>
                <small class="text-muted">
                    {{ __('Shown to the customer beside the comparison. Explain any difference here.') }}
                </small>
            </div>

            <button type="submit" class="btn btn-primary">
                <i class="fa fa-paper-plane"></i> {{ __('Send the final price to the customer') }}
            </button>
        </form>
    </div>
</div>

@push('scripts')
    <script>
        // The total has to move as the counts do — a person adjusting nine rows
        // should not have to submit to find out what they have just charged.
        $(function () {
            const deliveryFee = {{ (float) $row->delivery_fee }};
            const discount = {{ (float) $row->discount_total }};
            const estimated = {{ (float) $row->estimated_total }};

            function money(value) {
                return value.toFixed(2);
            }

            function recalculate() {
                let subtotal = 0;

                $('.review-qty').each(function () {
                    const row = $(this).closest('tr');
                    const qty = parseInt($(this).val(), 10) || 0;
                    // On a quoted service the price is typed rather than looked
                    // up, so the running total has to read the box the person is
                    // filling in — not the empty data attribute beside it.
                    const typed = row.find('.review-price');
                    const price = typed.length
                        ? (parseFloat(typed.val()) || 0)
                        : (parseFloat($(this).data('price')) || 0);
                    const line = qty * price;

                    subtotal += line;
                    row.find('.review-line-total').text(money(line));
                });

                const total = subtotal + deliveryFee - discount;

                $('#review-subtotal').text(money(subtotal));
                $('#review-total').text(money(total));

                const difference = total - estimated;
                const cell = $('#review-difference');

                if (Math.abs(difference) < 0.005) {
                    cell.text('{{ __('same') }}').attr('class', 'text-end text-muted');
                } else if (difference > 0) {
                    cell.text('+' + money(difference)).attr('class', 'text-end text-danger fw-bold');
                } else {
                    cell.text(money(difference)).attr('class', 'text-end text-success fw-bold');
                }
            }

            $(document).on('input change', '.review-qty, .review-price', recalculate);
            recalculate();
        });
    </script>
@endpush
