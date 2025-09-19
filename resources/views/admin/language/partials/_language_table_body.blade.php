@forelse ($languages as $language)
    <tr>
        <td>{!! getImageDashboardUrl($language->icon) !!}</td>
        <td>{{ $language->id ?? 'None' }}</td>
        <td>{{ $language->name }}</td>
        <td>{{ $language->name_en }}</td>
        <td>{{ $language->code ?? 'None' }}</td>
        <td>{{ $language->country_code ?? 'None' }}</td>
        <td>{{ optional($language->created_at)->format('Y-m-d H:i') ?? 'None' }}</td>
        <td class="text-center">
            @include('admin.language.shared.controlBut', [
                'row' => $language,
            ])
        </td>
    </tr>
@empty
    <tr>
        <td colspan="8" class="text-center">{{ __('No data found') }}</td>
    </tr>
@endforelse
