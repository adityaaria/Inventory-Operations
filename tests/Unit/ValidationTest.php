<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Exception\ValidationException;
use App\Validation\InputValidator;
use PHPUnit\Framework\TestCase;

final class ValidationTest extends TestCase
{
    public function testNormalizesRequiredEmailEnumNumbersAndDates(): void
    {
        self::assertSame('Alice', InputValidator::requiredString('name', ' Alice '));
        self::assertSame('a@example.test', InputValidator::email('email', ' a@example.test '));
        self::assertSame('Admin', InputValidator::enum('role', 'Admin', ['Admin']));
        self::assertSame(3, InputValidator::positiveInt('quantity', '3'));
        self::assertSame(0.0, InputValidator::nonNegativeMoney('price', '0'));
        self::assertSame('2026-08-31', InputValidator::date('from', '2026-08-31'));
    }

    public function testRejectsInvalidValues(): void
    {
        $this->expectException(ValidationException::class);

        InputValidator::positiveInt('quantity', '0');
    }
}
