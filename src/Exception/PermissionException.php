<?php

declare(strict_types=1);

namespace MeCentral\Exception;

/** 403 — the key is valid but lacks the scope, IP, or endpoint allowance. */
class PermissionException extends MeCentralException
{
}
