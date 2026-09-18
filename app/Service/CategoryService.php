<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Category;
use App\Exception\HttpException;
use App\Repository\Contract\CategoryRepositoryInterface;
use App\Security\AuthContext;
use InvalidArgumentException;

final class CategoryService
{
    public function __construct(
        private readonly CategoryRepositoryInterface $categories,
        private readonly MasterDataAuthorizationService $authorization,
    ) {}

    /** @return list<Category> */
    public function all(AuthContext $actor): array
    {
        $this->assertCanRead($actor);
        return $this->categories->all();
    }

    public function create(AuthContext $actor, string $name, string $description): Category
    {
        $this->assertCanWrite($actor);
        $this->assertName($name);
        return $this->categories->create(trim($name), trim($description), true);
    }

    public function update(AuthContext $actor, int $id, string $name, string $description): Category
    {
        $this->assertCanWrite($actor);
        $this->assertName($name);
        return $this->categories->update($id, trim($name), trim($description));
    }

    public function setActive(AuthContext $actor, int $id, bool $isActive): void
    {
        $this->assertCanWrite($actor);
        $this->categories->setActive($id, $isActive);
    }

    private function assertCanRead(AuthContext $actor): void
    {
        if (!$this->authorization->canRead($actor)) {
            throw new HttpException(403, 'Forbidden');
        }
    }

    private function assertCanWrite(AuthContext $actor): void
    {
        if (!$this->authorization->canWrite($actor)) {
            throw new HttpException(403, 'Forbidden');
        }
    }

    private function assertName(string $name): void
    {
        if (trim($name) === '') {
            throw new InvalidArgumentException('Name is required.');
        }
    }
}
