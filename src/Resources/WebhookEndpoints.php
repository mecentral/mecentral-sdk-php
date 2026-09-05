<?php

declare(strict_types=1);

namespace MeCentral\Resources;

use MeCentral\HttpClient;

/**
 * /v1/webhooks — endpoint registration. Scope: webhooks.manage
 * Delivery verification lives in \MeCentral\Webhooks.
 */
final class WebhookEndpoints
{
    public function __construct(private readonly HttpClient $http)
    {
    }

    /**
     * GET /webhooks/endpoints
     * @return array<string,mixed>
     */
    public function list(): array
    {
        return $this->http->request('GET', '/webhooks/endpoints');
    }

    /**
     * POST /webhooks/endpoints — 201. The 'secret' in the response is shown once.
     *
     * @param list<string> $enabledEvents
     * @return array<string,mixed>
     */
    public function create(string $url, array $enabledEvents, ?string $description = null): array
    {
        $body = ['url' => $url, 'enabled_events' => array_values($enabledEvents)];
        if ($description !== null) {
            $body['description'] = $description;
        }
        return $this->http->request('POST', '/webhooks/endpoints', null, $body);
    }

    /**
     * GET /webhooks/endpoints/{id}
     * @return array<string,mixed>
     */
    public function get(string $endpointId): array
    {
        return $this->http->request('GET', '/webhooks/endpoints/' . rawurlencode($endpointId));
    }

    /**
     * PATCH /webhooks/endpoints/{id} — any of url, enabled_events, description, disabled.
     *
     * @param array<string,mixed> $params
     * @return array<string,mixed>
     */
    public function update(string $endpointId, array $params): array
    {
        return $this->http->request('PATCH', '/webhooks/endpoints/' . rawurlencode($endpointId), null, $params);
    }

    /**
     * DELETE /webhooks/endpoints/{id}
     * @return array<string,mixed>
     */
    public function delete(string $endpointId): array
    {
        return $this->http->request('DELETE', '/webhooks/endpoints/' . rawurlencode($endpointId));
    }

    /**
     * GET /webhooks/events — the catalog registration validates against.
     * @return array<string,mixed>
     */
    public function eventTypes(): array
    {
        return $this->http->request('GET', '/webhooks/events');
    }
}
