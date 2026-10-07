<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class WorkspaceLayoutTest extends TestCase
{
    public static function roles(): array
    {
        return [['Admin', true], ['Sales', false], ['WarehouseStaff', false]];
    }

    #[DataProvider('roles')]
    public function testInitialHtmlContainsCompleteRoleAwareLayout(string $role, bool $usersVisible): void
    {
        $previousRole = $GLOBALS['workspace_role'] ?? null;
        $previousUri = $_SERVER['REQUEST_URI'] ?? null;
        $GLOBALS['workspace_role'] = $role;
        $_SERVER['REQUEST_URI'] = '/products?page=2';
        $workspaceTitle = '<Products>';
        ob_start();
        try {
            require dirname(__DIR__, 2) . '/views/partials/workspace-start.php';
            $html = (string) ob_get_contents();
        } finally {
            ob_end_clean();
            if ($previousRole === null) unset($GLOBALS['workspace_role']);
            else $GLOBALS['workspace_role'] = $previousRole;
            if ($previousUri === null) unset($_SERVER['REQUEST_URI']);
            else $_SERVER['REQUEST_URI'] = $previousUri;
        }
        self::assertStringContainsString('class="app-shell"', $html);
        self::assertStringContainsString('class="sidebar-profile"', $html);
        self::assertStringContainsString('action="/logout"', $html);
        self::assertStringContainsString('href="/products" class="is-active" aria-current="page"', $html);
        self::assertStringContainsString('&lt;Products&gt;', $html);
        self::assertSame($usersVisible, str_contains($html, 'href="/users"'));
    }
}
