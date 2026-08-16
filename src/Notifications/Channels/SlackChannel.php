<?php

namespace DatabaseBackupSync\Notifications\Channels;

use GuzzleHttp\Client;

class SlackChannel
{
    public function __construct(
        protected string $webhookUrl,
        protected string $channel = '',
        protected string $username = 'Database Backup',
    ) {
    }

    /**
     * @param  array{subject: string, text: string, level?: string, fields?: array<string, string>}  $payload
     */
    public function send(array $payload): void
    {
        $color = match ($payload['level'] ?? 'info') {
            'error' => 'danger',
            'warning' => 'warning',
            default => 'good',
        };

        $fields = [];

        foreach ($payload['fields'] ?? [] as $name => $value) {
            $fields[] = ['title' => $name, 'value' => $value, 'short' => true];
        }

        $body = [
            'username' => $this->username,
            'attachments' => [[
                'color' => $color,
                'title' => $payload['subject'],
                'text' => $payload['text'],
                'fields' => $fields,
                'ts' => time(),
            ]],
        ];

        if ($this->channel !== '') {
            $body['channel'] = $this->channel;
        }

        (new Client(['timeout' => 15]))->post($this->webhookUrl, ['json' => $body]);
    }
}
