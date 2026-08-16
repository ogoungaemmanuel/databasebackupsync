<?php

namespace DatabaseBackupSync\Drivers\Support;

use GuzzleHttp\Client;
use RuntimeException;

/**
 * Google Drive OAuth2 token acquisition.
 *
 * - service_account: JWT bearer grant (RS256) using a service account JSON
 *   key file. Recommended for servers — no user interaction, scoped to the
 *   drive.file scope, and the account can be shared with a folder.
 * - oauth: refresh-token grant for a user-authorized OAuth client.
 */
class GoogleDriveAuth
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

        $data = ($this->config['auth'] ?? 'service_account') === 'oauth'
            ? $this->oauthToken()
            : $this->serviceAccountToken();

        $this->cachedToken = (string) ($data['access_token'] ?? '');
        $this->cachedExpiry = time() + (int) ($data['expires_in'] ?? 3600);

        if ($this->cachedToken === '') {
            throw new RuntimeException('Google Drive token response did not include an access_token.');
        }

        return $this->cachedToken;
    }

    /**
     * @return array<string, mixed>
     */
    protected function serviceAccountToken(): array
    {
        $jsonPath = (string) ($this->config['service_account_json'] ?? '');

        if ($jsonPath === '' || ! is_file($jsonPath)) {
            throw new RuntimeException('Google Drive service account JSON key file not found (DB_BACKUP_DRIVE_SERVICE_ACCOUNT_JSON).');
        }

        $json = json_decode((string) file_get_contents($jsonPath), true);

        if (! is_array($json) || empty($json['client_email']) || empty($json['private_key'])) {
            throw new RuntimeException('Invalid Google Drive service account JSON key file.');
        }

        $header = ['alg' => 'RS256', 'typ' => 'JWT'];
        $claims = [
            'iss' => $json['client_email'],
            'scope' => 'https://www.googleapis.com/auth/drive.file',
            'aud' => $json['token_uri'] ?? 'https://oauth2.googleapis.com/token',
            'iat' => time(),
            'exp' => time() + 3600,
        ];

        $b64 = fn (string $data): string => rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
        $signingInput = $b64(json_encode($header)).'.'.$b64(json_encode($claims));

        $signature = '';
        if (! openssl_sign($signingInput, $signature, $json['private_key'], 'sha256WithRSAEncryption')) {
            throw new RuntimeException('Failed to sign Google Drive JWT assertion.');
        }

        $assertion = $signingInput.'.'.$b64($signature);

        $response = (new Client(['timeout' => 30]))->post($json['token_uri'] ?? 'https://oauth2.googleapis.com/token', [
            'form_params' => [
                'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
                'assertion' => $assertion,
            ],
        ]);

        return HttpClientFactory::json($response);
    }

    /**
     * @return array<string, mixed>
     */
    protected function oauthToken(): array
    {
        $response = (new Client(['timeout' => 30]))->post('https://oauth2.googleapis.com/token', [
            'form_params' => [
                'grant_type' => 'refresh_token',
                'client_id' => (string) ($this->config['client_id'] ?? ''),
                'client_secret' => (string) ($this->config['client_secret'] ?? ''),
                'refresh_token' => (string) ($this->config['refresh_token'] ?? ''),
            ],
        ]);

        return HttpClientFactory::json($response);
    }
}
