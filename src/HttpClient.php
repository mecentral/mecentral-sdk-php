<?php

declare(strict_types=1);

namespace MeCentral;

use MeCentral\Exception\ConnectionException;
use MeCentral\Exception\MeCentralException;

/**
 * HTTP transport on ext-curl. Every resource method goes through request().
 *
 * Retry policy (see README "Retries"):
 *   - 429 is retried for every method, honouring Retry-After.
 *   - 503 is retried for every method (the edge refused before processing).
 *   - 502 / 504 and transport failures are retried for GET and DELETE only.
 *     A POST that timed out may have been processed; the caller decides.
 *
 * @internal
 */
final class HttpClient
{
    private const IDEMPOTENT = ['GET', 'DELETE'];
    private const MAX_BACKOFF_MS = 8000;

    private string $baseUrl;
    /** @var array<string,string> */
    private array $headers;
    private int $timeoutSeconds;
    private int $maxRetries;
    /** @var callable(int):void */
    private $sleep;

    /**
     * @param array<string,string> $headers
     */
    public function __construct(string $baseUrl, array $headers, int $timeoutSeconds, int $maxRetries, ?callable $sleep = null)
    {
        if (!function_exists('curl_init')) {
            throw new \RuntimeException('The MeCentral SDK requires ext-curl');
        }
        $this->baseUrl = rtrim($baseUrl, '/');
        $this->headers = $headers;
        $this->timeoutSeconds = $timeoutSeconds;
        $this->maxRetries = max(0, $maxRetries);
        $this->sleep = $sleep ?? static function (int $ms): void {
            usleep($ms * 1000);
        };
    }

    /**
     * @param array<string,scalar|null>|null $query
     * @param array<string,string> $headers
     * @return mixed decoded JSON (array), or the raw string when $raw
     */
    public function request(string $method, string $path, ?array $query = null, mixed $body = null, array $headers = [], bool $raw = false): mixed
    {
        $url = $this->buildUrl($path, $query);
        $hdrs = array_merge($this->headers, $headers);
        $payload = null;
        if ($body !== null) {
            $hdrs['Content-Type'] = 'application/json';
            $payload = json_encode($body, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        }
        $idempotent = in_array($method, self::IDEMPOTENT, true);

        for ($attempt = 0; ; $attempt++) {
            $ch = curl_init($url);
            if ($ch === false) {
                throw new ConnectionException('curl_init failed');
            }
            $responseHeaders = [];
            curl_setopt_array($ch, [
                CURLOPT_CUSTOMREQUEST  => $method,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_HTTPHEADER     => $this->formatHeaders($hdrs),
                CURLOPT_CONNECTTIMEOUT => 10,
                CURLOPT_TIMEOUT        => $this->timeoutSeconds,
                CURLOPT_SSL_VERIFYPEER => true,
                CURLOPT_FOLLOWLOCATION => false,
                CURLOPT_HEADERFUNCTION => static function ($ch, string $line) use (&$responseHeaders): int {
                    $len = strlen($line);
                    $parts = explode(':', $line, 2);
                    if (count($parts) === 2) {
                        $responseHeaders[strtolower(trim($parts[0]))] = trim($parts[1]);
                    }
                    return $len;
                },
            ]);
            if ($payload !== null) {
                curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);
            }

            $response = curl_exec($ch);
            $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
            $errno = curl_errno($ch);
            $error = curl_error($ch);
            curl_close($ch);

            if ($response === false || $errno !== 0) {
                if ($idempotent && $attempt < $this->maxRetries) {
                    ($this->sleep)($this->backoffMs($attempt, null));
                    continue;
                }
                throw new ConnectionException('Connection failed: ' . ($error !== '' ? $error : 'curl error ' . $errno));
            }

            if ($status >= 200 && $status < 300) {
                return $raw ? (string) $response : $this->decode((string) $response, $responseHeaders);
            }

            // Never follow redirects (CURLOPT_FOLLOWLOCATION is off): a JSON API
            // that answers 3xx is misconfigured, and a followed 301 silently
            // turns a POST into a GET.
            if ($status >= 300 && $status < 400) {
                $location = $responseHeaders['location'] ?? null;
                throw new MeCentralException(
                    'Unexpected redirect from ' . $url . ($location ? ' to ' . $location : '') . '; the SDK never follows redirects',
                    $status
                );
            }

            $decoded = $this->decode((string) $response, $responseHeaders);
            $exception = MeCentralException::fromResponse($status, $decoded, $responseHeaders);
            $retryable = $status === 429 || $status === 503 || ($idempotent && ($status === 502 || $status === 504));
            if ($retryable && $attempt < $this->maxRetries) {
                ($this->sleep)($this->backoffMs($attempt, $exception->retryAfter));
                continue;
            }
            throw $exception;
        }
    }

    /**
     * @param array<string,scalar|null>|null $query
     */
    private function buildUrl(string $path, ?array $query): string
    {
        $url = $this->baseUrl . ($path[0] === '/' ? $path : '/' . $path);
        if ($query) {
            $params = [];
            foreach ($query as $k => $v) {
                if ($v === null) {
                    continue;
                }
                $params[$k] = is_bool($v) ? ($v ? 'true' : 'false') : (string) $v;
            }
            if ($params) {
                $url .= (str_contains($url, '?') ? '&' : '?') . http_build_query($params);
            }
        }
        return $url;
    }

    /**
     * @param array<string,string> $headers
     * @return list<string>
     */
    private function formatHeaders(array $headers): array
    {
        $out = [];
        foreach ($headers as $k => $v) {
            $out[] = $k . ': ' . $v;
        }
        return $out;
    }

    /**
     * @param array<string,string> $headers
     */
    private function decode(string $text, array $headers): mixed
    {
        if ($text === '') {
            return null;
        }
        $ct = $headers['content-type'] ?? '';
        $first = $text[0];
        if (str_contains($ct, 'json') || $first === '{' || $first === '[') {
            $decoded = json_decode($text, true);
            if (json_last_error() === JSON_ERROR_NONE) {
                return $decoded;
            }
        }
        return $text;
    }

    private function backoffMs(int $attempt, ?int $retryAfter): int
    {
        if ($retryAfter !== null && $retryAfter >= 0) {
            return min($retryAfter * 1000, 60000);
        }
        $base = 400 * (2 ** $attempt);
        return (int) min($base + random_int(0, 200), self::MAX_BACKOFF_MS);
    }
}
