<?php

declare(strict_types=1);

namespace MeCentral\Exception;

/** 5xx — the API failed. Safe to retry reads; check state before re-sending writes. */
class ServerException extends MeCentralException
{
}
