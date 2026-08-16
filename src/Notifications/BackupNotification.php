<?php

namespace DatabaseBackupSync\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class BackupNotification extends Mailable
{
    use Queueable;
    use SerializesModels;

    /**
     * @param  array<string, string>  $fields
     */
    public function __construct(
        public string $subject,
        public string $text,
        public array $fields = [],
    ) {
    }

    public function envelope(): Envelope
    {
        return new Envelope(subject: $this->subject);
    }

    public function content(): Content
    {
        return new Content(
            view: 'database-backup::notification',
            with: [
                'text' => $this->text,
                'fields' => $this->fields,
            ]
        );
    }
}
