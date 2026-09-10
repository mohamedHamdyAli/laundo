<?php

namespace App\Mail;

use App\Modules\Laundry\Models\Laundry;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * «تمت الموافقة» / «نأسف» — the answer to a laundry's application.
 *
 * Not queued. This install has no queue worker running by default, and a
 * queued mail that nothing drains is a mail that never leaves — worse than a
 * synchronous send, because it fails silently rather than in the request that
 * caused it.
 *
 * Note that `MAIL_MAILER=log` today: this is written to `storage/logs` and
 * delivered nowhere until SMTP is configured.
 */
class LaundryApplicationDecided extends Mailable
{
    use Queueable;
    use SerializesModels;

    public function __construct(
        public Laundry $laundry,
        public bool $approved,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: $this->approved
                ? __('Your laundry has been approved')
                : __('About your laundry application'),
        );
    }

    public function content(): Content
    {
        return new Content(
            markdown: 'mail.laundry-application-decided',
            with: [
                'laundryName' => getLocalizedValueDashboard($this->laundry, 'name'),
                'approved' => $this->approved,
                'reason' => $this->laundry->rejection_reason,
                'signInUrl' => route('laundry.login'),
            ],
        );
    }
}
