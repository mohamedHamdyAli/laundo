{{--
    «انضم لنا» — the driver recruitment form, as a dialog.

    A dialog rather than a page because the ask is three boxes and the visitor
    is halfway down a marketing page: sending them somewhere else to type a
    name and a number is how a lead becomes a bounce. It posts in the
    background and swaps to a thank-you in place, so the page they were
    reading is still behind it when they close.

    `<dialog>` and not a hand-built overlay: the browser gives the modal
    behaviour, the focus trap, the backdrop and Escape for free, and every one
    of those is a thing a hand-rolled version gets wrong.

    Only two fields are required. A recruitment form that asks a courier for a
    licence number on his phone is a form he closes; everything else is
    collected in the call the number is for.
--}}
<dialog class="driver-form" id="driver-form" aria-labelledby="driver-form-title">
    <form method="dialog" class="driver-form-dismiss">
        <button value="close" aria-label="{{ webText('landing.driver_form.close') }}">
            <x-landing.icon name="close" :size="18" />
        </button>
    </form>

    <div data-driver-ask>
        <h2 class="driver-form-title" id="driver-form-title">{{ webText('landing.driver_form.title') }}</h2>
        <p class="driver-form-body">{{ webText('landing.driver_form.body') }}</p>

        <form class="driver-form-fields" data-driver-form action="{{ route('driver.apply') }}" method="POST"
            data-failed="{{ webText('landing.driver_form.failed') }}">
            @csrf

            <p class="driver-form-alert" data-driver-alert hidden></p>

            <label class="driver-form-field">
                <span>{{ webText('landing.driver_form.name') }}</span>
                <input type="text" name="name" required maxlength="191" autocomplete="name">
                <em class="driver-form-error" data-error-for="name" hidden></em>
            </label>

            <label class="driver-form-field">
                <span>{{ webText('landing.driver_form.phone') }}</span>
                <input type="tel" name="phone" required maxlength="30" autocomplete="tel" dir="ltr"
                    placeholder="01xxxxxxxxx">
                <em class="driver-form-error" data-error-for="phone" hidden></em>
            </label>

            <label class="driver-form-field">
                <span>
                    {{ webText('landing.driver_form.note') }}
                    <small>{{ webText('landing.driver_form.note_hint') }}</small>
                </span>
                <textarea name="note" rows="3" maxlength="1000"></textarea>
                <em class="driver-form-error" data-error-for="note" hidden></em>
            </label>

            <button type="submit" class="driver-form-submit"
                data-sending="{{ webText('landing.driver_form.sending') }}">
                {{ webText('landing.driver_form.submit') }}
            </button>
        </form>
    </div>

    {{-- Swapped in place of the form rather than replacing the dialog: the
         person stays where they were and closes when they are ready. --}}
    <div data-driver-done hidden>
        <span class="driver-form-seal" aria-hidden="true">
            <x-landing.icon name="check" :size="26" />
        </span>
        <h2 class="driver-form-title">{{ webText('landing.driver_form.done_title') }}</h2>
        <p class="driver-form-body">{{ webText('landing.driver_form.done_body') }}</p>

        <form method="dialog">
            <button class="driver-form-submit" value="close">
                {{ webText('landing.driver_form.close') }}
            </button>
        </form>
    </div>
</dialog>
