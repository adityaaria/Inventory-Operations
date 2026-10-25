<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Entity\Customer;
use App\Entity\Product;
use App\Entity\Supplier;
use App\Entity\User;
use App\Entity\Warehouse;
use App\Exception\HttpException;
use App\Repository\InMemory\InMemoryDocumentSequenceRepository;
use App\Repository\InMemory\InMemoryProductRepository;
use App\Repository\InMemory\InMemoryPurchaseOrderRepository;
use App\Repository\InMemory\InMemorySalesOrderRepository;
use App\Repository\InMemory\InMemoryStockLedgerRepository;
use App\Repository\InMemory\InMemoryStockRepository;
use App\Security\AuthContext;
use App\Service\PurchaseOrderService;
use App\Service\SalesOrderService;
use App\Service\StockService;
use App\Support\OrderNumber;
use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** D-11 / ADR-017: server-assigned PREFIX-YYMM-NNNN order numbers. */
final class OrderNumberTest extends TestCase
{
    public function testFormatPadsToFourDigitsAndGrowsBeyond9999(): void
    {
        self::assertSame('PO-2610-0001', OrderNumber::format('PO', '2610', 1));
        self::assertSame('SO-2610-0038', OrderNumber::format('SO', '2610', 38));
        self::assertSame('PO-2610-9999', OrderNumber::format('PO', '2610', 9999));
        self::assertSame('PO-2610-10000', OrderNumber::format('PO', '2610', 10000));
        self::assertSame('SO-2701-123456', OrderNumber::format('SO', '2701', 123456));
    }

    public function testPeriodIsYearAndMonthOfTheGivenMoment(): void
    {
        $utc = new DateTimeZone('UTC');
        self::assertSame('2610', OrderNumber::period(new DateTimeImmutable('2026-10-31 23:59:59', $utc)));
        self::assertSame('2611', OrderNumber::period(new DateTimeImmutable('2026-11-01 00:00:00', $utc)));
        self::assertSame('2701', OrderNumber::period(new DateTimeImmutable('2027-01-15 08:00:00', $utc)));
        // The month follows the moment's own zone: 31 Oct 20:00 UTC is already 1 Nov in Jakarta (UTC+7).
        $moment = new DateTimeImmutable('2026-10-31 20:00:00', $utc);
        self::assertSame('2611', OrderNumber::period($moment->setTimezone(new DateTimeZone('Asia/Jakarta'))));
    }

    public function testParseAcceptsOnlyTheExactGeneratedPattern(): void
    {
        self::assertSame(['prefix' => 'PO', 'period' => '2610', 'value' => 18], OrderNumber::parse('PO-2610-0018'));
        self::assertSame(['prefix' => 'SO', 'period' => '2610', 'value' => 10000], OrderNumber::parse('SO-2610-10000'));
        foreach (['PO-20261009141927', 'PO-2610-018', 'PO-2610-0018-X', 'XX-2610-0001', 'PO-2610-0018' . "\n", 'CAPACITY-PO-000001'] as $legacy) {
            self::assertNull(OrderNumber::parse($legacy), $legacy);
        }
    }

    /** @return array<string, array{0: string, 1: string, 2: int}> */
    public static function invalidParts(): array
    {
        return [
            'unknown prefix' => ['XX', '2610', 1],
            'lower-case prefix' => ['po', '2610', 1],
            'month 13' => ['PO', '2613', 1],
            'month 00' => ['PO', '2600', 1],
            'short period' => ['PO', '261', 1],
            'zero value' => ['PO', '2610', 0],
        ];
    }

    #[DataProvider('invalidParts')]
    public function testFormatRejectsInvalidParts(string $prefix, string $period, int $value): void
    {
        $this->expectException(InvalidArgumentException::class);
        OrderNumber::format($prefix, $period, $value);
    }

    public function testInMemorySequenceCountsPerPrefixAndPeriod(): void
    {
        $sequences = new InMemoryDocumentSequenceRepository(new DateTimeImmutable('2026-10-10 09:00:00', new DateTimeZone('UTC')));

        self::assertSame('2610', $sequences->currentPeriod());
        self::assertSame(1, $sequences->next('PO', '2610'));
        self::assertSame(2, $sequences->next('PO', '2610'));
        self::assertSame(1, $sequences->next('SO', '2610'));
        self::assertSame(1, $sequences->next('PO', '2611'));
        self::assertSame(2, $sequences->lastValue('PO', '2610'));
    }

    public function testServicesUseTheSequenceAndContinueAfterExistingNumbers(): void
    {
        // Mirrors the 8080 demo data on 10 Oct 2026: PO-2610 ends at 0018 and SO-2610 at 0038.
        $sequences = new InMemoryDocumentSequenceRepository(new DateTimeImmutable('2026-10-10 09:00:00', new DateTimeZone('UTC')), ['PO:2610' => 18, 'SO:2610' => 38]);
        [$purchase, $sales] = $this->services($sequences);
        $admin = new AuthContext(1, 'admin@example.test', User::ROLE_ADMIN);
        $salesUser = new AuthContext(7, 'sales@example.test', User::ROLE_SALES);

        self::assertSame('PO-2610-0019', $purchase->createDraft($admin, 1, 1, [['product_id' => 10, 'quantity' => 1]])->orderNumber());
        self::assertSame('SO-2610-0039', $sales->createDraft($salesUser, 1, 1, [['product_id' => 10, 'quantity' => 1]])->orderNumber());
        self::assertSame('PO-2610-0020', $purchase->createDraft($admin, 1, 1, [['product_id' => 10, 'quantity' => 2]])->orderNumber());
        self::assertSame(20, $sequences->lastValue('PO', '2610'));
        self::assertSame(39, $sequences->lastValue('SO', '2610'));
    }

    public function testNewMonthStartsAtOneAndCounterGrowsPast9999(): void
    {
        $sequences = new InMemoryDocumentSequenceRepository(new DateTimeImmutable('2026-11-01 00:00:00', new DateTimeZone('UTC')), ['PO:2610' => 18, 'SO:2611' => 9999]);
        [$purchase, $sales] = $this->services($sequences);
        $admin = new AuthContext(1, 'admin@example.test', User::ROLE_ADMIN);

        self::assertSame('PO-2611-0001', $purchase->createDraft($admin, 1, 1, [['product_id' => 10, 'quantity' => 1]])->orderNumber());
        self::assertSame('SO-2611-10000', $sales->createDraft($admin, 1, 1, [['product_id' => 10, 'quantity' => 1]])->orderNumber());
    }

    public function testForbiddenOrInvalidCreateNeverClaimsANumber(): void
    {
        $sequences = new InMemoryDocumentSequenceRepository(new DateTimeImmutable('2026-10-10 09:00:00', new DateTimeZone('UTC')));
        [$purchase, $sales] = $this->services($sequences);
        $attempts = [
            fn () => $purchase->createDraft(new AuthContext(7, 'sales@example.test', User::ROLE_SALES), 1, 1, [['product_id' => 10, 'quantity' => 1]]),
            fn () => $sales->createDraft(new AuthContext(3, 'warehouse@example.test', User::ROLE_WAREHOUSE_STAFF), 1, 1, [['product_id' => 10, 'quantity' => 1]]),
            fn () => $purchase->createDraft(new AuthContext(1, 'admin@example.test', User::ROLE_ADMIN), 1, 1, []),
            fn () => $sales->createDraft(new AuthContext(1, 'admin@example.test', User::ROLE_ADMIN), 1, 1, [['product_id' => 999, 'quantity' => 1]]),
        ];
        foreach ($attempts as $index => $attempt) {
            try {
                $attempt();
                self::fail('Attempt ' . $index . ' must be rejected.');
            } catch (HttpException | InvalidArgumentException) {
            }
        }

        self::assertSame(0, $sequences->lastValue('PO', '2610'));
        self::assertSame(0, $sequences->lastValue('SO', '2610'));
    }

    /** @return array{0: PurchaseOrderService, 1: SalesOrderService} */
    private function services(InMemoryDocumentSequenceRepository $sequences): array
    {
        $products = new InMemoryProductRepository([new Product(10, 'SKU-001', 'Widget A', 'pcs', 1000.0, 1500.0, 5, 1, true)]);
        $warehouses = [1 => new Warehouse(1, 'Main Warehouse', 'Jakarta', true)];
        $stock = new StockService(new InMemoryStockRepository(), new InMemoryStockLedgerRepository());

        return [
            new PurchaseOrderService(new InMemoryPurchaseOrderRepository(), $sequences, $products, [1 => new Supplier(1, 'Supplier', '', '', '', true)], $warehouses, $stock),
            new SalesOrderService(new InMemorySalesOrderRepository(), $sequences, $products, [1 => new Customer(1, 'Customer', '', '', '', true)], $warehouses, $stock),
        ];
    }
}
