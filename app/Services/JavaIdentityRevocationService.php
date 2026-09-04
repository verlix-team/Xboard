<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * Persists account-ban revocation requests for Hop Java identity.
 *
 * Java Flyway owns the java_identity_revocation_request schema. Xboard may
 * only insert ACCOUNT_BANNED requests inside the same transaction that changes
 * v2_user.banned; Java remains the only consumer and status writer.
 */
class JavaIdentityRevocationService
{
    private const TABLE = 'java_identity_revocation_request';

    public const SOURCE_ADMIN_SINGLE = 'XBOARD_ADMIN_SINGLE';

    public const SOURCE_ADMIN_BULK = 'XBOARD_ADMIN_BULK';

    /**
     * Persist one request for every distinct positive user id.
     *
     * The caller owns the surrounding ban transaction. A missing V009 table or
     * failed insert is intentionally allowed to abort that transaction so a ban
     * is never acknowledged without a durable physical-revocation request.
     */
    public function requestForUsers(array $userIds, string $source): int
    {
        $rows = self::requestRows($userIds, $source, time());
        if ($rows === []) {
            return 0;
        }

        DB::table(self::TABLE)->insert($rows);
        return count($rows);
    }

    /**
     * Build non-sensitive request rows for unit testing and bounded bulk inserts.
     */
    public static function requestRows(array $userIds, string $source, int $now): array
    {
        if (!in_array($source, [self::SOURCE_ADMIN_SINGLE, self::SOURCE_ADMIN_BULK], true)) {
            throw new InvalidArgumentException('Unsupported identity revocation source.');
        }

        $normalizedIds = array_values(array_unique(array_filter(
            array_map(static fn ($id) => is_numeric($id) ? (int) $id : 0, $userIds),
            static fn (int $id) => $id > 0
        )));

        return array_map(static fn (int $userId) => [
            'event_key' => Str::uuid()->toString(),
            'user_id' => $userId,
            'request_type' => 'ACCOUNT_BANNED',
            'request_source' => $source,
            'processing_status' => 'PENDING',
            'attempt_count' => 0,
            'next_attempt_at' => $now,
            'claim_token' => null,
            'claimed_at' => null,
            'completed_at' => null,
            'last_error_code' => null,
            'created_at' => $now,
            'updated_at' => $now,
        ], $normalizedIds);
    }
}
