{{--
    The language row's actions.

    The standard trio, plus a dropdown for the four file editors.

    Those editors had routes and screens and **nothing linked to them**:
    `x-action-button-lang` was built to carry them and is rendered by nobody
    (`app/View/Components/ActionButtonLang.php` is its only reference). So
    `admin.language.panel`, `.mobile` and `.web` have been reachable only by
    typing the URL — the same shape as a column with no form field, from the
    navigation side.

    Not switched to `x-action-button-lang` wholesale, even though that component
    exists for this: its trio is ungated, and this screen's actions go through
    `canDo()`. Losing the permission checks to gain a dropdown would be a poor
    trade.
--}}
<x-action-buttons :id="$row->id" routePrefix="admin.language" roleKey="language" />

@if (canDo('language.update'))
    <div class="dropdown d-inline">
        <button class="btn btn-sm btn-light border" type="button" data-bs-toggle="dropdown"
            aria-expanded="false" aria-label="{{ __('Edit translation files') }}">
            <i class="fa fa-ellipsis-v"></i>
        </button>

        <ul class="dropdown-menu dropdown-menu-end">
            {{-- First, because it is the one anybody writing copy wants. --}}
            <li>
                <a class="dropdown-item fw-semibold" href="{{ route('admin.language.landing', $row->id) }}">
                    <i class="fa fa-pen-nib me-2"></i>{{ __('Edit Landing Page Content') }}
                </a>
            </li>

            <li><hr class="dropdown-divider"></li>

            <li>
                <a class="dropdown-item" href="{{ route('admin.language.panel', $row->id) }}">
                    <i class="fa fa-file-code me-2"></i>{{ __('Edit Panel Json') }}
                </a>
            </li>
            <li>
                <a class="dropdown-item" href="{{ route('admin.language.mobile', $row->id) }}">
                    <i class="fa fa-file-code me-2"></i>{{ __('Edit Mobile Json') }}
                </a>
            </li>
            <li>
                <a class="dropdown-item" href="{{ route('admin.language.web', $row->id) }}">
                    <i class="fa fa-file-code me-2"></i>{{ __('Edit Web Json') }}
                </a>
            </li>
        </ul>
    </div>
@endif
