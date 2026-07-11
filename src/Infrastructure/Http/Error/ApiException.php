<?php

declare(strict_types=1);

namespace App\Infrastructure\Http\Error;

final class ApiException extends \RuntimeException
{
    public function __construct(
        string $message,
        private readonly string $apiCode,
        private readonly int $httpStatus = 400,
        ?\Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
    }

    public function apiCode(): string
    {
        return $this->apiCode;
    }

    public function httpStatus(): int
    {
        return $this->httpStatus;
    }
}
