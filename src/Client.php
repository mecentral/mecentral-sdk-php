<?php

declare(strict_types=1);

namespace MeCentral;

use MeCentral\Exception\ConnectionException;
use MeCentral\Exception\MeCentralException;
use MeCentral\Resources\Connections;
use MeCentral\Resources\Custody;
use MeCentral\Resources\Integrations;
use MeCentral\Resources\People;
use MeCentral\Resources\Vault;
use MeCentral\Resources\WebhookEndpoints;

/**
 * MeCentral — the client.
 *
 *   $mc = new \MeCentral\Client(getenv('MECENTRAL_API_KEY'));
 *   $page = $mc->vault->listDocuments(limit: 20);
 *
 * Authentication is the X-API-Key header. Keys are mec_sbx_… (sandbox),
 * mec_prd_… (production) or mec_dev_… (legacy). The People Graph endpoints
 * additionally need a member connection token (mec_tok_…) obtained through
 * connections->request() + connections->verify(); pass it as the
 * 'connection_token' option or derive a bound client with withConnection().
 */
final class Client
{
    public const SDK_VERSION = '0.1.0';
    public const DEFAULT_BASE_URL = 'https://api.mecentral.org/v1';

    private const KEY_PATTERN = '/^mec_(sbx|prd|dev)_[0-9a-f]{32,64}$/';

    public readonly string $baseUrl;
    public readonly Vault $vault;
    public readonly Custody $custody;
    public readonly Connections $connections;
    public readonly People $people;
    public readonly WebhookEndpoints $webhooks;
    public readonly Integrations $integrations;

    private string $apiKey;
    /** @var array<string,mixed> */
    private array $options;

    /**
     * @param array{
     *   base_url?: string,
     *   connection_token?: string|null,
     *   timeout?: int,
     *   max_retries?: int,
     *   headers?: array<string,string>
     * } $options
     */
    public function __construct(string $apiKey, array $options = [])
    {
        if ($apiKey === '') {
            throw new \InvalidArgumentException('MeCentral\\Client: an API key is required');
        }
        if (!preg_match(self::KEY_PATTERN, $apiKey)) {
            throw new \InvalidArgumentException('MeCentral\\Client: keys look like mec_sbx_… or mec_prd_… followed by 32–64 hex characters');
        }
        $this->apiKey = $apiKey;
        $this->options = $options;
        $this->baseUrl = rtrim($options['base_url'] ?? self::DEFAULT_BASE_URL, '/');

        $headers = array_merge([
            'X-API-Key'  => $apiKey,
            'Accept'     => 'application/json',
            'User-Agent' => 'mecentral-sdk-php/' . self::SDK_VERSION,
        ], $options['headers'] ?? []);
        if (!empty($options['connection_token'])) {
            $headers['Authorization'] = 'Bearer ' . $options['connection_token'];
        }

        $http = new HttpClient(
            $this->baseUrl,
            $headers,
            (int) ($options['timeout'] ?? 30),
            (int) ($options['max_retries'] ?? 2)
        );

        $this->vault = new Vault($http);
        $this->custody = new Custody($http);
        $this->connections = new Connections($http);
        $this->people = new People($http);
        $this->webhooks = new WebhookEndpoints($http);
        $this->integrations = new Integrations($http);
    }

    /**
     * A client bound to one connected member: same key and options, plus the
     * member's mec_tok_… on every request. Use it for $people.
     */
    public function withConnection(string $connectionToken): self
    {
        return new self($this->apiKey, array_merge($this->options, ['connection_token' => $connectionToken]));
    }

    /**
     * Self-service provisioning for build sessions: SANDBOX by default, or
     * PRODUCTION (mec_prd_) with mode 'production' plus an operator_reason when
     * the door's operator has switched production on. Authenticates with the
     * operator token (INTEGRATION_PROVISION_TOKEN), not an API key; the door
     * answers only to allowlisted source addresses. The raw key is in the
     * response and nowhere else.
     *
     * @param array{
     *   slug: string, name?: string, purpose?: string, scopes?: list<string>,
     *   mode?: 'sandbox'|'production', operator_reason?: string,
     *   ttl_days?: int, rotate?: bool
     * } $params
     * @return array<string,mixed>
     */
    public static function provisionSandbox(string $token, array $params, string $baseUrl = self::DEFAULT_BASE_URL, int $timeout = 30): array
    {
        $url = rtrim($baseUrl, '/') . '/integrations/provision';
        $ch = curl_init($url);
        if ($ch === false) {
            throw new ConnectionException('curl_init failed');
        }
        $responseHeaders = [];
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => json_encode($params, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_TIMEOUT        => $timeout,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_HTTPHEADER     => [
                'Authorization: Bearer ' . $token,
                'Content-Type: application/json',
                'Accept: application/json',
                'User-Agent: mecentral-sdk-php/' . self::SDK_VERSION,
            ],
            CURLOPT_HEADERFUNCTION => static function (\CurlHandle $_, string $line) use (&$responseHeaders): int {
                $parts = explode(':', $line, 2);
                if (count($parts) === 2) {
                    $responseHeaders[strtolower(trim($parts[0]))] = trim($parts[1]);
                }
                return strlen($line);
            },
        ]);
        $response = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $error = curl_error($ch);
        if ($response === false) {
            throw new ConnectionException('Connection failed: ' . $error);
        }
        $decoded = json_decode((string) $response, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            $decoded = (string) $response;
        }
        if ($status < 200 || $status >= 300) {
            throw MeCentralException::fromResponse($status, $decoded, $responseHeaders);
        }
        return is_array($decoded) ? $decoded : [];
    }
}
