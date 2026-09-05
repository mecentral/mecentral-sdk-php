<?php

declare(strict_types=1);

namespace MeCentral\Exception;

/** A deposit job did not complete within the polling window. */
class DepositTimeoutException extends MeCentralException
{
    public readonly string $jobId;
    public readonly mixed $lastStatus;

    public function __construct(string $jobId, mixed $lastStatus)
    {
        parent::__construct("Deposit job {$jobId} did not complete in time", 0, null, $lastStatus);
        $this->jobId = $jobId;
        $this->lastStatus = $lastStatus;
    }
}
