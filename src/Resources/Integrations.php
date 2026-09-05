<?php

declare(strict_types=1);

namespace MeCentral\Resources;

use MeCentral\HttpClient;

/**
 * /v1/integrations — signing certificates for provenance tiers. Scope: vault.deposit_on_behalf
 */
final class Integrations
{
    public function __construct(private readonly HttpClient $http)
    {
    }

    /**
     * GET /integrations/signing-certificates
     * @return array<string,mixed>
     */
    public function listSigningCertificates(): array
    {
        return $this->http->request('GET', '/integrations/signing-certificates');
    }

    /**
     * POST /integrations/signing-certificates — only the fingerprint is kept.
     * @return array<string,mixed>
     */
    public function registerSigningCertificate(string $certificatePem, ?string $label = null): array
    {
        $body = ['certificate_pem' => $certificatePem];
        if ($label !== null) {
            $body['label'] = $label;
        }
        return $this->http->request('POST', '/integrations/signing-certificates', null, $body);
    }

    /**
     * DELETE /integrations/signing-certificates — tombstone.
     * @return array<string,mixed>
     */
    public function revokeSigningCertificate(string $fingerprintSha256, ?string $reason = null): array
    {
        $body = ['fingerprint_sha256' => $fingerprintSha256];
        if ($reason !== null) {
            $body['reason'] = $reason;
        }
        return $this->http->request('DELETE', '/integrations/signing-certificates', null, $body);
    }
}
