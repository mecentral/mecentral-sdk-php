<?php

declare(strict_types=1);

namespace MeCentral\Exception;

/** 429 — per-key rate limit. `$retryAfter` says how long to wait. */
class RateLimitException extends MeCentralException
{
}
