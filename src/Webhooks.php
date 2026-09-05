<?php

declare(strict_types=1);

namespace MeCentral;

use MeCentral\Exception\SignatureVerificationException;

/**
 * Webhook delivery verification.
 *
 * Every delivery carries:
 *   X-MeCentral-Event:     deposit.completed
 *   X-MeCentral-Timestamp: 1785000000
 *   X-MeCentral-Signature: sha256=<hex>
 *
 * where the signature is HMAC-SHA256 over "<timestamp>.<raw body>" keyed
 * with the endpoint secret returned once at registration. The timestamp is
 * inside the signed string so a captured delivery cannot be replayed later.
 *
 * Use the RAW request body (file_get_contents('php://input')). Re-serialising
 * parsed JSON changes bytes and breaks the signature.
 */
final class Webhooks
{
    public const DEFAULT_TOLERANCE = 300;

    /**
     * Verify a delivery and return the parsed event:
     *   ['type' => 'deposit.completed', 'timestamp' => 1785000000, 'data' => [...], 'raw' => '...']
     *
     * $headers may be an associative array (any case; Laravel/Symfony style
     * arrays of values are accepted) or omitted to read from $_SERVER.
     *
     * @param array<string,mixed>|null $headers
     * @return array{type:string,timestamp:int,data:array<string,mixed>,raw:string}
     * @throws SignatureVerificationException
     */
    public static function constructEvent(string $rawBody, ?array $headers, string $secret, int $tolerance = self::DEFAULT_TOLERANCE, ?int $now = null): array
    {
        if ($secret === '') {
            throw new SignatureVerificationException('Webhook secret is empty');
        }
        $headers ??= self::headersFromServer();

        $sig = self::header($headers, 'X-MeCentral-Signature');
        $tsRaw = self::header($headers, 'X-MeCentral-Timestamp');
        $type = self::header($headers, 'X-MeCentral-Event');
        if ($sig === null || $sig === '') {
            throw new SignatureVerificationException('Missing X-MeCentral-Signature header');
        }
        if ($tsRaw === null || !ctype_digit($tsRaw)) {
            throw new SignatureVerificationException('Missing or malformed X-MeCentral-Timestamp header');
        }
        if ($type === null || $type === '') {
            throw new SignatureVerificationException('Missing X-MeCentral-Event header');
        }
        if (!preg_match('/^sha256=([0-9a-fA-F]{64})$/', trim($sig), $m)) {
            throw new SignatureVerificationException('Malformed X-MeCentral-Signature header');
        }

        $ts = (int) $tsRaw;
        $drift = abs(($now ?? time()) - $ts);
        if ($drift > $tolerance) {
            throw new SignatureVerificationException("Timestamp outside tolerance ({$drift}s > {$tolerance}s)");
        }

        $expected = hash_hmac('sha256', $ts . '.' . $rawBody, $secret);
        if (!hash_equals($expected, strtolower($m[1]))) {
            throw new SignatureVerificationException('Signature mismatch');
        }

        $data = json_decode($rawBody, true);
        if (!is_array($data)) {
            throw new SignatureVerificationException('Body is not a JSON object');
        }
        return ['type' => $type, 'timestamp' => $ts, 'data' => $data, 'raw' => $rawBody];
    }

    /**
     * Boolean form of constructEvent for callers that do not want exceptions.
     *
     * @param array<string,mixed>|null $headers
     */
    public static function verify(string $rawBody, ?array $headers, string $secret, int $tolerance = self::DEFAULT_TOLERANCE): bool
    {
        try {
            self::constructEvent($rawBody, $headers, $secret, $tolerance);
            return true;
        } catch (SignatureVerificationException) {
            return false;
        }
    }

    /**
     * Produce the headers MeCentral would send for a body — for tests and for
     * simulating deliveries against your own receiver.
     *
     * @return array<string,string>
     */
    public static function sign(string $rawBody, string $secret, string $event = 'deposit.completed', ?int $timestamp = null): array
    {
        $ts = $timestamp ?? time();
        return [
            'X-MeCentral-Event'     => $event,
            'X-MeCentral-Timestamp' => (string) $ts,
            'X-MeCentral-Signature' => 'sha256=' . hash_hmac('sha256', $ts . '.' . $rawBody, $secret),
        ];
    }

    /**
     * @param array<string,mixed> $headers
     */
    private static function header(array $headers, string $name): ?string
    {
        $want = strtolower($name);
        $server = 'HTTP_' . strtoupper(str_replace('-', '_', $name));
        foreach ($headers as $k => $v) {
            if (strtolower((string) $k) === $want || $k === $server) {
                if (is_array($v)) {
                    $v = $v[0] ?? null;
                }
                return $v === null ? null : (string) $v;
            }
        }
        return null;
    }

    /**
     * @return array<string,mixed>
     */
    private static function headersFromServer(): array
    {
        if (function_exists('getallheaders')) {
            $h = getallheaders();
            if (is_array($h)) {
                return $h;
            }
        }
        return $_SERVER;
    }
}
