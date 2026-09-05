<?php

declare(strict_types=1);

namespace MeCentral\Resources;

use MeCentral\HttpClient;

/**
 * /v1/connections — a member connects their vault to your integration.
 */
final class Connections
{
    public function __construct(private readonly HttpClient $http)
    {
    }

    /**
     * POST /connections/request — ask a member to connect; MeCentral sends them an OTP.
     * @return array<string,mixed>  ['success' => true, 'connection_id' => 'conn_…']
     */
    public function request(string $email): array
    {
        return $this->http->request('POST', '/connections/request', null, ['email' => $email]);
    }

    /**
     * POST /connections/verify — exchange the OTP for a member token (mec_tok_…).
     * @return array<string,mixed>  ['success' => true, 'token' => 'mec_tok_…', 'user_id' => 60, 'email' => '…']
     */
    public function verify(string $connectionId, string $otp): array
    {
        return $this->http->request('POST', '/connections/verify', null, ['connection_id' => $connectionId, 'otp' => $otp]);
    }
}
