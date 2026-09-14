<?php

namespace Tests\Unit\Deployment;

use PHPUnit\Framework\TestCase;

/** 防止容器启动时重新遍历整个 Xboard 源码树。 */
class EntrypointPermissionsTest extends TestCase
{
    public function test_entrypoint_only_repairs_runtime_writable_paths(): void
    {
        $entrypoint = file_get_contents(dirname(__DIR__, 3) . '/.docker/entrypoint.sh');

        $this->assertDoesNotMatchRegularExpression(
            '/chown\s+-R\s+www:www\s+\/www(?:\s|$)/m',
            $entrypoint
        );

        foreach ([
            '/www/storage',
            '/www/bootstrap/cache',
            '/www/plugins',
            '/www/public/theme',
            '/www/public/plugins',
            '/www/.docker/.data',
        ] as $path) {
            $this->assertStringContainsString($path, $entrypoint);
        }

        $this->assertStringContainsString('chown -R www:www "$path"', $entrypoint);
        $this->assertStringNotContainsString(
            'chown -R www:www "$path" 2>/dev/null || true',
            $entrypoint
        );
    }
}
