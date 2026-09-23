<?php

namespace App\Console\Commands;

use App\Models\Server;
use App\Services\NodeSyncService;
use Illuminate\Console\Command;
use App\Support\ChecksProcessingAuthority;

/** 周期推送权威完整用户列表，修复 Redis 或 WebSocket 极端丢消息后的节点漂移。 */
class ReconcileNodeUsers extends Command
{
    use ChecksProcessingAuthority;
    protected $signature = 'node:reconcile-users
        {--dry-run : 只读统计目标节点，不发布 Redis 或 WebSocket 消息}';
    protected $description = '向在线 Xboard 节点推送权威完整用户列表';

    public function handle(): int
    {
        if (!$this->option('dry-run')
            && !(bool) config('java_traffic_outbox.full_sync_enabled', false)) {
            return self::SUCCESS;
        }

        $permit = $this->option('dry-run') ? null : $this->scanPermit('nodeFullSync');
        if (!$this->option('dry-run') && (!$permit || !$this->executionAllowed($permit))) {
            return self::SUCCESS;
        }

        $servers = Server::query()->where('enabled', true)->orderBy('id')->get(['id']);
        $online = $servers->filter(
            fn($server) => NodeSyncService::isNodeOnline((int) $server->id)
        );

        if ($this->option('dry-run')) {
            $this->line(json_encode([
                'enabled_nodes' => $servers->count(),
                'online_nodes' => $online->count(),
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
            return self::SUCCESS;
        }

        foreach ($online as $server) {
            if (!$this->executionAllowed($permit)) return self::SUCCESS;
            NodeSyncService::notifyFullSync((int) $server->id);
        }

        $this->info("Reconciled {$online->count()} online nodes.");
        return self::SUCCESS;
    }
}
