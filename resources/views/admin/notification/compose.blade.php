@extends('layouts.main')

@section('content')
    <div class="card-header align-items-center d-flex">
        <h5 class="card-title mb-0 flex-grow-1">{{ __('Send a notification') }}</h5>
        <a href="{{ route('admin.notification.index') }}" class="btn btn-light btn-sm">{{ __('Back to the log') }}</a>
    </div>

    <div class="row">
        <div class="col-lg-8">
            <div class="card">
                <div class="card-body">

                    {{-- The two things somebody needs to know before pressing send. --}}
                    <p class="text-muted small">
                        {{ __('This reaches people in the app and on their phone. It is not urgent business — a muted phone stays muted, and the message is still waiting in the app when they open it.') }}
                    </p>

                    <form class="row g-3 needs-validation store" action="{{ route('admin.notification.send') }}"
                        method="POST">
                        @csrf
                        @include('layouts.validateMessage.errorMessage')

                        <div class="col-md-4">
                            <label class="form-label" for="audience">{{ __('Audience') }}</label>
                            <select class="form-select" id="audience" name="audience">
                                @foreach ($audiences as $case)
                                    <option value="{{ $case->value }}"
                                        @selected(old('audience', $audience->value) === $case->value)>
                                        {{ __($case->label()) }}
                                    </option>
                                @endforeach
                            </select>
                        </div>

                        <div class="col-md-8">
                            <label class="form-label" for="target">{{ __('Recipient') }}</label>
                            <select class="form-select" id="target" name="target"></select>
                            {{-- The count sits here rather than inside the option text,
                                 because it is the number that decides whether to send. --}}
                            <small class="text-muted" id="reachNote"></small>
                        </div>

                        <div class="col-md-12">
                            <label class="form-label" for="title">{{ __('Title') }}</label>
                            <input type="text" class="form-control" id="title" name="title" maxlength="120"
                                value="{{ old('title') }}"
                                placeholder="{{ __('Read on a lock screen — keep it short') }}">
                        </div>

                        <div class="col-md-12">
                            <label class="form-label" for="body">{{ __('Message') }}</label>
                            <textarea class="form-control" id="body" name="body" rows="4" maxlength="500">{{ old('body') }}</textarea>
                        </div>

                        <div class="form-actions">
                            <button class="btn btn-primary" type="submit" id="sendNotification">{{ __('Send') }}</button>
                        </div>
                    </form>

                </div>
            </div>
        </div>

        <div class="col-lg-4">
            <div class="card">
                <div class="card-body">
                    <h6 class="mb-2">{{ __('Before you send') }}</h6>
                    <ul class="text-muted small mb-0 ps-3">
                        <li class="mb-2">{{ __('There is no undo. A notification that has arrived cannot be taken back.') }}</li>
                        <li class="mb-2">{{ __('Everything sent from here appears in the log, under your name.') }}</li>
                        {{-- «Sending» is not «sent», and the difference is worth
                             saying before somebody presses the button. --}}
                        <li class="mb-0">
                            {{ __('More than :limit people goes to the background queue — the log fills as it goes out, rather than all at once.', ['limit' => $inlineLimit]) }}
                        </li>
                    </ul>
                </div>
            </div>
        </div>
    </div>
@endsection

@push('scripts')
    @php
        $composeLabels = collect($audiences)->mapWithKeys(fn($case) => [
            $case->value => [
                'everyone' => __($case->everyoneLabel()),
                'choose' => __($case->chooseLabel()),
            ],
        ]);
    @endphp
    <script>
        (function () {
            // Cast to objects. A PHP array whose keys happen to run 0,1,2… encodes
            // as a JSON *array*, and then `Object.keys()` would hand back list
            // indexes instead of user ids — the recipient would silently become
            // the wrong person. Ids never start at zero, so this has no effect
            // today; it is here so that staying true is not an accident.
            var TARGETS = @json(collect($targets)->map(fn ($rows) => (object) $rows));
            var LABELS = @json($composeLabels);
            var EVERYONE = @json(\App\Modules\Notification\Requests\ManualNotificationRequest::EVERYONE);
            var REACH = @json(__('This will reach :count people.'));
            var CONFIRM = @json(__('This goes to :count people at once and cannot be undone. Send it?'));

            // `footer_script` turns every `select.form-select` in this panel into a
            // select2, so jQuery is the only thing that can hear one change.
            var $ = window.jQuery;
            var audience = document.getElementById('audience');
            var target = document.getElementById('target');
            var note = document.getElementById('reachNote');
            var button = document.getElementById('sendNotification');

            // What was chosen before a failed validation sent us back here.
            var restore = @json(old('target'));

            function fill() {
                var rows = TARGETS[audience.value] || {};
                var ids = Object.keys(rows);
                var labels = LABELS[audience.value];

                target.innerHTML = '';

                // «Everyone» is an option and never the meaning of an empty field:
                // a blank that quietly means «all customers» is one distracted
                // afternoon away from a blast nobody intended.
                var all = document.createElement('option');
                all.value = EVERYONE;
                all.textContent = labels.everyone + ' (' + ids.length + ')';
                target.appendChild(all);

                ids.forEach(function (id) {
                    var option = document.createElement('option');
                    option.value = id;
                    option.textContent = rows[id];
                    target.appendChild(option);
                });

                if (restore && target.querySelector('[value="' + restore + '"]')) {
                    target.value = restore;
                } else {
                    // One person, not everyone, is the safe thing to land on when
                    // the audience changes underneath you.
                    target.selectedIndex = ids.length ? 1 : 0;
                }

                restore = null;

                // The widget draws from its own copy. Rebuilding the `<option>`
                // elements underneath it changes nothing on screen until it is
                // told to re-read them.
                if ($ && $(target).data('select2')) {
                    $(target).trigger('change.select2');
                }

                describe();
            }

            function reach() {
                var rows = TARGETS[audience.value] || {};

                return target.value === EVERYONE ? Object.keys(rows).length : 1;
            }

            function describe() {
                note.textContent = target.value === EVERYONE ? REACH.replace(':count', reach()) : '';
            }

            /*
             * Bound through jQuery, and that is the whole of it.
             *
             * `footer_script` turns **every** `select.form-select` in the panel
             * into a select2, and select2 announces a change with a jQuery
             * event. `addEventListener('change')` never hears one — so the
             * audience visibly switched to Drivers while `fill()` was never
             * called and the recipient list went on offering customers.
             *
             * jQuery's own `.on('change')` catches both, so this is correct
             * whether or not select2 ever loaded.
             */
            if ($) {
                $(audience).on('change', fill);
                $(target).on('change', describe);
            } else {
                audience.addEventListener('change', fill);
                target.addEventListener('change', describe);
            }

            // Bound to the button's click rather than the form's submit: the
            // background submitter in form-validation.js listens for submit too,
            // and a click runs before either of them without depending on the
            // order the two scripts happened to bind in.
            button.addEventListener('click', function (event) {
                if (target.value !== EVERYONE) {
                    return;
                }

                if (!window.confirm(CONFIRM.replace(':count', reach()))) {
                    event.preventDefault();
                }
            });

            fill();
        })();
    </script>
@endpush
