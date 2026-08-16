<?php

namespace DatabaseBackupSync\Notifications\Channels;

use GuzzleHttp\Client;

class WebhookChannel
{
    public function __construct(
        protected string $url,
        protected string $secret = '',
        protected int $timeout = 10,
    ) {
    }

    /**
     * @param  array{subject: string, text: string, level?: string, fields?: array<string, string>}  $payload
     */
    public function send(array $payload): void
    {
        $body = json_encode([
            'event' => 'database-backup',
            'level' => $payload['level'] ?? 'info',
            'subject' => $payload['subject'],
            'text' => $payload['text'],
            'fields' => $payload['fields'] ?? [],
            'sent_at' => date('c'),
        ], JSON_UNESCAPED_SLASHES);

        $headers = ['Content-Type' => 'application/json'];

        if ($this->secret !== '') {
            $headers['X-Backup-Signature'] = 'sha256='.hash_hmac('sha256', $body, $this->secret);
        }

        (new Client(['timeout' => $this->timeout]))->post($this->url, [
            'headers' => $headers,
            'body' => $body,
        ]);
    }
}
