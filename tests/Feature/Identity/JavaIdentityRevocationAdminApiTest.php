<?php

namespace Tests\Feature\Identity;

use App\Models\User;
use App\Services\JavaIdentityRevocationService;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Testing\TestCase as LaravelTestCase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Real Xboard admin HTTP-kernel acceptance for durable Java identity revocation requests.
 *
 * This suite is opt-in because it writes preserved test_identity_revocation_admin_ data
 * to the configured shared database. It never refreshes, rolls back, or truncates data.
 */
class JavaIdentityRevocationAdminApiTest extends LaravelTestCase
{
    private string $adminBearerToken;

    private string $securePath;

    protected function setUp(): void
    {
        if (getenv('RUN_LOCAL_XBOARD_IDENTITY_REVOCATION') !== 'true') {
            self::markTestSkipped('Shared Xboard identity revocation acceptance is opt-in.');
        }
        parent::setUp();
        $this->securePath = admin_setting(
            'secure_path',
            admin_setting('frontend_admin_path', hash('crc32b', config('app.key')))
        );
        $adminId = $this->insertUser('admin', true);
        $admin = User::query()->findOrFail($adminId);
        $this->adminBearerToken = $admin->createToken('test_identity_revocation_admin')->plainTextToken;
    }

    /** Bootstrap the real Xboard application without refreshing or replacing its database. */
    public function createApplication(): Application
    {
        $app = require dirname(__DIR__, 3) . '/bootstrap/app.php';
        $app->make(Kernel::class)->bootstrap();
        return $app;
    }

    /** Single save and selected bulk ban persist PENDING requests without deleting sessions. */
    public function test_single_and_bulk_admin_ban_persist_requests_while_consumer_is_disabled(): void
    {
        $singleUserId = $this->insertUser('single');
        [$singleSessionId, $singleRefreshId] = $this->insertSessionAndRefresh($singleUserId, 'single');

        $singleResponse = $this->postAdmin('user/update', [
            'id' => $singleUserId,
            'banned' => 1,
        ]);

        $this->assertSame(200, $singleResponse->getStatusCode());
        $this->assertSame(1, (int) DB::table('v2_user')->where('id', $singleUserId)->value('banned'));
        $this->assertSame('PENDING', DB::table('java_identity_revocation_request')
            ->where('user_id', $singleUserId)
            ->where('request_source', JavaIdentityRevocationService::SOURCE_ADMIN_SINGLE)
            ->latest('id')
            ->value('processing_status'));
        $this->assertTrue(DB::table('personal_access_tokens')->where('id', $singleSessionId)->exists());
        $this->assertSame('ACTIVE', DB::table('java_refresh_token')->where('id', $singleRefreshId)->value('status'));

        $firstBulkUserId = $this->insertUser('bulk_first');
        $secondBulkUserId = $this->insertUser('bulk_second');
        [$firstBulkSessionId, $firstBulkRefreshId] = $this->insertSessionAndRefresh($firstBulkUserId, 'bulk_first');
        [$secondBulkSessionId, $secondBulkRefreshId] = $this->insertSessionAndRefresh($secondBulkUserId, 'bulk_second');

        $bulkResponse = $this->postAdmin('user/ban', [
            'scope' => 'selected',
            'user_ids' => [$firstBulkUserId, $secondBulkUserId],
        ]);

        $this->assertSame(200, $bulkResponse->getStatusCode());
        $this->assertSame(2, DB::table('v2_user')
            ->whereIn('id', [$firstBulkUserId, $secondBulkUserId])
            ->where('banned', 1)
            ->count());
        $this->assertSame(2, DB::table('java_identity_revocation_request')
            ->whereIn('user_id', [$firstBulkUserId, $secondBulkUserId])
            ->where('request_source', JavaIdentityRevocationService::SOURCE_ADMIN_BULK)
            ->where('processing_status', 'PENDING')
            ->count());
        $this->assertSame(2, DB::table('personal_access_tokens')
            ->whereIn('id', [$firstBulkSessionId, $secondBulkSessionId])
            ->count());
        $this->assertSame(2, DB::table('java_refresh_token')
            ->whereIn('id', [$firstBulkRefreshId, $secondBulkRefreshId])
            ->where('status', 'ACTIVE')
            ->count());

        fwrite(STDOUT, sprintf(
            "Preserved Xboard identity revocation admin/users: %d/%d,%d,%d\n",
            $this->adminUserId(),
            $singleUserId,
            $firstBulkUserId,
            $secondBulkUserId
        ));
    }

    /** A producer failure rolls back banned=1 instead of acknowledging an unsafe partial ban. */
    public function test_single_ban_rolls_back_when_durable_request_cannot_be_written(): void
    {
        $userId = $this->insertUser('rollback');
        $this->app->instance(JavaIdentityRevocationService::class, new class extends JavaIdentityRevocationService {
            public function requestForUsers(array $userIds, string $source): int
            {
                throw new RuntimeException('simulated durable request failure');
            }
        });

        $response = $this->postAdmin('user/update', [
            'id' => $userId,
            'banned' => 1,
        ]);

        $this->assertSame(0, (int) DB::table('v2_user')->where('id', $userId)->value('banned'));
        $this->assertFalse(DB::table('java_identity_revocation_request')->where('user_id', $userId)->exists());
        $this->assertSame('保存失败', $response->json('message'));
    }

    /** Dispatch one authenticated request through the real Xboard admin middleware and controller. */
    private function postAdmin(string $path, array $payload): mixed
    {
        return $this->withHeaders([
            'Authorization' => 'Bearer ' . $this->adminBearerToken,
            'Accept' => 'application/json',
        ])->postJson('/api/v2/' . $this->securePath . '/' . $path, $payload);
    }

    /** Insert a preserved minimal Xboard user without firing model observers or external jobs. */
    private function insertUser(string $label, bool $admin = false): int
    {
        $suffix = str_replace('.', '', uniqid('', true));
        $now = time();
        return (int) DB::table('v2_user')->insertGetId([
            'email' => 'test_idrev_admin_' . $label . '_' . substr($suffix, -12) . '@example.com',
            'password' => password_hash(Str::random(40), PASSWORD_DEFAULT),
            'uuid' => Str::uuid()->toString(),
            'token' => Str::random(32),
            'banned' => 0,
            'is_admin' => $admin ? 1 : 0,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

    /** Insert one Java-shaped Xboard session and one ACTIVE refresh-token digest row. */
    private function insertSessionAndRefresh(int $userId, string $label): array
    {
        $now = now();
        $sessionId = (int) DB::table('personal_access_tokens')->insertGetId([
            'tokenable_type' => User::class,
            'tokenable_id' => $userId,
            'name' => 'test_identity_revocation_admin_' . $label,
            'token' => hash('sha256', Str::uuid()->toString()),
            'abilities' => '["java-session"]',
            'expires_at' => $now->copy()->addHour(),
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        $refreshId = (int) DB::table('java_refresh_token')->insertGetId([
            'personal_access_token_id' => $sessionId,
            'token_family_id' => Str::uuid()->toString(),
            'token_hash' => hash('sha256', Str::uuid()->toString()),
            'status' => 'ACTIVE',
            'expires_at' => $now->copy()->addHour(),
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        return [$sessionId, $refreshId];
    }

    /** Resolve the current test admin without exposing its bearer token. */
    private function adminUserId(): int
    {
        $tokenId = (int) explode('|', $this->adminBearerToken, 2)[0];
        return (int) DB::table('personal_access_tokens')->where('id', $tokenId)->value('tokenable_id');
    }
}
