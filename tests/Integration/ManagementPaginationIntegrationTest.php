<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Repository\MySql\MySqlCategoryRepository;
use App\Repository\MySql\MySqlCustomerRepository;
use App\Repository\MySql\MySqlSupplierRepository;
use App\Repository\MySql\MySqlUserRepository;
use App\Repository\MySql\MySqlWarehouseRepository;
use App\Support\Pagination;
use PDO;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ManagementPaginationIntegrationTest extends TestCase
{
    public static function modules(): array
    {
        return [['users'], ['categories'], ['warehouses'], ['suppliers'], ['customers']];
    }

    #[DataProvider('modules')]
    public function testNativePreparedPaginationCoversEveryRowWithoutOverlap(string $module): void
    {
        $pdo = \Tests\Support\TestDatabase::connect();
        $repository = match ($module) {
            'users' => new MySqlUserRepository($pdo),
            'categories' => new MySqlCategoryRepository($pdo),
            'warehouses' => new MySqlWarehouseRepository($pdo),
            'suppliers' => new MySqlSupplierRepository($pdo),
            'customers' => new MySqlCustomerRepository($pdo),
        };
        $before = count($repository->all());
        $pdo->beginTransaction();
        try {
            $inserted = [];
            $prefix = 'Pagination-' . bin2hex(random_bytes(5));
            for ($index = 1; $index <= 23; $index++) {
                $name = $prefix . '-' . str_pad((string) $index, 2, '0', STR_PAD_LEFT);
                $active = $index !== 1;
                $record = match ($module) {
                    'users' => $repository->create($name, $name . '@example.test', password_hash('password', PASSWORD_BCRYPT), 'Sales', $active),
                    'categories' => $repository->create($name, 'Pagination test', $active),
                    'warehouses' => $repository->create($name, 'Test location', $active),
                    'suppliers', 'customers' => $repository->create($name, $name . '@example.test', '', 'Test address', $active),
                };
                $inserted[] = $record->id();
            }
            $first = $repository->paginate(new Pagination());
            self::assertSame($before + 23, $first->total());
            self::assertCount(10, $first->items());
            $seen = [];
            for ($page = 1; $page <= $first->pages(); $page++) {
                $result = $repository->paginate(new Pagination($page));
                self::assertSame($page, $result->page());
                self::assertLessThanOrEqual(10, count($result->items()));
                foreach ($result->items() as $item) {
                    self::assertNotContains($item->id(), $seen);
                    $seen[] = $item->id();
                }
            }
            self::assertCount($before + 23, $seen);
            foreach ($inserted as $id) {
                self::assertContains($id, $seen);
            }
            $second = $repository->paginate(new Pagination(2));
            $repeated = $repository->paginate(new Pagination(2));
            self::assertSame(array_map(fn ($item) => $item->id(), $second->items()), array_map(fn ($item) => $item->id(), $repeated->items()));
            self::assertSame($first->pages(), $repository->paginate(new Pagination(PHP_INT_MAX))->page());
        } finally {
            $pdo->rollBack();
        }
        self::assertSame($before, count($repository->all()));
    }
}
