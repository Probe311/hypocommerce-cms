<?php

declare(strict_types=1);

namespace App\Application\Validation;

use RuntimeException;

final class ValidationException extends RuntimeException
{
    public function __construct(private readonly string $errorCode, string $message = '')
    {
        parent::__construct($message !== '' ? $message : $errorCode);
    }

    public function errorCode(): string
    {
        return $this->errorCode;
    }
}
