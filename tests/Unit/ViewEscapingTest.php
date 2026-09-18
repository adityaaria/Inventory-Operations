<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Support\Html;
use PHPUnit\Framework\TestCase;

final class ViewEscapingTest extends TestCase
{
    public function testHtmlHelperEscapesQuotesAndTags(): void
    {
        self::assertSame('&lt;script&gt;&quot;x&quot;&lt;/script&gt;', Html::e('<script>"x"</script>'));
    }
}
