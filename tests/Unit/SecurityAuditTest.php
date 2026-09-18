<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Security\Csrf;
use App\Security\NativeSessionManager;
use PHPUnit\Framework\TestCase;

final class SecurityAuditTest extends TestCase
{
    public function testSessionCookieOptionsAreHardened(): void
    {
        $options = NativeSessionManager::cookieOptions(false);

        self::assertTrue($options['httponly']);
        self::assertSame('Lax', $options['samesite']);
        self::assertFalse($options['secure']);
    }

    public function testCsrfTokenComparisonIsStrict(): void
    {
        self::assertTrue(Csrf::isValid('abc', 'abc'));
        self::assertFalse(Csrf::isValid('abc', 'abcd'));
    }

    public function testRepositoriesDoNotReadHttpSuperglobals(): void
    {
        foreach ($this->phpFiles(dirname(__DIR__, 2) . '/app/Repository') as $file) {
            $source = (string) file_get_contents($file);
            self::assertStringNotContainsString('$_GET', $source, $file);
            self::assertStringNotContainsString('$_POST', $source, $file);
            self::assertStringNotContainsString('$_REQUEST', $source, $file);
        }
    }

    public function testPostFormsCarryCsrfToken(): void
    {
        foreach ($this->phpFiles(dirname(__DIR__, 2) . '/views') as $file) {
            $source = (string) file_get_contents($file);
            preg_match_all('/<form method="post"[^>]*>.*?<\/form>/s', $source, $forms);

            foreach ($forms[0] as $form) {
                self::assertStringContainsString('name="csrf_token"', $form, $file);
            }
        }
    }

    /**
     * @return list<string>
     */
    private function phpFiles(string $directory): array
    {
        $files = [];
        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($directory));

        foreach ($iterator as $file) {
            if ($file instanceof \SplFileInfo && $file->isFile() && $file->getExtension() === 'php') {
                $files[] = $file->getPathname();
            }
        }

        return $files;
    }
}
