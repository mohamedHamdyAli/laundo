<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Attachment;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * The weekly summary.
 *
 * The figures are in the body, not only in the attachment. A digest whose numbers
 * live in a spreadsheet is a digest nobody reads on a phone — which is where this
 * one arrives, on a Sunday morning.
 */
class WeeklyReportMail extends Mailable
{
    use Queueable, SerializesModels;

    /**
     * @param  array<string, mixed>  $digest
     * @param  array<int, array<int, string|int|float>>  $csv
     */
    public function __construct(
        public readonly array $digest,
        public readonly array $csv,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: __('Weekly report').' — '.$this->digest['title'].' · '.$this->digest['range']->label(),
        );
    }

    public function content(): Content
    {
        return new Content(view: 'emails.weekly-report');
    }

    /**
     * @return array<int, Attachment>
     */
    public function attachments(): array
    {
        $handle = fopen('php://temp', 'r+');

        // A BOM, so Excel opens Arabic labels as Arabic rather than as mojibake.
        // Without it the attachment is unreadable for the people it is for.
        fwrite($handle, "\xEF\xBB\xBF");

        foreach ($this->csv as $row) {
            fputcsv($handle, $row);
        }

        rewind($handle);
        $contents = (string) stream_get_contents($handle);
        fclose($handle);

        return [
            Attachment::fromData(fn () => $contents, 'weekly-report.csv')
                ->withMime('text/csv'),
        ];
    }
}
