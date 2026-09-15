<?php

namespace App\Modules\Notification\Requests;

use App\Modules\Notification\Enums\NotificationAudience;
use App\Modules\Notification\Services\ManualNotifier;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * What a hand-written notification has to say before it is allowed out.
 *
 * The one rule worth explaining is `target`. It is **required**, and «everyone»
 * is a value it can hold rather than the meaning of leaving it empty — because a
 * blank field that quietly means «all customers» is one distracted afternoon
 * away from a blast nobody intended.
 */
class ManualNotificationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public const EVERYONE = 'all';

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'audience' => ['required', Rule::in(NotificationAudience::values())],
            'target' => ['required', 'string'],

            // A notification title is read on a lock screen, where anything past
            // roughly this length is cut off by the handset rather than by us.
            'title' => ['required', 'string', 'max:120'],
            'body' => ['required', 'string', 'max:500'],
        ];
    }

    /**
     * That the chosen recipient is real, and belongs to the chosen audience.
     *
     * Checked here rather than with `exists:users,id` because the id must be in
     * *this* audience: without that, a hand-made request could name a customer
     * while claiming to write to drivers, and the message would go to a person
     * the operator never saw on the screen.
     */
    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            if ($validator->errors()->has('audience') || $validator->errors()->has('target')) {
                return;
            }

            $target = (string) $this->input('target');

            if ($target === self::EVERYONE) {
                return;
            }

            $audience = NotificationAudience::from((string) $this->input('audience'));
            $targets = app(ManualNotifier::class)->targets($audience);

            if (! array_key_exists((int) $target, $targets)) {
                $validator->errors()->add('target', __('Choose somebody to send this to.'));
            }
        });
    }

    /**
     * The chosen recipient, or null for «everyone in this audience».
     */
    public function targetId(): ?int
    {
        $target = (string) $this->input('target');

        return $target === self::EVERYONE ? null : (int) $target;
    }

    public function audience(): NotificationAudience
    {
        return NotificationAudience::from((string) $this->input('audience'));
    }
}
