<?php

namespace App\Support;

use App\Services\ProcessingAuthorityService;

/** 为命令和队列任务提供统一的扫描/执行双门禁。 */
trait ChecksProcessingAuthority
{
    protected function scanPermit(string $taskCode): ?array
    {
        return app(ProcessingAuthorityService::class)->scanPermit($taskCode);
    }

    protected function executionAllowed(?array $permit): bool
    {
        return app(ProcessingAuthorityService::class)->executionAllowed($permit);
    }
}
