<?php
declare(strict_types=1);
namespace App\Repository\Contract;
interface OperationalHealthRepositoryInterface
{
    public function assertReady(): void;
    /** @return array<string, array{rows:int, sha256:string}> */
    public function fingerprint(): array;
}
