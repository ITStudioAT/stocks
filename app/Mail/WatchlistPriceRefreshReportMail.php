<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Attachment;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class WatchlistPriceRefreshReportMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public string $pdfContent,
        public string $filename,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'Stocks watch-list price refresh report',
        );
    }

    public function content(): Content
    {
        return new Content(
            markdown: 'mail.watchlist-price-refresh-report',
        );
    }

    /**
     * Get the attachments for the message.
     *
     * @return array<int, Attachment>
     */
    public function attachments(): array
    {
        return [
            Attachment::fromData(fn (): string => $this->pdfContent, $this->filename)
                ->withMime('application/pdf'),
        ];
    }
}
