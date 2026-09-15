<?php

namespace Tests\Unit\Services;

use App\Http\Middleware\VerifyJavaNodeSync;
use PHPUnit\Framework\TestCase;

class JavaNodeSyncImmediateAuthTest extends TestCase
{
    public function test_signature_matches_java_raw_body_contract(): void
    {
        $this->assertSame(
            '18e8f363100f0e57ca4b46411668896b59917a591a1455e691b10d8c9741a0ab',
            VerifyJavaNodeSync::signature(
                'test-secret-12345678901234567890',
                '1700000000',
                '{"eventKey":"subscription-order:37"}'
            )
        );
    }
}
