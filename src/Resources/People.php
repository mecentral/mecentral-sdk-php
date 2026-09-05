<?php

declare(strict_types=1);

namespace MeCentral\Resources;

use MeCentral\HttpClient;

/**
 * /v1/people — a connected member's People Graph.
 * Every call needs a connection token on the client (Client::withConnection()). Scope: people.write
 */
final class People
{
    public function __construct(private readonly HttpClient $http)
    {
    }

    /**
     * GET /people
     * @return array<string,mixed>
     */
    public function list(): array
    {
        return $this->http->request('GET', '/people');
    }

    /**
     * POST /people — 201. A bad 'relationship' code is reported under relationship_error, not fatal.
     * $params: full_name (required), date_of_birth?, jurisdiction?, relationship?, facts?
     *
     * @param array<string,mixed> $params
     * @return array<string,mixed>
     */
    public function create(array $params): array
    {
        return $this->http->request('POST', '/people', null, $params);
    }

    /**
     * GET /people/{person_id}
     * @return array<string,mixed>
     */
    public function get(string $personId): array
    {
        return $this->http->request('GET', '/people/' . rawurlencode($personId));
    }

    /**
     * PATCH /people/{person_id} — any of full_name, date_of_birth, jurisdiction, relationship.
     * Pass null explicitly to clear a field; omit it to leave it untouched.
     *
     * @param array<string,mixed> $params
     * @return array<string,mixed>
     */
    public function update(string $personId, array $params): array
    {
        return $this->http->request('PATCH', '/people/' . rawurlencode($personId), null, $params);
    }

    /**
     * DELETE /people/{person_id} — tombstone. Idempotent (already_removed).
     * @return array<string,mixed>
     */
    public function remove(string $personId, ?string $reason = null): array
    {
        return $this->http->request('DELETE', '/people/' . rawurlencode($personId), null, $reason !== null ? ['reason' => $reason] : null);
    }

    /**
     * GET /people/{person_id}/facts
     * @return array<string,mixed>
     */
    public function listFacts(string $personId): array
    {
        return $this->http->request('GET', '/people/' . rawurlencode($personId) . '/facts');
    }

    /**
     * POST /people/{person_id}/facts — malformed facts land in 'rejected'.
     *
     * @param list<array<string,mixed>> $facts  each: key, value, source?, note?
     * @return array<string,mixed>
     */
    public function confirmFacts(string $personId, array $facts): array
    {
        return $this->http->request('POST', '/people/' . rawurlencode($personId) . '/facts', null, ['facts' => array_values($facts)]);
    }
}
