<?php

namespace App\Modules\Complaint\Enums;

/**
 * What the complaint is about.
 *
 * A closed set, so «أكتر سبب شكوى إيه» has an answer. Free text alone leaves that
 * question unanswerable unless somebody reads every row one at a time, which is
 * exactly what nobody does once there are a few hundred.
 *
 * `Other` exists deliberately. Without it a customer with a real problem that
 * does not fit picks the nearest wrong category, and the tally becomes a lie.
 */
enum ComplaintCategory: string
{
    case DamagedItem = 'damaged_item';
    case MissingItem = 'missing_item';
    case NotClean = 'not_clean';
    case Late = 'late';
    case DriverConduct = 'driver_conduct';
    case Payment = 'payment';
    case AppProblem = 'app_problem';

    /**
     * «مشكلة مع العميل» — the driver's side of `DriverConduct`.
     *
     * Added because it was missing entirely: every other case here describes
     * something done to a customer, so a driver with a real problem at a doorstep
     * — nobody home for the fourth time, an address that is a field, somebody
     * abusive — had nothing to file it under but «أخرى», where it stops being
     * countable. Operations cannot act on a pattern it cannot see.
     */
    case CustomerConduct = 'customer_conduct';

    /**
     * «تواصل معنا» — the free-text box under the phone, WhatsApp and email rows.
     *
     * Not a complaint anybody picks: both apps draw a message box with a send
     * button and no category chooser at all, so the app sets this itself. It
     * exists rather than folding into `Other` because «كام واحد كتبلنا من تواصل
     * معنا» is a question somebody will ask, and `Other` is where an answer goes
     * to stop being countable.
     *
     * It is deliberately **not selectable**: offering «طلب دعم» inside the
     * complaint-type dropdown would be offering a screen's name as a kind of
     * problem.
     */
    case SupportRequest = 'support_request';
    case Other = 'other';

    public function label(): string
    {
        return match ($this) {
            self::DamagedItem => 'Damaged item',
            self::MissingItem => 'Missing item',
            self::NotClean => 'Not cleaned properly',
            self::Late => 'Late',
            self::DriverConduct => 'Driver conduct',
            self::Payment => 'Payment problem',
            self::AppProblem => 'App problem',
            self::CustomerConduct => 'Problem with the customer',
            self::SupportRequest => 'Support request',
            self::Other => 'Something else',
        };
    }

    /**
     * Who may file this, because the two apps are not complaining about the
     * same things.
     *
     * Half of this list is meaningless to a driver — «هدوم اتخربت», «قطعة
     * ناقصة», «مش نضيفة» describe the laundry's work on somebody's clothes, and
     * offering them on the driver's screen is offering the wrong words to
     * somebody with a real problem. `driver_conduct` is a customer complaining
     * about a driver, and `customer_conduct` is its mirror.
     *
     * The rest — late, payment, the app itself, anything else — genuinely happen
     * to both, so they are `both` rather than duplicated per audience.
     *
     * Mirrors `faqs.audience`, which has worked this way since that table was
     * created, so an app reads the same `?audience=` on both endpoints.
     *
     * @return 'both'|'customer'|'driver'
     */
    public function audience(): string
    {
        return match ($this) {
            self::DamagedItem,
            self::MissingItem,
            self::NotClean,
            self::DriverConduct => 'customer',

            self::CustomerConduct => 'driver',

            self::Late,
            self::Payment,
            self::AppProblem,
            self::SupportRequest,
            self::Other => 'both',
        };
    }

    /**
     * Whether this case is offered to the given audience.
     *
     * One definition, because two things ask: the list an app is shown, and the
     * check on submit. A category a driver was never offered is a category a
     * driver may not file, or the filtering would be decoration.
     */
    public function servesAudience(?string $audience): bool
    {
        if (! in_array($audience, ['customer', 'driver'], true)) {
            return true;
        }

        return $this->audience() === 'both' || $this->audience() === $audience;
    }

    /**
     * Whether a person ever picks this from the complaint-type list.
     *
     * `support_request` is the only one that is not: «تواصل معنا» is a message
     * box with a send button and no chooser, so the app sets the category
     * itself. Listing it in the dropdown would put a screen's name among kinds
     * of problem.
     */
    public function isSelectable(): bool
    {
        return $this !== self::SupportRequest;
    }

    /**
     * What the complaint-type list **offers** this audience.
     *
     * Kept apart from `acceptedFrom()` on purpose: what a person may choose and
     * what the endpoint may be sent are two different sets the moment one
     * category is set by a screen rather than picked from a list. Collapsing
     * them would either hide «تواصل معنا» from the API or show it in the
     * dropdown.
     *
     * @return array<int, self>
     */
    public static function offeredTo(?string $audience): array
    {
        return array_values(array_filter(
            self::cases(),
            fn (self $case) => $case->isSelectable() && $case->servesAudience($audience)
        ));
    }

    /**
     * What the endpoint **accepts** from this audience.
     *
     * @return array<int, self>
     */
    public static function acceptedFrom(?string $audience): array
    {
        return array_values(array_filter(
            self::cases(),
            fn (self $case) => $case->servesAudience($audience)
        ));
    }

    /**
     * Whether this kind of complaint is about a specific order.
     *
     * Used to nudge, not to enforce: a customer complaining about a damaged item
     * without naming the order leaves operations guessing, but refusing the
     * complaint outright would lose it entirely.
     */
    public function usuallyAboutAnOrder(): bool
    {
        return in_array($this, [
            self::DamagedItem,
            self::MissingItem,
            self::NotClean,
            self::Late,
            self::DriverConduct,
            // The driver's mirror of it. «مشكلة مع العميل» is always about one
            // doorstep, so the order is what makes it actionable.
            self::CustomerConduct,
        ], true);
    }

    /** @return array<int, string> */
    public static function values(): array
    {
        return array_map(fn (self $c) => $c->value, self::cases());
    }
}
