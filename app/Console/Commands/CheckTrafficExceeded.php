<?php

namespace App\Console\Commands;

use App\Models\Server;
use App\Models\User;
use App\Services\JavaTrafficOutboxService;
use App\Services\NodeSyncService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Redis;

class CheckTrafficExceeded extends Command
{
    protected $signature = 'check:traffic-exceeded
        {--dry-run : 只读检查 Java traffic Outbox 和交付账本，不抢占、不发布、不弹出 Redis 集合}';
    protected $description = '检查流量超标用户，并由 Xboard 唯一节点控制面消费 Java traffic Outbox';

    public function handle(JavaTrafficOutboxService $javaOutbox): int
    {
        if ($this->option('dry-run')) {
            $this->line(json_encode($javaOutbox->consume(true), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
            return self::SUCCESS;
        }

        if ((bool) config('java_traffic_outbox.enabled', false)) {
            $result = $javaOutbox->consume(false);
            if (!$result['schema_ready']) {
                Log::warning('[TrafficOutbox] Consumer skipped because schema is not ready', [
                    'reason' => $result['reason'],
                ]);
            } elseif (($result['claimed_deliveries'] ?? 0) > 0
                || ($result['processed_events'] ?? 0) > 0
                || ($result['recovered_claims'] ?? 0) > 0) {
                $this->info('Java traffic Outbox: ' . json_encode($result, JSON_UNESCAPED_UNICODE));
            }
        }

        $count = (int) Redis::scard('traffic:pending_check');
        if ($count <= 0) {
            return self::SUCCESS;
        }

        $pendingUserIds = array_map('intval', Redis::spop('traffic:pending_check', $count));

        $exceededUsers = User::toBase()
            ->whereIn('id', $pendingUserIds)
            ->whereRaw('u + d >= transfer_enable')
            ->where('transfer_enable', '>', 0)
            ->where('banned', 0)
            ->select(['id', 'group_id'])
            ->get();

        if ($exceededUsers->isEmpty()) {
            return self::SUCCESS;
        }

        $groupedUsers = $exceededUsers->groupBy('group_id');
        $notifiedCount = 0;

        foreach ($groupedUsers as $groupId => $users) {
            if (!$groupId) {
                continue;
            }

            $userIdsInGroup = $users->pluck('id')->toArray();
            $servers = Server::whereJsonContains('group_ids', (string) $groupId)->get();

            foreach ($servers as $server) {
                if (!NodeSyncService::isNodeOnline($server->id)) {
                    continue;
                }

                NodeSyncService::push($server->id, 'sync.user.delta', [
                    'action' => 'remove',
                    'users' => array_map(fn($id) => ['id' => $id], $userIdsInGroup),
                ]);
                $notifiedCount++;
            }
        }

        $this->info("Checked " . count($pendingUserIds) . " users, notified {$notifiedCount} nodes for " . $exceededUsers->count() . " exceeded users.");
        return self::SUCCESS;
    }
}
