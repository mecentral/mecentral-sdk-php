<?php

declare(strict_types=1);

namespace MeCentral\Exception;

/**
 * Base class for every SDK error.
 *
 * Every HTTP failure becomes a MeCentralException (or a subclass) carrying
 * the status code, the API's `error` message and the decoded body.
 * Transport failures (DNS, TLS, timeout) become ConnectionException with
 * status 0.
 */
class MeCentralException extends \RuntimeException
{
    /** HTTP status code; 0 for transport failures. */
    public readonly int $status;
    /** Machine code from the API body (`code`), when one was sent. */
    public readonly ?string $apiCode;
    /** Decoded response body, or the raw text when it was not JSON. */
    public readonly mixed $body;
    /** Seconds to wait before retrying, from Retry-After or the body. */
    public readonly ?int $retryAfter;
    public readonly ?string $requestId;

    public function __construct(
        string $message,
        int $status = 0,
        ?string $apiCode = null,
        mixed $body = null,
        ?int $retryAfter = null,
        ?string $requestId = null,
        ?\Throwable $previous = null
    ) {
        parent::__construct($message, $status, $previous);
        $this->status = $status;
        $this->apiCode = $apiCode;
        $this->body = $body;
        $this->retryAfter = $retryAfter;
        $this->requestId = $requestId;
    }

    /**
     * Build the most specific exception class for a response.
     *
     * @param array<string,string> $headers lower-cased header names
     */
    public static function fromResponse(int $status, mixed $body, array $headers): self
    {
        $message = 'HTTP ' . $status;
        $code = null;
        $retryAfter = null;

        if (is_array($body)) {
            if (isset($body['error']) && is_string($body['error']) && $body['error'] !== '') {
                $message = $body['error'];
            } elseif (isset($body['message']) && is_string($body['message']) && $body['message'] !== '') {
                $message = $body['message'];
            }
            if (isset($body['code']) && is_string($body['code'])) {
                $code = $body['code'];
            }
            if (isset($body['retry_after']) && is_int($body['retry_after'])) {
                $retryAfter = $body['retry_after'];
            }
        } elseif (is_string($body) && trim($body) !== '') {
            $message = substr(trim($body), 0, 200);
        }

        $ra = $headers['retry-after'] ?? null;
        if (is_string($ra) && ctype_digit($ra)) {
            $retryAfter = (int) $ra;
        }
        $requestId = $headers['x-request-id'] ?? null;

        $args = [$message, $status, $code, $body, $retryAfter, $requestId];
        return match (true) {
            $status === 401 => new AuthenticationException(...$args),
            $status === 403 => new PermissionException(...$args),
            $status === 404 => new NotFoundException(...$args),
            $status === 400, $status === 422 => new ValidationException(...$args),
            $status === 429 => new RateLimitException(...$args),
            $status >= 500 => new ServerException(...$args),
            default => new self(...$args),
        };
    }
}
