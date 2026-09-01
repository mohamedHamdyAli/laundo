{{--
    The date picker every report shares.

    A plain GET form: a report you cannot link to or bookmark is a report somebody
    has to re-find every morning.

    The inputs echo the range the report actually used, not the one in the URL.
    Those differ when the dates arrive backwards or span more than a year, and a
    form showing dates the figures below it do not cover is worse than no form.
--}}
<form method="GET" class="d-flex gap-2 align-items-end flex-wrap mb-3">
    <div>
        <label class="form-label mb-1 small">{{ __('From') }}</label>
        <input type="date" name="from" class="form-control form-control-sm"
            value="{{ $range->from->toDateString() }}">
    </div>
    <div>
        <label class="form-label mb-1 small">{{ __('To') }}</label>
        <input type="date" name="to" class="form-control form-control-sm"
            value="{{ $range->to->toDateString() }}">
    </div>
    <button type="submit" class="btn btn-sm btn-primary">{{ __('Apply') }}</button>

    <a href="?from={{ now()->subDays(6)->toDateString() }}&to={{ now()->toDateString() }}"
        class="btn btn-sm btn-outline-secondary">{{ __('Last 7 days') }}</a>
    <a href="?from={{ now()->subDays(29)->toDateString() }}&to={{ now()->toDateString() }}"
        class="btn btn-sm btn-outline-secondary">{{ __('Last 30 days') }}</a>
    <a href="?from={{ now()->startOfMonth()->toDateString() }}&to={{ now()->toDateString() }}"
        class="btn btn-sm btn-outline-secondary">{{ __('This month') }}</a>

    @isset($exportable)
        <a href="{{ route('admin.report.export', $exportable) }}?from={{ $range->from->toDateString() }}&to={{ $range->to->toDateString() }}"
            class="btn btn-sm btn-outline-success ms-auto">
            <i class="fa fa-download"></i> {{ __('Export CSV') }}
        </a>
    @endisset
</form>
