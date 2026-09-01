{{--
    Plain table-based HTML on purpose. This is an email, not a page: no external
    stylesheet survives the trip, and half the clients that will open it strip
    anything cleverer than an inline style.
--}}
<div style="font-family: Arial, Helvetica, sans-serif; color: #1f1f1f; max-width: 640px;">
    <h2 style="margin: 0 0 4px; font-size: 20px; color: #0f2d52;">{{ $digest['title'] }}</h2>
    <p style="margin: 0 0 20px; color: #424655; font-size: 13px;">{{ $digest['range']->label() }}</p>

    <table cellpadding="8" cellspacing="0" border="0" width="100%"
        style="border-collapse: collapse; margin-bottom: 24px;">
        @foreach ($digest['headline'] as $label => $value)
            <tr style="border-bottom: 1px solid #e0e3e5;">
                <td style="color: #424655; font-size: 13px;">{{ $label }}</td>
                <td style="text-align: right; font-weight: bold; font-size: 15px;">{{ $value }}</td>
            </tr>
        @endforeach
    </table>

    @if ($digest['waiting'] !== [])
        {{-- Above the breakdown, because it is the only part of this email that
             asks somebody to do something. --}}
        <h3 style="font-size: 15px; margin: 0 0 8px; color: #b45309;">{{ __('Waiting for a person') }}</h3>
        <table cellpadding="6" cellspacing="0" border="0" width="100%"
            style="border-collapse: collapse; margin-bottom: 24px; background: #fffbeb;">
            @foreach ($digest['waiting'] as $row)
                <tr>
                    <td style="font-size: 13px;">{{ $row['label'] }}</td>
                    <td style="text-align: right; font-weight: bold;">{{ $row['count'] }}</td>
                </tr>
            @endforeach
        </table>
    @endif

    @if ($digest['rows'] !== [])
        <h3 style="font-size: 15px; margin: 0 0 8px;">{{ $digest['rows_title'] }}</h3>
        <table cellpadding="6" cellspacing="0" border="0" width="100%" style="border-collapse: collapse;">
            <tr style="background: #f7fafc;">
                @foreach ($digest['rows_headers'] as $header)
                    <th style="text-align: left; font-size: 12px; color: #424655;">{{ $header }}</th>
                @endforeach
            </tr>
            @foreach ($digest['rows'] as $row)
                <tr style="border-bottom: 1px solid #e0e3e5;">
                    <td style="font-size: 13px;">{{ $row['label'] }}</td>
                    <td style="font-size: 13px;">{{ $row['orders'] }}</td>
                    <td style="font-size: 13px;">{{ moneyFormat($row['revenue']) }}</td>
                </tr>
            @endforeach
        </table>
    @endif

    <p style="margin-top: 28px; font-size: 12px; color: #424655;">
        {{ __('The same figures are attached as a spreadsheet.') }}
    </p>
</div>
