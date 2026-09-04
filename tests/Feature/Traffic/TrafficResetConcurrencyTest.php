<?php

namespace Tests\Feature\Traffic;

use App\Models\TrafficResetLog;
use App\Models\User;
use App\Services\TrafficResetService;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Testing\TestCase as LaravelTestCase;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

/**
 * 使用真实 MySQL 验证 Xboard 定时重置与 Hop Java 订单重置的并发行为。
 *
 * 本测试默认不运行，因为它会在配置的共享数据库中永久保留 test_ 套餐、用户、
 * 重置日志和 Java 幂等记录；测试不会刷新、回滚、截断或清理既有数据。
 */
class TrafficResetConcurrencyTest extends LaravelTestCase
{
    /** 启动真实 Xboard 应用，并保持使用当前配置的数据库。 */
    public function createApplication(): Application
    {
        $app = require dirname(__DIR__, 3) . '/bootstrap/app.php';
        $app->make(Kernel::class)->bootstrap();
        return $app;
    }

    protected function setUp(): void
    {
        if (getenv('RUN_LOCAL_XBOARD_TRAFFIC_RESET_CONCURRENCY') !== 'true') {
            self::markTestSkipped('共享数据库的 Xboard 流量重置并发验收需要显式启用。');
        }
        if (!function_exists('pcntl_fork') || !function_exists('stream_socket_pair')) {
            self::markTestSkipped('当前环境必须提供 pcntl 和 Unix Socket 函数。');
        }
        parent::setUp();
    }

    /** Java 先取得用户行锁并完成订单重置后，Xboard 的旧定时候选不得再次重置。 */
    public function test_cron_rechecks_due_state_after_competing_java_order_reset(): void
    {
        $suffix = str_replace('.', '', uniqid('', true));
        $now = time();
        $futureResetAt = $now + 30 * 86400;
        $planId = $this->insertPlan('test_traffic_lock_plan_' . $suffix, $now);
        $userId = $this->insertDueUser($planId, 'test_traffic_lock_' . $suffix . '@example.com', $now);
        $operationKey = 'test-order-traffic-lock-' . $suffix;

        // 使用本地 Socket 协调父子进程，确保竞争发生在可验证的数据库锁窗口内。
        [$parentSocket, $childSocket] = stream_socket_pair(
            STREAM_PF_UNIX,
            STREAM_SOCK_STREAM,
            STREAM_IPPROTO_IP
        );
        DB::disconnect();
        $childPid = pcntl_fork();
        if ($childPid === -1) {
            throw new RuntimeException('无法创建模拟 Xboard 重置竞争者的子进程。');
        }

        if ($childPid === 0) {
            fclose($parentSocket);
            $this->runCronCompetitor($childSocket, $userId);
        }

        fclose($childSocket);
        $status = 0;
        $waitedEarly = -1;
        try {
            $this->assertSame('READY', trim((string) fgets($parentSocket)));
            DB::purge();
            DB::reconnect();
            DB::beginTransaction();

            // 父进程模拟 Java 订单事务：先锁用户，再在同一事务内完成重置和幂等记录。
            $locked = DB::table('v2_user')->where('id', $userId)->lockForUpdate()->first();
            $this->assertNotNull($locked);
            DB::table('v2_user')->where('id', $userId)->update([
                'u' => 0,
                'd' => 0,
                'last_reset_at' => $now,
                'reset_count' => (int) $locked->reset_count + 1,
                'next_reset_at' => $futureResetAt,
                'updated_at' => $now,
            ]);
            $resetLogId = DB::table('v2_traffic_reset_logs')->insertGetId([
                'user_id' => $userId,
                'reset_type' => TrafficResetLog::TYPE_PURCHASE,
                'reset_time' => now(),
                'old_upload' => 100,
                'old_download' => 50,
                'old_total' => 150,
                'new_upload' => 0,
                'new_download' => 0,
                'new_total' => 0,
                'trigger_source' => TrafficResetLog::SOURCE_ORDER,
                'metadata' => json_encode(['test' => 'java-order-race']),
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            DB::table('java_traffic_reset_operation')->insert([
                'operation_key' => $operationKey,
                'user_id' => $userId,
                'reset_log_id' => $resetLogId,
                'reset_type' => 'PURCHASE',
                'trigger_source' => 'ORDER',
                'source_reference_id' => $userId,
                'due_at' => null,
                'old_upload_bytes' => 100,
                'old_download_bytes' => 50,
                'reset_at' => $now,
                'next_reset_at' => $futureResetAt,
                'created_at' => $now,
            ]);

            // 模拟 Java 重置后、Xboard 旧定时候选取得用户锁前，节点新上报的流量；
            // 锁后复核必须保留这些新流量，不能再次将其清零。
            DB::table('v2_user')->where('id', $userId)->incrementEach(['u' => 20, 'd' => 5]);

            fwrite($parentSocket, "START\n");
            fflush($parentSocket);
            $this->assertSame('ATTEMPT', trim((string) fgets($parentSocket)));
            usleep(100000);
            // 提交前子进程必须仍在等待同一用户行锁，不能提前完成第二次重置。
            $waitedEarly = pcntl_waitpid($childPid, $status, WNOHANG);

            // Java 形态事务提交后释放行锁，Xboard 才能读取最新状态并复核到期条件。
            DB::commit();
            if ($waitedEarly === 0) {
                pcntl_waitpid($childPid, $status);
            }
        } catch (Throwable $exception) {
            if (DB::transactionLevel() > 0) {
                DB::rollBack();
            }
            fwrite($parentSocket, "START\n");
            pcntl_waitpid($childPid, $status);
            fclose($parentSocket);
            throw $exception;
        }

        $childOutput = trim((string) stream_get_contents($parentSocket));
        fclose($parentSocket);
        $this->assertSame(0, $waitedEarly, 'Xboard 没有等待共享用户行锁。');
        $this->assertTrue(pcntl_wifexited($status), $childOutput);
        $this->assertSame(0, pcntl_wexitstatus($status), $childOutput);

        DB::purge();
        DB::reconnect();
        $user = DB::table('v2_user')->where('id', $userId)->first();
        $this->assertSame(20, (int) $user->u);
        $this->assertSame(5, (int) $user->d);
        $this->assertSame(1, (int) $user->reset_count);
        $this->assertSame($futureResetAt, (int) $user->next_reset_at);
        $this->assertSame(1, DB::table('v2_traffic_reset_logs')
            ->where('user_id', $userId)
            ->where('trigger_source', TrafficResetLog::SOURCE_ORDER)
            ->count());
        $this->assertSame(0, DB::table('v2_traffic_reset_logs')
            ->where('user_id', $userId)
            ->where('trigger_source', TrafficResetLog::SOURCE_CRON)
            ->count());
        $this->assertSame(1, DB::table('java_traffic_reset_operation')
            ->where('operation_key', $operationKey)
            ->count());

        fwrite(STDOUT, "已保留流量重置并发测试套餐/用户：{$planId}/{$userId}\n");
    }

    /** 显式订单重置保持强制语义，但必须使用取得行锁后的最新流量计数。 */
    public function test_forced_order_reset_uses_latest_locked_traffic_state(): void
    {
        $suffix = str_replace('.', '', uniqid('', true));
        $now = time();
        $planId = $this->insertPlan('test_traffic_lock_forced_plan_' . $suffix, $now);
        $userId = $this->insertDueUser(
            $planId,
            'test_traffic_lock_forced_' . $suffix . '@example.com',
            $now
        );
        DB::table('v2_user')->where('id', $userId)->update([
            'u' => 30,
            'd' => 10,
            'reset_count' => 1,
            'next_reset_at' => $now + 86400,
        ]);
        $staleCandidate = User::query()->findOrFail($userId);
        DB::table('v2_user')->where('id', $userId)->update([
            'u' => 40,
            'd' => 20,
            'reset_count' => 2,
        ]);

        $reset = App::make(TrafficResetService::class)
            ->performReset($staleCandidate, TrafficResetLog::SOURCE_ORDER);

        $this->assertTrue($reset);
        $user = DB::table('v2_user')->where('id', $userId)->first();
        $this->assertSame(0, (int) $user->u);
        $this->assertSame(0, (int) $user->d);
        $this->assertSame(3, (int) $user->reset_count);
        $log = DB::table('v2_traffic_reset_logs')
            ->where('user_id', $userId)
            ->where('trigger_source', TrafficResetLog::SOURCE_ORDER)
            ->latest('id')
            ->first();
        $this->assertNotNull($log);
        $this->assertSame(40, (int) $log->old_upload);
        $this->assertSame(20, (int) $log->old_download);

        fwrite(STDOUT, "已保留强制流量重置测试套餐/用户：{$planId}/{$userId}\n");
    }

    /** 在独立进程中使用旧候选模型运行 Xboard 自动重置，形成真实数据库锁竞争。 */
    private function runCronCompetitor($socket, int $userId): never
    {
        try {
            DB::purge();
            DB::reconnect();
            App::instance('hook.actions', []);
            $candidate = User::query()->findOrFail($userId);
            fwrite($socket, "READY\n");
            fflush($socket);
            if (trim((string) fgets($socket)) !== 'START') {
                throw new RuntimeException('父进程没有释放定时重置竞争者。');
            }
            fwrite($socket, "ATTEMPT\n");
            fflush($socket);
            $reset = App::make(TrafficResetService::class)
                ->checkAndReset($candidate, TrafficResetLog::SOURCE_CRON);
            fwrite($socket, $reset ? "UNEXPECTED_RESET\n" : "SKIPPED_AFTER_RECHECK\n");
            fclose($socket);
            exit($reset ? 2 : 0);
        } catch (Throwable $exception) {
            fwrite($socket, 'ERROR ' . $exception::class . ': ' . $exception->getMessage() . "\n");
            fclose($socket);
            exit(3);
        }
    }

    /** 插入一个按月重置套餐，作为需要永久保留的测试数据。 */
    private function insertPlan(string $name, int $now): int
    {
        return (int) DB::table('v2_plan')->insertGetId([
            'group_id' => 1,
            'transfer_enable' => 20,
            'name' => $name,
            'prices' => json_encode(['monthly' => 10, 'reset_traffic' => 1]),
            'sell' => 1,
            'show' => 1,
            'renew' => 1,
            'reset_traffic_method' => 0,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

    /** 插入一个 Xboard 定时扫描会判定为到期的有效用户。 */
    private function insertDueUser(int $planId, string $email, int $now): int
    {
        return (int) DB::table('v2_user')->insertGetId([
            'email' => $email,
            'password' => password_hash(Str::random(40), PASSWORD_DEFAULT),
            'uuid' => Str::uuid()->toString(),
            'token' => Str::random(32),
            'plan_id' => $planId,
            'group_id' => 1,
            'u' => 100,
            'd' => 50,
            'transfer_enable' => 20 * 1073741824,
            'banned' => 0,
            'expired_at' => $now + 90 * 86400,
            'next_reset_at' => $now - 60,
            'last_reset_at' => null,
            'reset_count' => 0,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }
}
