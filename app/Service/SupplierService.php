<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Supplier;
use App\Exception\HttpException;
use App\Repository\Contract\SupplierRepositoryInterface;
use App\Security\AuthContext;
use InvalidArgumentException;

final class SupplierService
{
    public function __construct(
        private readonly SupplierRepositoryInterface $suppliers,
        private readonly MasterDataAuthorizationService $authorization,
    ) {}

    /** @return list<Supplier> */
    public function all(AuthContext $actor): array
    {
        if (!$this->authorization->canRead($actor)) {
            throw new HttpException(403, 'Forbidden');
        }
        return $this->suppliers->all();
    }

    public function create(AuthContext $actor, string $name, string $email, string $phone, string $address): Supplier
    {
        $this->assertCanWrite($actor);
        $this->assertValid($name, $email);
        return $this->suppliers->create(trim($name), trim($email), trim($phone), trim($address), true);
    }

    public function update(AuthContext $actor, int $id, string $name, string $email, string $phone, string $address): Supplier
    {
        $this->assertCanWrite($actor);
        $this->assertValid($name, $email);
        return $this->suppliers->update($id, trim($name), trim($email), trim($phone), trim($address));
    }

    public function setActive(AuthContext $actor, int $id, bool $isActive): void
    {
        $this->assertCanWrite($actor);
        $this->suppliers->setActive($id, $isActive);
    }

    private function assertCanWrite(AuthContext $actor): void
    {
        if (!$this->authorization->canWrite($actor)) {
            throw new HttpException(403, 'Forbidden');
        }
    }

    private function assertValid(string $name, string $email): void
    {
        if (trim($name) === '') {
            throw new InvalidArgumentException('Name is required.');
        }
        if (trim($email) !== '' && filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            throw new InvalidArgumentException('Valid email is required.');
        }
    }
}
