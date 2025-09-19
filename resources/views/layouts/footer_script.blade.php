<script type="text/javascript" src="{{ asset('assets/js/apexcharts.js') }}"></script>
<script type="text/javascript" src="{{ asset('assets/js/jquery.min.js') }}"></script>
<script type="text/javascript" src="{{ asset('assets/js/popper.min.js') }}"></script>
<script type="text/javascript" src="{{ asset('assets/js/bootstrap.min.js') }}"></script>
<script type="text/javascript" src="{{ asset('assets/js/app.js') }}"></script>

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
<script type="text/javascript" src="{{ asset('assets/js/custom/function.js') }}"></script>
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
                    button.classList.toggle('btn-success');
                    button.classList.toggle('btn-danger');
                    button.innerHTML = data.status === 'active' ?
                        '<i class="fa fa-check-circle me-1"></i> Active' :
                        '<i class="fa fa-times-circle me-1"></i> Inactive';
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
            console.log("Fetching:", query, page); // Debug
            $.ajax({
                url: config.url,
                type: 'GET',
                data: {
                    query: query,
                    page: page
                },
                success: function(response) {
                    tableBody.html(response.table);
                    paginationWrapper.html(response.pagination);
                },
                error: function(xhr) {
                    console.error('Error:', xhr.responseText);
                    tableBody.html(
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
