<?php

declare(strict_types=1);

namespace MeCentral\Exception;

/** The request never produced an HTTP response (DNS, TLS, timeout). */
class ConnectionException extends MeCentralException
{
    public function __construct(string $message, ?\Throwable $previous = null)
    {
        parent::__construct($message, 0, null, null, null, null, $previous);
    }
}
