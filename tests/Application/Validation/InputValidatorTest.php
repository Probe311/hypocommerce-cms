<?php

declare(strict_types=1);

namespace App\Tests\Application\Validation;

use App\Application\Validation\InputValidator;
use App\Application\Validation\ValidationException;
use PHPUnit\Framework\TestCase;

final class InputValidatorTest extends TestCase
{
    public function testRequireEmailNormalizesLowercase(): void
    {
        $validator = new InputValidator();
        $email = $validator->requireEmail(['email' => 'User@Example.com'], 'email', 'invalid_email');
        self::assertSame('user@example.com', $email);
    }

    public function testRequireUuidStringRejectsInvalidUuid(): void
    {
        $validator = new InputValidator();

        $this->expectException(ValidationException::class);
        $validator->requireUuidString(['id' => 'bad-id'], 'id', 'invalid_uuid');
    }
}
