<script type="text/javascript" src="{{ asset('assets/js/apexcharts.js') }}"></script>
<script type="text/javascript" src="{{ asset('assets/js/jquery.min.js') }}"></script>
<script type="text/javascript" src="{{ asset('assets/js/popper.min.js') }}"></script>
<script type="text/javascript" src="{{ asset('assets/js/bootstrap.min.js') }}"></script>
<script type="text/javascript" src="{{ asset('assets/js/app.js') }}"></script>

{{-- Alpine.js (lightweight interactive components only; does not replace jQuery/Bootstrap) --}}
<script type="text/javascript" src="{{ asset('assets/js/vendor/alpinejs/alpine.min.js') }}" defer></script>

{{-- Firebasejs 8.10.0 --}}
{{-- <script type="text/javascript" src="{{ asset('assets/js/firebase-app.js')}}"></script> --}}
{{-- <script type="text/javascript" src="{{ asset('assets/js/firebase-messaging.js')}}"></script> --}}


{{-- Sweet Alert --}}
<script type="text/javascript" src="{{ asset('assets/extensions/sweetalert2/sweetalert2.min.js') }}"></script>

{{-- Tiny MCE --}}
<script type="text/javascript" src="{{ asset('assets/extensions/tinymce/tinymce.min.js') }}"></script>

{{-- Jquery Vector Map --}}
<script type="text/javascript" src="{{ asset('assets/extensions/jquery-vector-map/jquery-jvectormap-2.0.5.min.js') }}">
</script>
<script type="text/javascript" src="{{ asset('assets/extensions/jquery-vector-map/jquery-jvectormap-asia-merc.js') }}">
</script>
<script type="text/javascript"
    src="{{ asset('assets/extensions/jquery-vector-map/jquery-jvectormap-world-mill-en.js') }}"></script>
<script type="text/javascript"
    src="{{ asset('assets/extensions/jquery-vector-map/jquery-jvectormap-world-mill.js') }}"></script>

{{-- Toastify --}}
<script type="text/javascript" src="{{ asset('assets/extensions/toastify-js/toastify.js') }}"></script>

{{-- Parsley --}}
<script type="text/javascript" src="{{ asset('assets/js/parsley.min.js') }}"></script>
<script type="text/javascript" src="{{ asset('assets/js/pages/parsley.js') }}"></script>


{{-- Magnific Popup --}}
<script type="text/javascript" src="{{ asset('assets/extensions/magnific-popup/jquery.magnific-popup.min.js') }}">
</script>

{{-- Select2 --}}
<script type="text/javascript" src="{{ asset('assets/extensions/select2/select2.min.js') }}"></script>

{{-- Jquery UI --}}
<script type="text/javascript" src="{{ asset('assets/extensions/jquery-ui/jquery-ui.min.js') }}"></script>

{{-- Clipboard JS --}}
<script type="text/javascript" src="{{ asset('assets/js/clipboard.min.js') }}"></script>

{{-- Filepond --}}
<script type="text/javascript" src="{{ asset('assets/extensions/filepond/filepond.min.js') }}"></script>
<script type="text/javascript" src="{{ asset('assets/extensions/filepond/filepond.jquery.js') }}"></script>
<script type="text/javascript" src="{{ asset('assets/extensions/filepond/filepond-plugin-image-preview.min.js') }}">
</script>
<script type="text/javascript" src="{{ asset('assets/extensions/filepond/filepond-plugin-pdf-preview.min.js') }}">
</script>
<script type="text/javascript"
    src="{{ asset('assets/extensions/filepond/filepond-plugin-file-validate-size.min.js') }}"></script>
<script type="text/javascript"
    src="{{ asset('assets/extensions/filepond/filepond-plugin-file-validate-type.min.js') }}"></script>
<script type="text/javascript"
    src="{{ asset('assets/extensions/filepond/filepond-plugin-image-validate-size.min.js') }}"></script>

{{-- JS Tree --}}
<script src="{{ asset('assets/extensions/jstree/jstree.min.js') }}"></script>


{{-- Custom JS --}}
<script type="text/javascript" src="{{ asset('assets/js/custom/common.js') }}"></script>
<script type="text/javascript" src="{{ asset('assets/js/custom/custom.js') }}"></script>

{{--
    The panel's own dropdowns.

    A native <select>'s open list is drawn by the operating system and takes no
    CSS, which is why it never matched anything around it. select2 is already
    bundled and already loaded — it was initialised only on a `.select2` class
    that none of the 55 selects in the admin forms carried, so the library
    shipped unused while every dropdown stayed a system menu.

    Deliberately narrow: `.form-select` only, skipping anything already turned
    into a select2 by custom.js or function.js, and skipping multiple selects,
    which are a different control.
--}}
<script>
    $(function () {
        if (typeof $.fn.select2 !== 'function') {
            return;
        }

        $('select.form-select').each(function () {
            const $el = $(this);

            if ($el.data('select2') || $el.prop('multiple') || $el.hasClass('select2')) {
                return;
            }

            $el.select2({
                theme: 'bootstrap-5',
                width: '100%',
                // A search box earns its place on a list of cities, not on
                // Active/Inactive. Below ten options it is only in the way.
                minimumResultsForSearch: 10,
                // Inside a modal the dropdown has to be parented to the modal
                // or it is clipped by the overlay and rendered behind it.
                dropdownParent: $el.closest('.modal').length
                    ? $el.closest('.modal')
                    : $(document.body),
                placeholder: $el.find('option[value=""]').first().text() || null,
                // The empty first option of these forms is the placeholder, so
                // the field must be clearable back to it.
                allowClear: $el.prop('required') !== true && $el.find('option[value=""]').length > 0,
            });
        });

        // A locked field is read-only on the detail screens; select2 has to be
        // told separately or it stays interactive over a disabled input.
        $('select.form-select:disabled').each(function () {
            $(this).prop('disabled', true).trigger('change.select2');
        });
    });
</script>
<script type="text/javascript" src="{{ asset('assets/js/custom/function.js') }}"></script>

{{-- Notifications --}}
<script type="text/javascript">
    window.csrfToken = "{{ csrf_token() }}";
    window.notificationUnreadUrl = "{{ route('admin.myNotifications.unread') }}";
    window.notificationReadUrlTemplate = "{{ route('admin.myNotifications.read', ['id' => '__ID__']) }}";
</script>
<script type="text/javascript" src="{{ asset('assets/js/custom/notifications.js') }}"></script>
<script type="text/javascript" src="{{ asset('assets/js/custom/bootstrap-table/formatter.js') }}"></script>
<script type="text/javascript" src="{{ asset('assets/js/custom/bootstrap-table/queryParams.js') }}"></script>
<script type="text/javascript" src="{{ asset('assets/js/custom/bootstrap-table/actionEvents.js') }}"></script>


{{-- Bootstrap Table --}}
<script type="text/javascript" src="{{ asset('assets/extensions/bootstrap-table/bootstrap-table.min.js') }}"></script>
<script type="text/javascript"
    src="{{ asset('assets/extensions/bootstrap-table/fixed-columns/bootstrap-table-fixed-columns.min.js') }}"></script>
<script type="text/javascript"
    src="{{ asset('assets/extensions/bootstrap-table/mobile/bootstrap-table-mobile.min.js') }}"></script>
<script type="text/javascript" src="{{ asset('assets/extensions/bootstrap-table/jquery.tablednd.min.js') }}"></script>
<script type="text/javascript" src="{{ asset('assets/extensions/bootstrap-table/bootstrap-table.min.js') }}"></script>
<script type="text/javascript"
    src="{{ asset('assets/extensions/bootstrap-table/bootstrap-table-reorder-rows.min.js') }}"></script>
<script type="text/javascript"
    src="{{ asset('assets/extensions/bootstrap-table/export/bootstrap-table-export.min.js') }}"></script>
<script type="text/javascript" src="{{ asset('assets/extensions/bootstrap-table/export/tableExport.min.js') }}">
</script>
<script type="text/javascript" src="{{ asset('assets/extensions/bootstrap-table/export/jspdf.umd.min.js') }}"></script>
<script type="text/javascript"
    src="{{ asset('assets/extensions/bootstrap-table/mobile/bootstrap-table-mobile.min.js') }}"></script>
<script type="text/javascript"
    src="{{ asset('assets/extensions/bootstrap-table/filter/bootstrap-table-filter-control.min.js') }}"></script>

{{-- Language Translation --}}
{{-- <script src="{{route('common.language.read')}}"></script> --}}

<script src="{{ asset('assets/js/leaflet.js') }}"></script>
<script src="{{ asset('assets/js/map.js') }}"></script>

{{-- <script src="https://cdnjs.cloudflare.com/ajax/libs/jquery/2.2.2/jquery.min.js"></script> --}}
{{-- <script src="https://harvesthq.github.io/chosen/chosen.jquery.js"></script> --}}
{{-- <script src="https://bevacqua.github.io/dragula/dist/dragula.js"></script> --}}
<script type="text/javascript">
    window.baseurl = "{{ URL::to('/') }}/";
    @if (Session::has('success'))
        showSuccessToast("{{ Session::get('success') }}")
    @endif

    {{--    @if (Session::has('errors')) --}}
    {{--    @if (is_array(Session::get('errors'))) --}}
    {{--    @foreach ($errors->all() as $error) --}}

    {{--    showErrorToast("{{ $error }}") --}}
    {{--    @endforeach --}}
    {{--    @else --}}
    {{--    @dd(Session::get('errors')) --}}
    {{--    console.log("{{ Session::get('errors') }}") --}}
    {{--    showErrorToast("{{ Session::get('errors')->message }}") --}}
    {{--    @endif --}}
    {{--    @endif --}}

    @if ($errors->any())
        @foreach ($errors->all() as $error)
            showErrorToast("{!! $error !!}");
        @endforeach
    @endif
    @if (Session::has('error'))
        showErrorToast('{!! Session::get('error') !!}')
    @endif

    document.addEventListener('click', function(e) {
        const button = e.target.closest('.toggle-status');
        if (!button) return;

        const id = button.dataset.id;
        const endpoint = button.dataset.endpoint;
        const currentStatus = button.dataset.status;
        const newStatus = currentStatus === 'active' ? 'inactive' : 'active';

        fetch(endpoint, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-CSRF-TOKEN': '{{ csrf_token() }}',
                },
                body: JSON.stringify({
                    status: newStatus
                })
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    button.dataset.status = data.status;
                    const on = data.status === 'active';
                    // Tone classes, and the label from the button's own data —
                    // this used to write hard-coded English into the cell, so a
                    // toggle on the Arabic panel switched that one cell to
                    // English until the next page load.
                    button.classList.toggle('tone-ok', on);
                    button.classList.toggle('tone-bad', !on);
                    button.textContent = on
                        ? button.dataset.labelActive
                        : button.dataset.labelInactive;
                }
            })
            .catch(error => {
                console.error('Error:', error);
                alert('Something went wrong');
            });
    });


    document.addEventListener('click', function(e) {
        const button = e.target.closest('.toggle-status-car');
        if (!button) return;

        const id = button.dataset.id;
        const endpoint = button.dataset.endpoint;
        const currentStatus = button.dataset.status;

        // حدد الحالة التالية بناءً على الحالة الحالية
        let newStatus;
        if (currentStatus === 'available') {
            newStatus = 'maintenance';
        } else if (currentStatus === 'maintenance') {
            newStatus = 'unavailable';
        } else {
            newStatus = 'available';
        }

        fetch(endpoint, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-CSRF-TOKEN': '{{ csrf_token() }}',
                },
                body: JSON.stringify({
                    status: newStatus
                })
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    button.dataset.status = data.status;

                    // إزالة جميع ألوان البوتون أولاً
                    button.classList.remove('btn-success', 'btn-warning', 'btn-danger');

                    // إضافة اللون المناسب للحالة
                    if (data.status === 'available') {
                        button.classList.add('btn-success');
                        button.innerHTML = '<i class="fa fa-check-circle me-1"></i> Available';
                    } else if (data.status === 'maintenance') {
                        button.classList.add('btn-warning');
                        button.innerHTML = '<i class="fa fa-wrench me-1"></i> Maintenance';
                    } else {
                        button.classList.add('btn-danger');
                        button.innerHTML = '<i class="fa fa-times-circle me-1"></i> Unavailable';
                    }
                }
            })
            .catch(error => {
                console.error('Error:', error);
                alert('Something went wrong');
            });
    });
</script>
<script type="text/javascript">
    function setupAjaxSearch(config) {
        let debounceTimeout;

        const input = $(config.inputSelector);
        const tableBody = $(config.tableBodySelector);
        const paginationWrapper = $(config.paginationWrapperSelector);

        function fetchData(query = '', page = 1) {
            $.ajax({
                url: config.url,
                type: 'GET',
                data: {
                    // `query` is the established parameter name — every search()
                    // action reads it with $request->get('query'). Note it must be
                    // read that way and never as $request->query: Symfony's
                    // Request has a public $query property holding a ParameterBag,
                    // so the property access returns that object, not the term.
                    query: query,
                    page: page
                },
                success: function(response) {
                    tableBody.html(response.table);
                    paginationWrapper.html(response.pagination);
                },
                error: function(xhr) {
                    console.error('Error:', xhr.responseText);
                    // Every list but one is a table, so the table row stays the
                    // default. A list that is not a table — the orders stack —
                    // passes its own markup, because a bare <tr> injected into a
                    // div is dropped by the parser and the failure goes silent.
                    tableBody.html(
                        config.errorHtml ??
                        `<tr><td colspan="${config.colspan}" class="text-center text-danger">Error during search</td></tr>`
                    );
                }
            });
        }

        input.on('keyup', function() {
            clearTimeout(debounceTimeout);
            let query = $(this).val();

            debounceTimeout = setTimeout(() => {
                fetchData(query, 1);
            }, 300);
        });

        $(document).on('click', `${config.paginationWrapperSelector} .pagination a`, function(event) {
            event.preventDefault();
            let pageUrl = $(this).attr('href');
            let page = new URL(pageUrl).searchParams.get("page");
            let query = input.val();
            fetchData(query, page);
        });
    }
</script>

<script>
    document.addEventListener('click', function(e) {
        const btn = e.target.closest('.toggle-status');
        if (!btn) return;

        const endpoint = btn.dataset.endpoint;
        const status = btn.dataset.status;

        // call ajax toggle
    });
</script>
