<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Security\SessionPolicy;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class SessionPolicyTest extends TestCase
{
    public function testIdleDeadlineIsInclusiveAndRotationDoesNotExtendAbsoluteDeadline(): void
    {
        $policy = new SessionPolicy(30, 100, 15);
        $metadata = ['created_at' => 100, 'last_seen_at' => 170, 'rotated_at' => 170];
        self::assertFalse($policy->isExpired($metadata, 199));
        self::assertTrue($policy->isExpired($metadata, 200));
        self::assertTrue($policy->shouldRotate($metadata, 185));
        self::assertFalse($policy->shouldRotate($metadata, 184));
        self::assertTrue($policy->isExpired(['created_at' => 100, 'last_seen_at' => 110, 'rotated_at' => 100], 140));
    }

    /** @return list<array{array<string, mixed>}> */
    public static function invalidMetadata(): array
    {
        return [[[]], [['created_at' => '1', 'last_seen_at' => 2, 'rotated_at' => 1]],
            [['created_at' => 2, 'last_seen_at' => 1, 'rotated_at' => 2]],
            [['created_at' => 1, 'last_seen_at' => 999, 'rotated_at' => 1]],
            [['created_at' => 1, 'last_seen_at' => 10, 'rotated_at' => 11]],
            [['created_at' => -1, 'last_seen_at' => 2, 'rotated_at' => 1]]];
    }

    #[DataProvider('invalidMetadata')]
    public function testMalformedMetadataFailsClosed(array $metadata): void
    {
        self::assertTrue((new SessionPolicy())->isExpired($metadata, 100));
    }

    public function testInvalidConfigurationIsRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new SessionPolicy(0);
    }
}
