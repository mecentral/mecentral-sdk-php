<?php

declare(strict_types=1);

namespace MeCentral\Exception;

/**
 * 404 — the resource does not exist for THIS integration.
 * The API never distinguishes "never existed" from "belongs to someone else".
 */
class NotFoundException extends MeCentralException
{
}
