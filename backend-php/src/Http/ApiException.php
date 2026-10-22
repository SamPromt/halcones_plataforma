<?php

declare(strict_types=1);

namespace Halcones\Http;

use RuntimeException;

final class ApiException extends RuntimeException
{
    public int $status;
    public string $errorCode;

    public function __construct(
        string $message,
        int $status = 400,
        string $errorCode = 'BAD_REQUEST'
    ) {
        $this->status = $status;
        $this->errorCode = $errorCode;
        parent::__construct($message);
    }
}
