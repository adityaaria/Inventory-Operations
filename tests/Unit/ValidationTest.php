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
        self::assertSame(0, InputValidator::nonNegativeInt('reorder_point', '0'));
        self::assertSame(0.0, InputValidator::nonNegativeMoney('price', '0'));
        self::assertSame('2026-08-31', InputValidator::date('from', '2026-08-31'));
    }

    public function testRejectsInvalidValues(): void
    {
        $this->expectException(ValidationException::class);

        InputValidator::positiveInt('quantity', '0');
    }

    public function testRequiredStringRejectsBlank(): void
    {
        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('name is required.');

        InputValidator::requiredString('name', '   ');
    }

    /** @dataProvider invalidEmailProvider */
    public function testEmailRejectsInvalidAddresses(mixed $value): void
    {
        $this->expectException(ValidationException::class);

        InputValidator::email('email', $value);
    }

    /** @return list<array{0: mixed}> */
    public static function invalidEmailProvider(): array
    {
        return [
            [''],
            ['not-an-email'],
        ];
    }

    public function testEnumRejectsValueOutsideAllowedList(): void
    {
        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('Invalid role.');

        InputValidator::enum('role', 'SuperAdmin', ['Admin', 'Sales']);
    }

    /** @dataProvider invalidPositiveIntProvider */
    public function testPositiveIntRejectsZeroNegativeAndNonNumeric(mixed $value): void
    {
        $this->expectException(ValidationException::class);

        InputValidator::positiveInt('quantity', $value);
    }

    /** @return list<array{0: mixed}> */
    public static function invalidPositiveIntProvider(): array
    {
        return [
            ['0'],
            ['-1'],
            ['abc'],
        ];
    }

    public function testNonNegativeIntRejectsNegative(): void
    {
        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('reorder point cannot be negative.');

        InputValidator::nonNegativeInt('reorder_point', '-1');
    }

    public function testNonNegativeIntRejectsNonNumeric(): void
    {
        $this->expectException(ValidationException::class);

        InputValidator::nonNegativeInt('reorder_point', 'abc');
    }

    public function testNonNegativeMoneyRejectsNegative(): void
    {
        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('price cannot be negative.');

        InputValidator::nonNegativeMoney('price', '-10.5');
    }

    public function testNonNegativeMoneyRejectsNonNumeric(): void
    {
        $this->expectException(ValidationException::class);

        InputValidator::nonNegativeMoney('price', 'abc');
    }

    /** @dataProvider invalidDateProvider */
    public function testDateRejectsMalformedOrImpossibleDates(string $value): void
    {
        $this->expectException(ValidationException::class);

        InputValidator::date('from', $value);
    }

    /** @return list<array{0: string}> */
    public static function invalidDateProvider(): array
    {
        return [
            ['not-a-date'],
            ['2026-13-01'],
            ['2026-08-32'],
            ['26-08-31'],
        ];
    }
}
