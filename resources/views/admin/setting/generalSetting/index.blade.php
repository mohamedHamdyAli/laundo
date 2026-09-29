@extends('layouts.main')
@section('content')
    <div class="card-header align-items-center d-flex flex-wrap gap-2">
        <h5 class="card-title mb-0 flex-grow-1"> {{ __('Edit General Setting') }}</h5>

        {{-- «Edit Privacy And Terms» exists, and has since before this screen
             did — but nothing anywhere linked to it. `config/menu.php` maps the
             `setting` key to one route, and adding a second sidebar entry means
             a second permission slug for a screen that is governed by
             `setting.update` like this one. So it hangs off here, the way the
             four language editors hang off the language row's own actions. --}}
        @if (canDo('setting.update'))
            <a href="{{ route('admin.generalSetting.viewPrivacyAndTerms') }}" class="btn-quiet">
                <i class="bi bi-file-earmark-text"></i>{{ __('Terms and Privacy Policy') }}
            </a>
        @endif
    </div>
    <div class="row">
        <div class="col-lg-12">
            <div class="card">

                <div class="card-body">
                    <form class="row g-3 needs-validation store"
                        action="{{ route('admin.generalSetting.updateGeneralSetting') }}" method="Post"
                        enctype="multipart/form-data" id="form_with_disabled" novalidate>
                        @csrf
                        @method('PUT')

                        @include('layouts.validateMessage.errorMessage')
                        @include('admin.setting.generalSetting.forms.formInput')

                        <div class="form-actions">
                            <button class="btn btn-primary" type="submit">{{ __('Edit') }}</button>
                        </div>

                    </form>
                </div>
            </div>
        </div>
    </div>
@endsection

@push('scripts')
    <script>
        // Back on the tab somebody was editing after a save reloads the page —
        // landing on «عام» after changing a money setting reads as the change
        // having gone nowhere. Per browser tab only, and nothing if storage is off.
        (function () {
            var key = 'settings-tab';
            var tabs = document.querySelectorAll('.settings-tabs [data-bs-toggle="tab"]');

            tabs.forEach(function (tab) {
                tab.addEventListener('shown.bs.tab', function () {
                    try { sessionStorage.setItem(key, tab.id); } catch (e) {}
                });
            });

            var saved = null;
            try { saved = sessionStorage.getItem(key); } catch (e) {}

            var tab = saved ? document.getElementById(saved) : null;

            if (tab && typeof bootstrap !== 'undefined') {
                bootstrap.Tab.getOrCreateInstance(tab).show();
            }
        })();
    </script>
@endpush
