<?php

declare(strict_types=1);

namespace MeCentral\Exception;

/** A webhook delivery failed signature or timestamp verification. */
class SignatureVerificationException extends MeCentralException
{
    public function __construct(string $message)
    {
        parent::__construct($message, 0);
    }
}
