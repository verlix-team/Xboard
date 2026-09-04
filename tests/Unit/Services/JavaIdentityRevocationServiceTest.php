<?php

namespace Tests\Unit\Services;

use App\Services\JavaIdentityRevocationService;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

class JavaIdentityRevocationServiceTest extends TestCase
{
    public function test_single_source_builds_pending_non_sensitive_request(): void
    {
        $rows = JavaIdentityRevocationService::requestRows(
            [42],
            JavaIdentityRevocationService::SOURCE_ADMIN_SINGLE,
            1_700_000_000
        );

        $this->assertCount(1, $rows);
        $this->assertSame(42, $rows[0]['user_id']);
        $this->assertSame('ACCOUNT_BANNED', $rows[0]['request_type']);
        $this->assertSame('XBOARD_ADMIN_SINGLE', $rows[0]['request_source']);
        $this->assertSame('PENDING', $rows[0]['processing_status']);
        $this->assertSame(1_700_000_000, $rows[0]['next_attempt_at']);
        $this->assertMatchesRegularExpression(
            '/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/',
            $rows[0]['event_key']
        );
        $this->assertSame([
            'event_key', 'user_id', 'request_type', 'request_source', 'processing_status',
            'attempt_count', 'next_attempt_at', 'claim_token', 'claimed_at', 'completed_at',
            'last_error_code', 'created_at', 'updated_at',
        ], array_keys($rows[0]));
    }

    public function test_bulk_source_deduplicates_and_rejects_invalid_user_ids(): void
    {
        $rows = JavaIdentityRevocationService::requestRows(
            [7, '7', 8, 0, -1, 'invalid'],
            JavaIdentityRevocationService::SOURCE_ADMIN_BULK,
            1_700_000_001
        );

        $this->assertSame([7, 8], array_column($rows, 'user_id'));
        $this->assertCount(2, array_unique(array_column($rows, 'event_key')));
        $this->assertSame(
            ['XBOARD_ADMIN_BULK', 'XBOARD_ADMIN_BULK'],
            array_column($rows, 'request_source')
        );
    }

    public function test_unknown_source_is_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        JavaIdentityRevocationService::requestRows([42], 'UNTRUSTED_SOURCE', 1_700_000_002);
    }
}
