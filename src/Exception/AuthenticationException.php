<?php

declare(strict_types=1);

namespace MeCentral\Exception;

/** 401 — the key is missing, malformed, revoked, or expired. */
class AuthenticationException extends MeCentralException
{
}
