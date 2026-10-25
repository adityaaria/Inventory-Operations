<?php

declare(strict_types=1);

namespace App\Repository\InMemory;

use App\Entity\StockLot;
use App\Repository\Contract\StockRepositoryInterface;
use App\Support\BusinessDate;

final class InMemoryStockRepository implements StockRepositoryInterface
{
    /** @var array<string, int> */
    private array $stocks = [];
    /** @var array<string, int> */
    private array $snapshot = [];
    /** @var array<string, int> */
    private array $reserved = [];
    /** @var array<string, int> */
    private array $reservedSnapshot = [];
    /** @var array<int, array{product:int, warehouse:int, seq:int, code:string, expiry:?string, received:string, cost:string, quantity:int, opening:int, source:string, source_id:?int}> */
    private array $lots = [];
    /** @var array<int, array{product:int, warehouse:int, seq:int, code:string, expiry:?string, received:string, cost:string, quantity:int, opening:int, source:string, source_id:?int}> */
    private array $lotsSnapshot = [];
    /** @var array<int, bool> */
    private array $tracked = [];
    /** @var array<int, string> */
    private array $costs = [];
    /** @var list<string> */
    private array $lockedKeys = [];
    /** @var list<string> pairs whose lots were locked, in order */
    private array $lockedLotKeys = [];
    private bool $inTransaction = false;
    private int $clock = 0;

    /** @param string|null $today business date for the expiry rule (default: BusinessDate::today()) */
    public function __construct(private ?string $today = null)
    {
    }

    public function setToday(string $today): void
    {
        $this->today = $today;
    }

    public function beginTransaction(): void
    {
        $this->snapshot = $this->stocks;
        $this->reservedSnapshot = $this->reserved;
        $this->lotsSnapshot = $this->lots;
        $this->lockedKeys = [];
        $this->lockedLotKeys = [];
        $this->inTransaction = true;
    }

    public function commit(): void
    {
        $this->snapshot = [];
        $this->lotsSnapshot = [];
        $this->inTransaction = false;
    }

    public function rollBack(): void
    {
        if ($this->inTransaction) {
            $this->stocks = $this->snapshot;
            $this->reserved = $this->reservedSnapshot;
            $this->lots = $this->lotsSnapshot;
        }
        $this->snapshot = [];
        $this->inTransaction = false;
    }

    public function lockByProductWarehouse(int $productId, int $warehouseId): void
    {
        $this->lockedKeys[] = $warehouseId . ':' . $productId;
    }

    public function increment(int $productId, int $warehouseId, int $quantity): void
    {
        $key = $this->key($productId, $warehouseId);
        $this->stocks[$key] = ($this->stocks[$key] ?? 0) + $quantity;
    }

    public function decrement(int $productId, int $warehouseId, int $quantity): void
    {
        $key = $this->key($productId, $warehouseId);
        $this->stocks[$key] = ($this->stocks[$key] ?? 0) - $quantity;
        $this->assertRowConstraints($key);
    }

    public function reservedQuantity(int $productId, int $warehouseId): int
    {
        return $this->reserved[$this->key($productId, $warehouseId)] ?? 0;
    }

    public function reserve(int $productId, int $warehouseId, int $quantity): void
    {
        $key = $this->key($productId, $warehouseId);
        $this->reserved[$key] = ($this->reserved[$key] ?? 0) + $quantity;
        $this->assertRowConstraints($key);
    }

    public function release(int $productId, int $warehouseId, int $quantity): void
    {
        $key = $this->key($productId, $warehouseId);
        $this->reserved[$key] = ($this->reserved[$key] ?? 0) - $quantity;
        $this->assertRowConstraints($key);
    }

    public function issueReserved(int $productId, int $warehouseId, int $quantity): void
    {
        $key = $this->key($productId, $warehouseId);
        $this->stocks[$key] = ($this->stocks[$key] ?? 0) - $quantity;
        $this->reserved[$key] = ($this->reserved[$key] ?? 0) - $quantity;
        $this->assertRowConstraints($key);
    }

    /** Fake of the MySQL row constraints: unsigned columns and CHECK (reserved_quantity <= quantity). */
    private function assertRowConstraints(string $key): void
    {
        $quantity = $this->stocks[$key] ?? 0;
        $reserved = $this->reserved[$key] ?? 0;
        if ($quantity < 0 || $reserved < 0 || $reserved > $quantity) {
            throw new \LogicException('Stock row constraint violated: quantity=' . $quantity . ', reserved=' . $reserved . '.');
        }
    }

    public function quantity(int $productId, int $warehouseId): int
    {
        return $this->stocks[$this->key($productId, $warehouseId)] ?? 0;
    }

    public function expiredQuantity(int $productId, int $warehouseId): int
    {
        $sum = 0;
        foreach ($this->lotsOf($productId, $warehouseId) as $lot) {
            $sum += $lot->isExpired() ? $lot->quantity() : 0;
        }

        return $sum;
    }

    public function lockLots(int $productId, int $warehouseId): array
    {
        if (!in_array($warehouseId . ':' . $productId, $this->lockedKeys, true) && $this->inTransaction) {
            throw new \LogicException('Lots are locked only after their pair (ADR-023).');
        }
        $this->lockedLotKeys[] = $warehouseId . ':' . $productId;

        return $this->lotsOf($productId, $warehouseId);
    }

    public function createLot(int $productId, int $warehouseId, string $code, ?string $expiryDate, string $unitCost, string $sourceType, ?int $sourceId): StockLot
    {
        foreach ($this->lotsOf($productId, $warehouseId) as $lot) {
            if ($lot->matches($code, $expiryDate, $unitCost)) {
                throw new \LogicException('Duplicate lot identity (uq_stock_lots_identity).');
            }
        }
        $id = $this->lots === [] && $this->lotsSnapshot === [] ? 1 : max(array_keys($this->lots + $this->lotsSnapshot) ?: [0]) + 1;
        $this->lots[$id] = ['product' => $productId, 'warehouse' => $warehouseId, 'seq' => count($this->lotsOf($productId, $warehouseId)) + 1, 'code' => $code, 'expiry' => $expiryDate,
            'received' => sprintf('2026-01-01 00:00:%02d', min(59, ++$this->clock)) . '.' . sprintf('%06d', $this->clock), 'cost' => $unitCost, 'quantity' => 0, 'opening' => 0, 'source' => $sourceType, 'source_id' => $sourceId];

        return $this->hydrate($id);
    }

    public function changeLotQuantity(int $lotId, int $delta): void
    {
        if (!isset($this->lots[$lotId])) {
            throw new \RuntimeException('Stock lot is missing.');
        }
        $this->lots[$lotId]['quantity'] += $delta;
        if ($this->lots[$lotId]['quantity'] < 0) {
            throw new \LogicException('Lot quantity cannot be negative.');
        }
    }

    public function findLot(int $lotId): ?StockLot
    {
        return isset($this->lots[$lotId]) ? $this->hydrate($lotId) : null;
    }

    public function today(): string
    {
        return $this->today ?? BusinessDate::today();
    }

    public function tracksExpiry(int $productId): bool
    {
        return $this->tracked[$productId] ?? false;
    }

    public function purchaseCost(int $productId): string
    {
        return $this->costs[$productId] ?? '0.00';
    }

    /** Test setup: product flags used by the expiry policy and the default lot cost. */
    public function configureProduct(int $productId, bool $tracksExpiry, string $purchaseCost = '0.00'): void
    {
        $this->tracked[$productId] = $tracksExpiry;
        $this->costs[$productId] = $purchaseCost;
    }

    /** @return list<string> */
    public function lockedKeys(): array
    {
        return $this->lockedKeys;
    }

    /** @return list<string> */
    public function lockedLotKeys(): array
    {
        return $this->lockedLotKeys;
    }

    /** Test setup: sets the pair to $quantity held in one lot "SEED" without expiry (lots = aggregate). */
    public function seed(int $productId, int $warehouseId, int $quantity, int $reserved = 0): void
    {
        $this->stocks[$this->key($productId, $warehouseId)] = $quantity;
        $this->reserved[$this->key($productId, $warehouseId)] = $reserved;
        foreach ($this->lots as $id => $lot) {
            if ($lot['product'] === $productId && $lot['warehouse'] === $warehouseId) {
                unset($this->lots[$id]);
            }
        }
        if ($quantity > 0) {
            $this->seedLot($productId, $warehouseId, 'SEED', null, $quantity, '0.00', false);
        }
    }

    /** Test setup: adds a lot and (by default) its units to the pair. Returns the lot id. */
    public function seedLot(int $productId, int $warehouseId, string $code, ?string $expiryDate, int $quantity, string $unitCost = '0.00', bool $addToPair = true, ?string $receivedAt = null): int
    {
        $lot = $this->createLot($productId, $warehouseId, $code, $expiryDate, $unitCost, 'RECEIPT', null);
        $this->lots[$lot->id()]['quantity'] = $quantity;
        if ($receivedAt !== null) {
            $this->lots[$lot->id()]['received'] = $receivedAt;
        }
        if ($addToPair) {
            $key = $this->key($productId, $warehouseId);
            $this->stocks[$key] = ($this->stocks[$key] ?? 0) + $quantity;
        }

        return $lot->id();
    }

    /** @return list<StockLot> */
    public function lots(int $productId, int $warehouseId): array
    {
        return $this->lotsOf($productId, $warehouseId);
    }

    /** @return list<StockLot> */
    private function lotsOf(int $productId, int $warehouseId): array
    {
        $lots = [];
        foreach ($this->lots as $id => $lot) {
            if ($lot['product'] === $productId && $lot['warehouse'] === $warehouseId) {
                $lots[] = $this->hydrate($id);
            }
        }

        return StockLot::fefo($lots);
    }

    private function hydrate(int $id): StockLot
    {
        $lot = $this->lots[$id];
        $expired = $lot['expiry'] !== null && $lot['expiry'] < $this->today();

        return new StockLot($id, $lot['product'], $lot['warehouse'], $lot['seq'], $lot['code'], $lot['expiry'], $lot['received'], $lot['cost'], $lot['quantity'], $expired, $lot['opening'], $lot['source'], $lot['source_id']);
    }

    private function key(int $productId, int $warehouseId): string
    {
        return $warehouseId . ':' . $productId;
    }
}
