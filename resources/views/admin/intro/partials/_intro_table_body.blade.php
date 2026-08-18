@forelse ($intros as $intro)
    <tr>
        <td>{!! getImageDashboardUrl($intro->image) !!}</td>
        <td>{{ $intro->id ?? 'None' }}</td>
        <td>{{ getLocalizedValueDashboard($intro, 'title') ?? '-' }}</td>
        <td>{{ getLocalizedValueDashboard($intro, 'description') ?? '-' }}</td>
        <td>{{ $intro->order ?? 'None' }}</td>
        <td>
            <x-status-toggle-button :id="$intro->id" :status="$intro->status"
                endpoint="{{ route('admin.intro.toggleStatus', $intro->id) }}" permission="intro.toggle"/>
        </td>
        <td>{{ $intro->created_at->format('Y-m-d H:i') ?? 'None' }}</td>
        <td class="text-center">
            @include('admin.intro.shared.controlBut', [
                'row' => $intro,
            ])
        </td>
    </tr>
@empty
    <tr>
        <td colspan="8" class="text-center">{{ __('No data found') }}</td>
    </tr>
@endforelse
