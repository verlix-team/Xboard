<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/** PHP 与 Java 共享的持久处理权门禁；任何读取异常都失败关闭。 */
class ProcessingAuthorityService
{
    public const OWNER = 'XBOARD';

    /** 扫描前取得许可；许可必须携带到真正执行阶段复核。 */
    public function scanPermit(string $taskCode): ?array
    {
        $snapshot = $this->snapshot($taskCode);
        if (!$snapshot || $snapshot->scan_owner !== self::OWNER) {
            return null;
        }

        return [
            'task_code' => $taskCode,
            'owner' => self::OWNER,
            'authority_epoch' => (int) $snapshot->authority_epoch,
        ];
    }

    /** 已持久入队任务在执行副作用前复核唯一执行方与 epoch。 */
    public function executionAllowed(?array $permit): bool
    {
        if (!$permit
            || ($permit['owner'] ?? null) !== self::OWNER
            || !isset($permit['task_code'], $permit['authority_epoch'])) {
            return false;
        }

        $snapshot = $this->snapshot((string) $permit['task_code']);
        return $snapshot
            && $snapshot->execute_owner === self::OWNER
            && (int) $snapshot->authority_epoch === (int) $permit['authority_epoch'];
    }

    private function snapshot(string $taskCode): ?object
    {
        if ($taskCode === '') {
            return null;
        }

        try {
            return DB::table('java_processing_authority')
                ->where('task_code', $taskCode)
                ->first(['task_code', 'scan_owner', 'execute_owner', 'authority_epoch']);
        } catch (\Throwable $exception) {
            Log::warning('Processing authority lookup failed', [
                'task_code' => $taskCode,
                'reason_type' => get_class($exception),
            ]);
            return null;
        }
    }
}
