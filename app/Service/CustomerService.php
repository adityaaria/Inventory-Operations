<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Customer;
use App\Exception\HttpException;
use App\Repository\Contract\CustomerRepositoryInterface;
use App\Security\AuthContext;
use App\Support\PaginatedResult;
use App\Support\Pagination;
use InvalidArgumentException;

final class CustomerService
{
    public function __construct(
        private readonly CustomerRepositoryInterface $customers,
        private readonly MasterDataAuthorizationService $authorization,
    ) {}

    /** @return list<Customer> */
    public function all(AuthContext $actor): array
    {
        if (!$this->authorization->canRead($actor)) {
            throw new HttpException(403, 'Forbidden');
        }
        return $this->customers->all();
    }

    /** @return PaginatedResult<Customer> */
    public function paginate(AuthContext $actor, Pagination $pagination): PaginatedResult
    {
        if (!$this->authorization->canRead($actor)) {
            throw new HttpException(403, 'Forbidden');
        }
        return $this->customers->paginate($pagination);
    }

    /** @param array<string, mixed>|null $tax optional npwp, nik, tax_name, tax_address (D-19, ADR-025); null leaves them unchanged */
    public function create(AuthContext $actor, string $name, string $email, string $phone, string $address, ?array $tax = null): Customer
    {
        $this->assertCanWrite($actor);
        $this->assertValid($name, $email);
        \App\Validation\InputValidator::optionalString('phone', $phone, 40);
        \App\Validation\InputValidator::optionalString('address', $address, 255);
        $profile = $tax === null ? null : self::taxProfile($tax);
        $customer = $this->customers->create(trim($name), trim($email), trim($phone), trim($address), true);

        return $profile === null ? $customer : $this->customers->updateTaxProfile($customer->id(), ...$profile);
    }

    /** @param array<string, mixed>|null $tax optional npwp, nik, tax_name, tax_address (D-19, ADR-025); null leaves them unchanged */
    public function update(AuthContext $actor, int $id, string $name, string $email, string $phone, string $address, ?array $tax = null): Customer
    {
        $this->assertCanWrite($actor);
        $this->assertValid($name, $email);
        \App\Validation\InputValidator::optionalString('phone', $phone, 40);
        \App\Validation\InputValidator::optionalString('address', $address, 255);
        // Every field is validated before the first write.
        $profile = $tax === null ? null : self::taxProfile($tax);
        $customer = $this->customers->update($id, trim($name), trim($email), trim($phone), trim($address));

        return $profile === null ? $customer : $this->customers->updateTaxProfile($id, ...$profile);
    }

    /**
     * Optional tax identity snapshotted on invoices (D-19, ADR-025). NPWP: 15 or 16 digits after removing dots, dashes
     * and spaces (a loose format check, no checksum); NIK: 16 digits; tax name ≤ 120 and tax address ≤ 255 characters.
     * Empty values are stored as NULL.
     *
     * @param array<string, mixed> $input
     * @return array{0: ?string, 1: ?string, 2: ?string, 3: ?string}
     */
    public static function taxProfile(array $input): array
    {
        $text = static fn (string $key): string => is_string($input[$key] ?? null) ? trim((string) $input[$key]) : '';
        $digits = static fn (string $value): string => (string) preg_replace('/[\s.\-]/', '', $value);
        $npwp = $digits($text('npwp'));
        if ($npwp !== '' && preg_match('/^\d{15,16}$/D', $npwp) !== 1) {
            throw new InvalidArgumentException('NPWP must have 15 or 16 digits (dots, dashes and spaces are ignored).');
        }
        $nik = $digits($text('nik'));
        if ($nik !== '' && preg_match('/^\d{16}$/D', $nik) !== 1) {
            throw new InvalidArgumentException('NIK must have 16 digits.');
        }
        if (mb_strlen($text('tax_name')) > 120 || mb_strlen($text('tax_address')) > 255) {
            throw new InvalidArgumentException('Tax name may have at most 120 and tax address at most 255 characters.');
        }

        return [$npwp === '' ? null : $npwp, $nik === '' ? null : $nik, $text('tax_name') === '' ? null : $text('tax_name'), $text('tax_address') === '' ? null : $text('tax_address')];
    }

    public function setActive(AuthContext $actor, int $id, bool $isActive): void
    {
        $this->assertCanWrite($actor);
        $this->customers->setActive($id, $isActive);
    }

    private function assertCanWrite(AuthContext $actor): void
    {
        if (!$this->authorization->canWrite($actor)) {
            throw new HttpException(403, 'Forbidden');
        }
    }

    private function assertValid(string $name, string $email): void
    {
        \App\Validation\InputValidator::requiredString('name', $name, 120);
        \App\Validation\InputValidator::optionalString('email', $email, 190);
        if (trim($name) === '') {
            throw new InvalidArgumentException('Name is required.');
        }
        if (trim($email) !== '' && filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            throw new InvalidArgumentException('Valid email is required.');
        }
    }
}
