<?php

declare(strict_types=1);

namespace MeCentral\Resources;

use MeCentral\HttpClient;

/**
 * /v1/custody — grants over deposited documents, replacement, disputes, life events.
 */
final class Custody
{
    public function __construct(private readonly HttpClient $http)
    {
    }

    /**
     * POST /custody/grants — attach grants to deposited documents.
     * Per-item failures are in grant_errors. Scope: custody.grant
     *
     * @param list<string> $documentIds
     * @param list<array<string,mixed>> $grants  each: user, scope, expires_at?, purpose?, relationship?, expiry?
     * @return array<string,mixed>
     */
    public function grant(array $documentIds, array $grants): array
    {
        return $this->http->request('POST', '/custody/grants', null, ['document_ids' => array_values($documentIds), 'grants' => array_values($grants)]);
    }

    /**
     * DELETE /custody/grants — revoke (tombstone) grants. Idempotent. Scope: custody.grant
     *
     * @param list<string> $documentIds
     * @param list<array<string,mixed>> $grants  each: user, scope
     * @return array<string,mixed>
     */
    public function revoke(array $documentIds, array $grants, ?string $reason = null): array
    {
        $body = ['document_ids' => array_values($documentIds), 'grants' => array_values($grants)];
        if ($reason !== null) {
            $body['reason'] = $reason;
        }
        return $this->http->request('DELETE', '/custody/grants', null, $body);
    }

    /**
     * POST /custody/replace — end one custodian's authority and start another's atomically.
     * $params: subject_email | document_id, from, to, scope, relationship?, expiry?, reason?. Scope: custody.replace
     *
     * @param array<string,mixed> $params
     * @return array<string,mixed>
     */
    public function replace(array $params): array
    {
        return $this->http->request('POST', '/custody/replace', null, $params);
    }

    /**
     * POST /custody/dispute — suspend every grant on a vault pending human review. Idempotent.
     * $params: subject_email | document_id, reason. Scope: custody.events
     *
     * @param array<string,mixed> $params
     * @return array<string,mixed>
     */
    public function dispute(array $params): array
    {
        return $this->http->request('POST', '/custody/dispute', null, $params);
    }

    /**
     * POST /custody/events — assert a life event and resolve every term bound to it.
     * $params: subject_email | document_id, event, evidence?, note?. Scope: custody.events
     *
     * @param array<string,mixed> $params
     * @return array<string,mixed>
     */
    public function assertEvent(array $params): array
    {
        return $this->http->request('POST', '/custody/events', null, $params);
    }

    /**
     * GET /custody/events — the custody ledger as this integration may see it. Scope: custody.events
     * $query: subject_email | document_id, limit?
     *
     * @param array<string,scalar|null> $query
     * @return array<string,mixed>
     */
    public function listEvents(array $query): array
    {
        return $this->http->request('GET', '/custody/events', $query);
    }
}
