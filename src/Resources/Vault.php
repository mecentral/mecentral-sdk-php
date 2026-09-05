<?php

declare(strict_types=1);

namespace MeCentral\Resources;

use MeCentral\Exception\DepositTimeoutException;
use MeCentral\HttpClient;

/**
 * /v1/vault — documents, deposits on behalf of a subject, subject identity.
 * Parameter arrays use the REST API's own snake_case keys.
 */
final class Vault
{
    public function __construct(private readonly HttpClient $http)
    {
    }

    /**
     * GET /vault/documents — documents this integration's account owns. Scope: vault.read
     * @return array<string,mixed>
     */
    public function listDocuments(?int $limit = null, ?int $offset = null): array
    {
        return $this->http->request('GET', '/vault/documents', ['limit' => $limit, 'offset' => $offset]);
    }

    /**
     * GET /vault/documents/{id} — metadata and custody facts. Scope: vault.read
     * @return array<string,mixed>
     */
    public function getDocument(string $documentId): array
    {
        return $this->http->request('GET', '/vault/documents/' . rawurlencode($documentId));
    }

    /**
     * GET /vault/documents/{id}/stream — the bytes. Scopes: vault.read + custody.read
     */
    public function downloadDocument(string $documentId): string
    {
        return $this->http->request('GET', '/vault/documents/' . rawurlencode($documentId) . '/stream', null, null, ['Accept' => '*/*'], true);
    }

    /**
     * POST /vault/deposit-on-behalf — file a document into its subject's vault.
     *
     * $params takes the API's fields (subject_email | subject_binding_token |
     * subject_identity, filename, mime_type, grants, release, collections, …).
     * Supply the bytes as 'content' (raw string) or 'content_base64'.
     * Returns 202 with a job_id; use waitForDeposit() or depositStatus().
     * Scopes: vault.deposit_on_behalf, plus custody.grant when grants is non-empty.
     *
     * @param array<string,mixed> $params
     * @return array<string,mixed>
     */
    public function depositOnBehalf(array $params): array
    {
        if (array_key_exists('content', $params)) {
            if (!isset($params['content_base64'])) {
                $params['content_base64'] = base64_encode((string) $params['content']);
            }
            unset($params['content']);
        }
        if (!isset($params['content_base64'])) {
            throw new \InvalidArgumentException("depositOnBehalf needs 'content' (bytes) or 'content_base64'");
        }
        return $this->http->request('POST', '/vault/deposit-on-behalf', null, $params);
    }

    /**
     * GET /vault/deposit-status — the source of truth for a deposit. Scope: vault.deposit_on_behalf
     * @return array<string,mixed>
     */
    public function depositStatus(string $jobId): array
    {
        return $this->http->request('GET', '/vault/deposit-status', ['job_id' => $jobId]);
    }

    /**
     * Poll until the job is completed or failed and return the final status.
     * A failed job is returned, not thrown (check 'status'), so callers can
     * read grant_errors and provenance either way.
     *
     * @return array<string,mixed>
     */
    public function waitForDeposit(string $jobId, float $intervalSeconds = 2.0, float $timeoutSeconds = 120.0): array
    {
        $interval = max(0.5, $intervalSeconds);
        $deadline = microtime(true) + $timeoutSeconds;
        while (true) {
            $last = $this->depositStatus($jobId);
            $status = $last['status'] ?? null;
            if ($status === 'completed' || $status === 'failed') {
                return $last;
            }
            if (microtime(true) + $interval > $deadline) {
                throw new DepositTimeoutException($jobId, $last);
            }
            usleep((int) ($interval * 1_000_000));
        }
    }

    /**
     * POST /vault/recall — cancel, un-hold, or contain a deposit. Idempotent.
     * @return array<string,mixed>
     */
    public function recallDeposit(string $jobId, ?string $reason = null): array
    {
        return $this->http->request('POST', '/vault/recall', null, self::clean(['job_id' => $jobId, 'reason' => $reason]));
    }

    /**
     * POST /vault/subject-update — supply or correct a subject's date of birth.
     * $params: date_of_birth (required), subject_email | document_id, full_name, reason.
     *
     * @param array<string,mixed> $params
     * @return array<string,mixed>
     */
    public function updateSubject(array $params): array
    {
        return $this->http->request('POST', '/vault/subject-update', null, $params);
    }

    /**
     * DELETE /vault/subject-bindings — revoke a TrueRoute binding token. Idempotent.
     * @return array<string,mixed>
     */
    public function revokeSubjectBinding(string $bindingToken, ?string $reason = null): array
    {
        return $this->http->request('DELETE', '/vault/subject-bindings', null, self::clean(['binding_token' => $bindingToken, 'reason' => $reason]));
    }

    /**
     * @param array<string,mixed> $a
     * @return array<string,mixed>
     */
    private static function clean(array $a): array
    {
        return array_filter($a, static fn ($v) => $v !== null);
    }
}
