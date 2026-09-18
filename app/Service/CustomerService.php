<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Customer;
use App\Exception\HttpException;
use App\Repository\Contract\CustomerRepositoryInterface;
use App\Security\AuthContext;
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

    public function create(AuthContext $actor, string $name, string $email, string $phone, string $address): Customer
    {
        $this->assertCanWrite($actor);
        $this->assertValid($name, $email);
        return $this->customers->create(trim($name), trim($email), trim($phone), trim($address), true);
    }

    public function update(AuthContext $actor, int $id, string $name, string $email, string $phone, string $address): Customer
    {
        $this->assertCanWrite($actor);
        $this->assertValid($name, $email);
        return $this->customers->update($id, trim($name), trim($email), trim($phone), trim($address));
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
        if (trim($name) === '') {
            throw new InvalidArgumentException('Name is required.');
        }
        if (trim($email) !== '' && filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            throw new InvalidArgumentException('Valid email is required.');
        }
    }
}
