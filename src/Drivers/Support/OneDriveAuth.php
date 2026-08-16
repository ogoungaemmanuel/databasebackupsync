<?php

namespace DatabaseBackupSync\Drivers\Support;

use GuzzleHttp\Client;
use RuntimeException;

/**
 * Microsoft Graph (OneDrive) token acquisition.
 *
 * - client_credentials: app-only token for a registered application with
 *   Files.ReadWrite.All application permission. Recommended for servers.
 * - authorization_code: delegated token via refresh-token grant.
 */
class OneDriveAuth
{
    protected ?string $cachedToken = null;

    protected int $cachedExpiry = 0;

    /**
     * @param  array<string, mixed>  $config
     */
    public function __construct(protected array $config)
    {
    }

    public function accessToken(): string
    {
        if ($this->cachedToken !== null && $this->cachedExpiry > time() + 60) {
            return $this->cachedToken;
        }

        $tenant = (string) ($this->config['tenant_id'] ?? 'common');
        $url = "https://login.microsoftonline.com/{$tenant}/oauth2/v2.0/token";

        $params = [
            'client_id' => (string) ($this->config['client_id'] ?? ''),
            'client_secret' => (string) ($this->config['client_secret'] ?? ''),
        ];

        if (($this->config['grant_type'] ?? 'client_credentials') === 'client_credentials') {
            $params['grant_type'] = 'client_credentials';
            $params['scope'] = 'https://graph.microsoft.com/.default';
        } else {
            $params['grant_type'] = 'refresh_token';
            $params['refresh_token'] = (string) ($this->config['refresh_token'] ?? '');
            $params['scope'] = 'https://graph.microsoft.com/Files.ReadWrite.All offline_access';
        }

        $response = (new Client(['timeout' => 30]))->post($url, ['form_params' => $params]);
        $data = HttpClientFactory::json($response);

        $this->cachedToken = (string) ($data['access_token'] ?? '');
        $this->cachedExpiry = time() + (int) ($data['expires_in'] ?? 3600);

        if ($this->cachedToken === '') {
            throw new RuntimeException('OneDrive token response did not include an access_token: '.($data['error_description'] ?? 'unknown error'));
        }

        return $this->cachedToken;
    }
}
