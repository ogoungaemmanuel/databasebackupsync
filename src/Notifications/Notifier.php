<?php

namespace DatabaseBackupSync\Notifications;

use DatabaseBackupSync\Notifications\Channels\SlackChannel;
use DatabaseBackupSync\Notifications\Channels\WebhookChannel;
use Illuminate\Contracts\Container\Container;
use Illuminate\Mail\Mailable;
use Illuminate\Support\Facades\Mail;

class Notifier
{
    public function __construct(protected Container $app)
    {
    }

    /**
     * Send a notification through every enabled channel.
     *
     * @param  array{subject: string, text: string, level?: string, fields?: array<string, string>}  $payload
     */
    public function send(array $payload): void
    {
        $channels = $this->app['config']->get('database-backup.notifications.channels', []);

        foreach ($channels as $name => $config) {
            if (empty($config['enabled'])) {
                continue;
            }

            try {
                match ($name) {
                    'slack' => $this->sendSlack($config, $payload),
                    'email' => $this->sendEmail($config, $payload),
                    'webhook' => $this->sendWebhook($config, $payload),
                    default => null,
                };
            } catch (\Throwable $e) {
                logger()->error('database-backup: notification channel failed', [
                    'channel' => $name,
                    'error' => $e->getMessage(),
                ]);
            }
        }
    }

    /**
     * @param  array<string, mixed>  $config
     * @param  array{subject: string, text: string, level?: string, fields?: array<string, string>}  $payload
     */
    protected function sendSlack(array $config, array $payload): void
    {
        $webhook = (string) ($config['webhook_url'] ?? '');

        if ($webhook === '') {
            return;
        }

        (new SlackChannel($webhook, (string) ($config['channel'] ?? ''), (string) ($config['username'] ?? 'Database Backup')))
            ->send($payload);
    }

    /**
     * @param  array<string, mixed>  $config
     * @param  array{subject: string, text: string, level?: string, fields?: array<string, string>}  $payload
     */
    protected function sendEmail(array $config, array $payload): void
    {
        $to = $config['to'] ?? [];

        if ($to === []) {
            return;
        }

        $mailable = new BackupNotification($payload['subject'], $payload['text'], $payload['fields'] ?? []);

        if (! empty($config['from'])) {
            $mailable->from((string) $config['from']);
        }

        Mail::to($to)->send($mailable);
    }

    /**
     * @param  array<string, mixed>  $config
     * @param  array{subject: string, text: string, level?: string, fields?: array<string, string>}  $payload
     */
    protected function sendWebhook(array $config, array $payload): void
    {
        $url = (string) ($config['url'] ?? '');

        if ($url === '') {
            return;
        }

        (new WebhookChannel($url, (string) ($config['secret'] ?? ''), (int) ($config['timeout'] ?? 10)))
            ->send($payload);
    }
}
