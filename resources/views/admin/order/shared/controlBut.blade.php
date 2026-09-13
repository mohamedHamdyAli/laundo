{{--
    Orders cannot use `x-action-buttons`: that component draws a View, an Edit
    and a Delete, and this module has no edit route at all. It would throw on
    `route('admin.order.edit')` before it rendered anything.

    `$blocker` is optional. The list leaves it out — asking the full guard once
    per row is five existence queries fifteen times — and passes `$offer` from
    the cheap status half instead. The detail screen has one order in hand, so
    it asks properly and gets a sentence to show.
--}}
@php
    $blocker = $blocker ?? null;
    $offer = $offer ?? ($blocker === null);
    // The detail screen's header has room for a control, not for a sentence.
    $compact = $compact ?? false;
@endphp

@if (canDo('order.delete') && $offer)
    <form action="{{ route('admin.order.delete', $row->id) }}" method="POST" class="d-inline">
        @csrf
        @method('DELETE')
        <button type="submit" class="btn btn-sm action-btn action-delete"
            title="{{ __('Delete') }}" aria-label="{{ __('Delete') }}"
            onclick="return confirm('{{ __('Are you sure you want to delete this record?') }}')">
            <i class="fa fa-trash"></i>
        </button>
    </form>
@elseif (canDo('order.delete') && $blocker !== null && $compact)
    {{-- Still shown, still not a button. An operator who came here to delete
         this order needs to know the screen understood them and refused, and
         the reason is the tooltip rather than the label because the header is
         a row of controls and this one is a sentence. --}}
    <span class="btn-quiet text-muted" style="cursor: not-allowed;" title="{{ $blocker }}">
        <i class="fa fa-lock"></i>{{ __('Delete') }}
    </span>
@elseif (canDo('order.delete') && $blocker !== null)
    {{-- Drawn as text, not as a disabled button. A greyed-out bin says «not
         now» and leaves the operator clicking it; the reason says which rule
         caught this order, which is the only thing that would change it. --}}
    <span class="text-muted small d-inline-flex align-items-center gap-1">
        <i class="fa fa-lock"></i> {{ $blocker }}
    </span>
@endif
