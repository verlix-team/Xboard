<?php

namespace App\Services;

use App\Models\User;
use App\Models\Plan;
use App\Models\TrafficResetLog;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Cache;
use App\Services\Plugin\HookManager;

/**
 * 用户流量重置服务。
 */
class TrafficResetService
{
  /**
   * 检查用户当前是否满足自动重置条件，满足时执行重置。
   */
  public function checkAndReset(User $user, string $triggerSource = TrafficResetLog::SOURCE_AUTO): bool
  {
    return $this->resetWithLock($user, $triggerSource, true);
  }

  /**
   * 强制执行用户流量重置，不要求当前已经到达自动重置时间。
   */
  public function performReset(User $user, string $triggerSource = TrafficResetLog::SOURCE_MANUAL): bool
  {
    return $this->resetWithLock($user, $triggerSource, false);
  }

  /**
   * 修改共享流量状态前，重新读取并锁定数据库中的最新用户行。
   *
   * 自动重置调用方必须在取得行锁后重新检查到期条件。Xboard 扫描出候选用户后，
   * Java 订单事务可能已经先完成重置并推进 next_reset_at；如果继续使用扫描阶段的
   * 旧模型，就可能清除重置后新产生的流量，或者重复写入重置日志。
   */
  private function resetWithLock(User $candidate, string $triggerSource, bool $requireDue): bool
  {
    try {
      return DB::transaction(function () use ($candidate, $triggerSource, $requireDue) {
        $user = User::query()
          ->with('plan')
          ->whereKey($candidate->getKey())
          ->lockForUpdate()
          ->first();

        if (!$user || ($requireDue && !$user->shouldResetTraffic())) {
          return false;
        }

        $oldUpload = $user->u ?? 0;
        $oldDownload = $user->d ?? 0;
        $oldTotal = $oldUpload + $oldDownload;

        $nextResetTime = $this->calculateNextResetTime($user);

        $user->update([
          'u' => 0,
          'd' => 0,
          'last_reset_at' => time(),
          'reset_count' => $user->reset_count + 1,
          'next_reset_at' => $nextResetTime ? $nextResetTime->timestamp : null,
        ]);

        $this->recordResetLog($user, [
          'reset_type' => $this->getResetTypeFromPlan($user->plan),
          'trigger_source' => $triggerSource,
          'old_upload' => $oldUpload,
          'old_download' => $oldDownload,
          'old_total' => $oldTotal,
          'new_upload' => 0,
          'new_download' => 0,
          'new_total' => 0,
        ]);

        $this->clearUserCache($user);
        HookManager::call('traffic.reset.after', $user);
        return true;
      });
    } catch (\Exception $e) {
      Log::error(__('traffic_reset.reset_failed'), [
        'user_id' => $candidate->id,
        'email' => $candidate->email,
        'error' => $e->getMessage(),
        'trigger_source' => $triggerSource,
      ]);

      return false;
    }
  }

  /**
   * 根据用户套餐和到期时间计算下一次流量重置时间。
   */
  public function calculateNextResetTime(User $user): ?Carbon
  {
    if (
      !$user->plan
      || $user->plan->reset_traffic_method === Plan::RESET_TRAFFIC_NEVER
      || ($user->plan->reset_traffic_method === Plan::RESET_TRAFFIC_FOLLOW_SYSTEM
        && (int) admin_setting('reset_traffic_method', Plan::RESET_TRAFFIC_MONTHLY) === Plan::RESET_TRAFFIC_NEVER)
      || $user->expired_at === NULL
    ) {
      return null;
    }

    $resetMethod = $user->plan->reset_traffic_method;

    if ($resetMethod === Plan::RESET_TRAFFIC_FOLLOW_SYSTEM) {
      $resetMethod = (int) admin_setting('reset_traffic_method', Plan::RESET_TRAFFIC_MONTHLY);
    }

    $now = Carbon::now(config('app.timezone'));

    return match ($resetMethod) {
      Plan::RESET_TRAFFIC_FIRST_DAY_MONTH => $this->getNextMonthFirstDay($now),
      Plan::RESET_TRAFFIC_MONTHLY => $this->getNextMonthlyReset($user, $now),
      Plan::RESET_TRAFFIC_FIRST_DAY_YEAR => $this->getNextYearFirstDay($now),
      Plan::RESET_TRAFFIC_YEARLY => $this->getNextYearlyReset($user, $now),
      default => null,
    };
  }

  /**
   * 取得下个月第一天。
   */
  private function getNextMonthFirstDay(Carbon $from): Carbon
  {
    return $from->copy()->addMonth()->startOfMonth();
  }

  /**
   * 根据用户到期日计算下一次按月重置时间。
   *
   * 规则：
   * 1. 用户没有到期时间时，每月 1 日重置。
   * 2. 用户存在到期时间时，以到期日中的“日”作为每月重置日。
   * 3. 本月重置时刻尚未经过时，优先使用本月日期。
   * 4. 目标月份不存在该日期时（例如 2 月 31 日），使用当月最后一天。
   */
  private function getNextMonthlyReset(User $user, Carbon $from): Carbon
  {
    $expiredAt = Carbon::createFromTimestamp($user->expired_at, config('app.timezone'));
    $resetDay = $expiredAt->day;
    $resetTime = [$expiredAt->hour, $expiredAt->minute, $expiredAt->second];
    
    $currentMonthTarget = $from->copy()->day($resetDay)->setTime(...$resetTime);
    if ($currentMonthTarget->timestamp > $from->timestamp) {
      return $currentMonthTarget;
    }
    
    $nextMonthTarget = $from->copy()->startOfMonth()->addMonths(1)->day($resetDay)->setTime(...$resetTime);
    
    if ($nextMonthTarget->month !== ($from->month % 12) + 1) {
      $nextMonth = ($from->month % 12) + 1;
      $nextYear = $from->year + ($from->month === 12 ? 1 : 0);
      $lastDayOfNextMonth = Carbon::create($nextYear, $nextMonth, 1)->endOfMonth()->day;
      $targetDay = min($resetDay, $lastDayOfNextMonth);
      $nextMonthTarget = Carbon::create($nextYear, $nextMonth, $targetDay)->setTime(...$resetTime);
    }
    
    return $nextMonthTarget;
  }

  /**
   * 取得下一年的第一天。
   */
  private function getNextYearFirstDay(Carbon $from): Carbon
  {
    return $from->copy()->addYear()->startOfYear();
  }

  /**
   * 根据用户到期日计算下一次按年重置时间。
   *
   * 规则：
   * 1. 用户没有到期时间时，每年 1 月 1 日重置。
   * 2. 用户存在到期时间时，以到期日中的月和日作为每年重置日期。
   * 3. 本年重置时刻尚未经过时，优先使用本年日期。
   * 4. 处理闰年 2 月 29 日在非闰年中不存在的情况。
   */
  private function getNextYearlyReset(User $user, Carbon $from): Carbon
  {
    $expiredAt = Carbon::createFromTimestamp($user->expired_at, config('app.timezone'));
    $resetMonth = $expiredAt->month;
    $resetDay = $expiredAt->day;
    $resetTime = [$expiredAt->hour, $expiredAt->minute, $expiredAt->second];

    $currentYearTarget = $from->copy()->month($resetMonth)->day($resetDay)->setTime(...$resetTime);
    if ($currentYearTarget->timestamp > $from->timestamp) {
      return $currentYearTarget;
    }
    
    $nextYearTarget = $from->copy()->startOfYear()->addYears(1)->month($resetMonth)->day($resetDay)->setTime(...$resetTime);
    
    if ($nextYearTarget->month !== $resetMonth) {
      $nextYear = $from->year + 1;
      $lastDayOfMonth = Carbon::create($nextYear, $resetMonth, 1)->endOfMonth()->day;
      $targetDay = min($resetDay, $lastDayOfMonth);
      $nextYearTarget = Carbon::create($nextYear, $resetMonth, $targetDay)->setTime(...$resetTime);
    }
    
    return $nextYearTarget;
  }


  /**
   * 记录流量重置审计日志。
   */
  private function recordResetLog(User $user, array $data): void
  {
    TrafficResetLog::create([
      'user_id' => $user->id,
      'reset_type' => $data['reset_type'],
      'reset_time' => now(),
      'old_upload' => $data['old_upload'],
      'old_download' => $data['old_download'],
      'old_total' => $data['old_total'],
      'new_upload' => $data['new_upload'],
      'new_download' => $data['new_download'],
      'new_total' => $data['new_total'],
      'trigger_source' => $data['trigger_source'],
      'metadata' => $data['metadata'] ?? null,
    ]);
  }

  /**
   * 根据用户套餐取得重置类型。
   */
  private function getResetTypeFromPlan(?Plan $plan): string
  {
    if (!$plan) {
      return TrafficResetLog::TYPE_MANUAL;
    }

    $resetMethod = $plan->reset_traffic_method;

    if ($resetMethod === Plan::RESET_TRAFFIC_FOLLOW_SYSTEM) {
      $resetMethod = (int) admin_setting('reset_traffic_method', Plan::RESET_TRAFFIC_MONTHLY);
    }

    return match ($resetMethod) {
      Plan::RESET_TRAFFIC_FIRST_DAY_MONTH => TrafficResetLog::TYPE_FIRST_DAY_MONTH,
      Plan::RESET_TRAFFIC_MONTHLY => TrafficResetLog::TYPE_MONTHLY,
      Plan::RESET_TRAFFIC_FIRST_DAY_YEAR => TrafficResetLog::TYPE_FIRST_DAY_YEAR,
      Plan::RESET_TRAFFIC_YEARLY => TrafficResetLog::TYPE_YEARLY,
      Plan::RESET_TRAFFIC_NEVER => TrafficResetLog::TYPE_MANUAL,
      default => TrafficResetLog::TYPE_MANUAL,
    };
  }

  /**
   * 清除与用户流量、重置状态和订阅信息有关的缓存。
   */
  private function clearUserCache(User $user): void
  {
    $cacheKeys = [
      "user_traffic_{$user->id}",
      "user_reset_status_{$user->id}",
      "user_subscription_{$user->token}",
    ];

    foreach ($cacheKeys as $key) {
      Cache::forget($key);
    }
  }

  /**
   * 分批检查并重置全部满足条件的用户。
   */
  public function batchCheckReset(int $batchSize = 100, ?callable $progressCallback = null): array
  {
    $startTime = microtime(true);
    $totalResetCount = 0;
    $totalProcessedCount = 0;
    $batchNumber = 1;
    $errors = [];
    $lastProcessedId = 0;

    try {
      do {
        $users = User::where('next_reset_at', '<=', time())
          ->whereNotNull('next_reset_at')
          ->where('id', '>', $lastProcessedId)
          ->where(function ($query) {
            $query->where('expired_at', '>', time())
              ->orWhereNull('expired_at');
          })
          ->where('banned', 0)
          ->whereNotNull('plan_id')
          ->orderBy('id')
          ->limit($batchSize)
          ->get();

        if ($users->isEmpty()) {
          break;
        }

        $batchResetCount = 0;

        if ($progressCallback) {
          $progressCallback([
            'batch_number' => $batchNumber,
            'batch_size' => $users->count(),
            'total_processed' => $totalProcessedCount,
          ]);
        }

        foreach ($users as $user) {
          try {
            if ($this->checkAndReset($user, TrafficResetLog::SOURCE_CRON)) {
              $batchResetCount++;
              $totalResetCount++;
            }
            $totalProcessedCount++;
            $lastProcessedId = $user->id;
          } catch (\Exception $e) {
            $error = [
              'user_id' => $user->id,
              'email' => $user->email,
              'error' => $e->getMessage(),
              'batch' => $batchNumber,
              'timestamp' => now()->toDateTimeString(),
            ];
            $batchErrors[] = $error;
            $errors[] = $error;

            Log::error('User traffic reset failed', $error);

            $totalProcessedCount++;
            $lastProcessedId = $user->id;
          }
        }

        $batchNumber++;

        if ($batchNumber % 10 === 0) {
          gc_collect_cycles();
        }

        if ($batchNumber % 5 === 0) {
          usleep(100000);
        }

      } while (true);

    } catch (\Exception $e) {
      Log::error('Batch traffic reset task failed with an exception', [
        'error' => $e->getMessage(),
        'trace' => $e->getTraceAsString(),
        'total_processed' => $totalProcessedCount,
        'total_reset' => $totalResetCount,
        'last_processed_id' => $lastProcessedId,
      ]);

      $errors[] = [
        'type' => 'system_error',
        'error' => $e->getMessage(),
        'batch' => $batchNumber,
        'last_processed_id' => $lastProcessedId,
        'timestamp' => now()->toDateTimeString(),
      ];
    }

    $totalDuration = round(microtime(true) - $startTime, 2);

    $result = [
      'total_processed' => $totalProcessedCount,
      'total_reset' => $totalResetCount,
      'total_batches' => $batchNumber - 1,
      'error_count' => count($errors),
      'errors' => $errors,
      'duration' => $totalDuration,
      'batch_size' => $batchSize,
      'last_processed_id' => $lastProcessedId,
      'completed_at' => now()->toDateTimeString(),
    ];

    return $result;
  }

  /**
   * 为尚未设置重置时间的新用户计算初始重置时间。
   */
  public function setInitialResetTime(User $user): void
  {
    if ($user->next_reset_at !== null) {
      return;
    }

    $nextResetTime = $this->calculateNextResetTime($user);

    if ($nextResetTime) {
      $user->update(['next_reset_at' => $nextResetTime->timestamp]);
    }
  }

  /**
   * 查询用户最近的流量重置历史。
   */
  public function getUserResetHistory(User $user, int $limit = 10): \Illuminate\Database\Eloquent\Collection
  {
    return $user->trafficResetLogs()
      ->orderBy('reset_time', 'desc')
      ->limit($limit)
      ->get();
  }

  /**
   * 检查用户是否具备流量重置资格。
   */
  public function canReset(User $user): bool
  {
    return $user->isActive() && $user->plan !== null;
  }

  /**
   * 由管理端手工重置用户流量。
   */
  public function manualReset(User $user, array $metadata = []): bool
  {
    if (!$this->canReset($user)) {
      return false;
    }

    return $this->performReset($user, TrafficResetLog::SOURCE_MANUAL);
  }
}
