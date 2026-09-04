<?php

namespace App\Services;

use App\Models\Server;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

/**
 * Consumes Hop Java traffic Outbox events through Xboard's node control plane.
 *
 * The Outbox row remains pending until every online target is accepted by an
 * active Workerman connection, while offline/polling targets are delegated to
 * authoritative full sync. Repeated ADD/REMOVE messages are safe because they
 * describe desired membership instead of a numeric delta.
 */
class JavaTrafficOutboxService
{
    private const OUTBOX_TABLE = 'java_traffic_event_outbox';
    private const DELIVERY_TABLE = 'java_traffic_node_delivery';
    private const EVENT_TYPES = ['TRAFFIC_EXCEEDED', 'TRAFFIC_RESTORED', 'TRAFFIC_RESET'];

    /**
     * Process one bounded consumer batch or return a read-only inspection.
     */
    public function consume(bool $dryRun = false): array
    {
        $inspection = $this->inspect();
        if ($dryRun || !$inspection['schema_ready']) {
            return $inspection;
        }

        $now = time();
        $recovered = $this->recoverExpiredClaims($now);
        $seeded = $this->seedPendingEvents($now);
        $claimed = $this->claimDueDeliveries($now);
        $published = 0;
        $deferred = 0;
        $completedWithoutPush = 0;

        foreach ($claimed as $delivery) {
            $outcome = $this->dispatch($delivery);
            if ($outcome === 'PUBLISHED') {
                $published++;
            } elseif ($outcome === 'COMPLETED') {
                $completedWithoutPush++;
            } else {
                $deferred++;
            }
        }

        $processed = $this->finalizeCompletedEvents($now);

        return array_merge($this->inspect(), [
            'recovered_claims' => $recovered,
            'seeded_deliveries' => $seeded,
            'claimed_deliveries' => count($claimed),
            'published_deliveries' => $published,
            'completed_without_push' => $completedWithoutPush,
            'deferred_deliveries' => $deferred,
            'processed_events' => $processed,
        ]);
    }

    /**
     * Return current durable state without claiming rows or publishing events.
     */
    public function inspect(): array
    {
        $outboxReady = Schema::hasTable(self::OUTBOX_TABLE);
        $deliveryReady = Schema::hasTable(self::DELIVERY_TABLE);
        if (!$outboxReady) {
            return [
                'schema_ready' => false,
                'pending_events' => 0,
                'pending_by_type' => [],
                'delivery_status' => [],
                'reason' => 'OUTBOX_TABLE_MISSING',
            ];
        }

        $pendingEvents = (int) DB::table(self::OUTBOX_TABLE)
            ->where('processing_status', 'PENDING')
            ->whereIn('event_type', self::EVENT_TYPES)
            ->count();
        $pendingByType = DB::table(self::OUTBOX_TABLE)
            ->selectRaw('event_type, COUNT(*) AS aggregate')
            ->where('processing_status', 'PENDING')
            ->whereIn('event_type', self::EVENT_TYPES)
            ->groupBy('event_type')
            ->pluck('aggregate', 'event_type')
            ->map(fn($count) => (int) $count)
            ->all();

        if (!$deliveryReady) {
            return [
                'schema_ready' => false,
                'pending_events' => $pendingEvents,
                'pending_by_type' => $pendingByType,
                'delivery_status' => [],
                'reason' => 'DELIVERY_TABLE_MISSING',
            ];
        }

        $deliveryStatus = DB::table(self::DELIVERY_TABLE)
            ->selectRaw('delivery_status, COUNT(*) AS aggregate')
            ->groupBy('delivery_status')
            ->pluck('aggregate', 'delivery_status')
            ->map(fn($count) => (int) $count)
            ->all();

        return [
            'schema_ready' => true,
            'pending_events' => $pendingEvents,
            'pending_by_type' => $pendingByType,
            'delivery_status' => $deliveryStatus,
            'reason' => null,
        ];
    }

    /**
     * Mark a delivery successful only after the Workerman process writes the
     * delta to the active node socket.
     */
    public function acknowledgeDelivery(int $deliveryId, string $claimToken): bool
    {
        if (!Schema::hasTable(self::DELIVERY_TABLE)) {
            return false;
        }

        $now = time();
        $updated = DB::table(self::DELIVERY_TABLE)
            ->where('id', $deliveryId)
            ->where('delivery_status', 'PROCESSING')
            ->where('claim_token', $claimToken)
            ->update([
                'delivery_status' => 'DELIVERED',
                'delivered_at' => $now,
                'claim_token' => null,
                'claimed_at' => null,
                'last_error_code' => null,
                'updated_at' => $now,
            ]);

        if ($updated === 1) {
            $eventId = DB::table(self::DELIVERY_TABLE)
                ->where('id', $deliveryId)
                ->value('outbox_event_id');
            if ($eventId !== null) {
                $this->finalizeEvent((int) $eventId, $now);
            }
            return true;
        }
        return false;
    }

    /**
     * Release a claimed delivery for bounded exponential retry.
     */
    public function retryDelivery(int $deliveryId, string $claimToken, string $errorCode): bool
    {
        if (!Schema::hasTable(self::DELIVERY_TABLE)) {
            return false;
        }

        $delivery = DB::table(self::DELIVERY_TABLE)
            ->where('id', $deliveryId)
            ->where('delivery_status', 'PROCESSING')
            ->where('claim_token', $claimToken)
            ->first(['attempt_count']);
        if (!$delivery) {
            return false;
        }

        $now = time();
        return DB::table(self::DELIVERY_TABLE)
            ->where('id', $deliveryId)
            ->where('delivery_status', 'PROCESSING')
            ->where('claim_token', $claimToken)
            ->update([
                'delivery_status' => 'PENDING',
                'next_attempt_at' => $now + self::retryDelaySeconds((int) $delivery->attempt_count),
                'claim_token' => null,
                'claimed_at' => null,
                'last_error_code' => substr($errorCode, 0, 64),
                'updated_at' => $now,
            ]) === 1;
    }

    /**
     * Calculate the configured bounded exponential retry delay.
     */
    public static function retryDelaySeconds(int $attemptCount): int
    {
        $base = max(1, (int) config('java_traffic_outbox.retry_base_seconds', 5));
        $maximum = max($base, (int) config('java_traffic_outbox.retry_max_seconds', 300));
        $exponent = max(0, min(10, $attemptCount - 1));
        return min($maximum, $base * (2 ** $exponent));
    }

    /**
     * Build the stable, idempotent node delta payload.
     */
    public static function deltaPayload(string $eventKey, string $action, int $userId, ?object $user): array
    {
        $normalizedAction = strtoupper($action) === 'ADD' ? 'add' : 'remove';
        $payloadUser = ['id' => $userId];
        if ($normalizedAction === 'add' && $user !== null) {
            $payloadUser['uuid'] = $user->uuid;
            $payloadUser['speed_limit'] = $user->speed_limit;
            $payloadUser['device_limit'] = $user->device_limit;
        }

        return [
            'event_id' => $eventKey,
            'action' => $normalizedAction,
            'users' => [$payloadUser],
        ];
    }

    /**
     * Recover claims left by a terminated scheduler or WebSocket worker.
     */
    private function recoverExpiredClaims(int $now): int
    {
        $lease = max(30, (int) config('java_traffic_outbox.claim_lease_seconds', 120));
        return DB::table(self::DELIVERY_TABLE)
            ->where('delivery_status', 'PROCESSING')
            ->where('claimed_at', '<=', $now - $lease)
            ->update([
                'delivery_status' => 'PENDING',
                'next_attempt_at' => $now,
                'claim_token' => null,
                'claimed_at' => null,
                'last_error_code' => 'CLAIM_LEASE_EXPIRED',
                'updated_at' => $now,
            ]);
    }

    /**
     * Materialize per-node delivery rows while holding only short DB locks.
     */
    private function seedPendingEvents(int $now): int
    {
        $limit = max(1, (int) config('java_traffic_outbox.batch_size', 100));

        return DB::transaction(function () use ($now, $limit) {
            $events = DB::table(self::OUTBOX_TABLE . ' as event')
                ->where('event.processing_status', 'PENDING')
                ->whereIn('event.event_type', self::EVENT_TYPES)
                ->whereNotExists(function ($query) {
                    $query->selectRaw('1')
                        ->from(self::DELIVERY_TABLE . ' as delivery')
                        ->whereColumn('delivery.outbox_event_id', 'event.id');
                })
                ->orderBy('event.id')
                ->limit($limit)
                ->lock('FOR UPDATE SKIP LOCKED')
                ->get([
                    'event.id', 'event.event_key', 'event.event_type',
                    'event.user_id', 'event.group_id',
                ]);

            $seeded = 0;
            foreach ($events as $event) {
                $currentGroupId = DB::table('v2_user')->where('id', $event->user_id)->value('group_id');
                $groupIds = array_values(array_unique(array_filter([
                    $event->group_id === null ? null : (int) $event->group_id,
                    $currentGroupId === null ? null : (int) $currentGroupId,
                ], fn($groupId) => $groupId !== null && $groupId > 0)));

                if ($groupIds === []) {
                    $this->markEventProcessed((int) $event->id, $now);
                    continue;
                }

                $servers = Server::query()
                    ->where('enabled', true)
                    ->where(function ($query) use ($groupIds) {
                        foreach ($groupIds as $index => $groupId) {
                            $method = $index === 0 ? 'whereJsonContains' : 'orWhereJsonContains';
                            $query->{$method}('group_ids', (string) $groupId);
                        }
                    })
                    ->get(['id']);

                if ($servers->isEmpty()) {
                    $this->markEventProcessed((int) $event->id, $now);
                    continue;
                }

                $initialAction = $event->event_type === 'TRAFFIC_EXCEEDED' ? 'REMOVE' : 'ADD';
                foreach ($servers as $server) {
                    $inserted = DB::table(self::DELIVERY_TABLE)->insertOrIgnore([
                        'outbox_event_id' => (int) $event->id,
                        'event_key' => $event->event_key,
                        'user_id' => (int) $event->user_id,
                        'node_id' => (int) $server->id,
                        'delivery_action' => $initialAction,
                        'delivery_status' => 'PENDING',
                        'attempt_count' => 0,
                        'next_attempt_at' => $now,
                        'created_at' => $now,
                        'updated_at' => $now,
                    ]);
                    $seeded += (int) $inserted;
                }
            }
            return $seeded;
        }, 3);
    }

    /**
     * Claim due deliveries and serialize events for the same user by Outbox ID.
     */
    private function claimDueDeliveries(int $now): array
    {
        $limit = max(1, (int) config('java_traffic_outbox.batch_size', 100));
        $claimToken = bin2hex(random_bytes(16));

        return DB::transaction(function () use ($now, $limit, $claimToken) {
            $rows = DB::table(self::DELIVERY_TABLE . ' as delivery')
                ->join(self::OUTBOX_TABLE . ' as event', 'event.id', '=', 'delivery.outbox_event_id')
                ->where('delivery.delivery_status', 'PENDING')
                ->where('delivery.next_attempt_at', '<=', $now)
                ->where('event.processing_status', 'PENDING')
                ->whereNotExists(function ($query) {
                    $query->selectRaw('1')
                        ->from(self::OUTBOX_TABLE . ' as earlier')
                        ->whereColumn('earlier.user_id', 'event.user_id')
                        ->whereColumn('earlier.id', '<', 'event.id')
                        ->where('earlier.processing_status', 'PENDING')
                        ->whereIn('earlier.event_type', self::EVENT_TYPES);
                })
                ->orderBy('event.id')
                ->orderBy('delivery.id')
                ->limit($limit)
                ->lock('FOR UPDATE SKIP LOCKED')
                ->get([
                    'delivery.id', 'delivery.outbox_event_id', 'delivery.event_key',
                    'delivery.user_id', 'delivery.node_id', 'delivery.attempt_count',
                ]);

            $claimed = [];
            foreach ($rows as $row) {
                $updated = DB::table(self::DELIVERY_TABLE)
                    ->where('id', $row->id)
                    ->where('delivery_status', 'PENDING')
                    ->update([
                        'delivery_status' => 'PROCESSING',
                        'attempt_count' => DB::raw('attempt_count + 1'),
                        'claim_token' => $claimToken,
                        'claimed_at' => $now,
                        'last_error_code' => null,
                        'updated_at' => $now,
                    ]);
                if ($updated === 1) {
                    $row->claim_token = $claimToken;
                    $claimed[] = $row;
                }
            }
            return $claimed;
        }, 3);
    }

    /**
     * Re-evaluate current node membership before publishing a claimed delivery.
     */
    private function dispatch(object $delivery): string
    {
        $server = Server::query()->where('id', $delivery->node_id)->where('enabled', true)->first();
        if (!$server) {
            return $this->completeWithoutPush($delivery, 'NODE_NOT_ACTIVE') ? 'COMPLETED' : 'DEFERRED';
        }

        $availableUser = ServerService::findAvailableUser($server, (int) $delivery->user_id);
        $action = $availableUser === null ? 'REMOVE' : 'ADD';
        DB::table(self::DELIVERY_TABLE)
            ->where('id', $delivery->id)
            ->where('delivery_status', 'PROCESSING')
            ->where('claim_token', $delivery->claim_token)
            ->update(['delivery_action' => $action, 'updated_at' => time()]);

        if (!NodeSyncService::isNodeOnline((int) $delivery->node_id)) {
            // An offline WebSocket node (and every legacy polling node) receives
            // the authoritative current user list on reconnect or its next pull.
            // Completing this target avoids retaining obsolete delta work while
            // preserving eventual convergence without a network call in the DB
            // transaction that materializes delivery rows.
            return $this->completeWithoutPush($delivery, 'NODE_OFFLINE_FULL_SYNC')
                ? 'COMPLETED' : 'DEFERRED';
        }

        $published = NodeSyncService::push(
            (int) $delivery->node_id,
            'sync.user.delta',
            self::deltaPayload($delivery->event_key, $action, (int) $delivery->user_id, $availableUser),
            ['id' => (int) $delivery->id, 'claim_token' => $delivery->claim_token]
        );
        if (!$published) {
            $this->retryDelivery((int) $delivery->id, $delivery->claim_token, 'WS_PUBLISH_UNAVAILABLE');
            return 'DEFERRED';
        }
        return 'PUBLISHED';
    }

    /**
     * Complete a target that no longer has an active node.
     */
    private function completeWithoutPush(object $delivery, string $reason): bool
    {
        $now = time();
        $updated = DB::table(self::DELIVERY_TABLE)
            ->where('id', $delivery->id)
            ->where('delivery_status', 'PROCESSING')
            ->where('claim_token', $delivery->claim_token)
            ->update([
                'delivery_status' => 'DELIVERED',
                'delivered_at' => $now,
                'claim_token' => null,
                'claimed_at' => null,
                'last_error_code' => $reason,
                'updated_at' => $now,
            ]);
        if ($updated === 1) {
            $this->finalizeEvent((int) $delivery->outbox_event_id, $now);
            return true;
        }
        return false;
    }

    /**
     * Finalize all events whose durable target deliveries are complete.
     */
    private function finalizeCompletedEvents(int $now): int
    {
        $eventIds = DB::table(self::OUTBOX_TABLE . ' as event')
            ->where('event.processing_status', 'PENDING')
            ->whereIn('event.event_type', self::EVENT_TYPES)
            ->whereExists(function ($query) {
                $query->selectRaw('1')->from(self::DELIVERY_TABLE . ' as delivery')
                    ->whereColumn('delivery.outbox_event_id', 'event.id');
            })
            ->whereNotExists(function ($query) {
                $query->selectRaw('1')->from(self::DELIVERY_TABLE . ' as delivery')
                    ->whereColumn('delivery.outbox_event_id', 'event.id')
                    ->where('delivery.delivery_status', '!=', 'DELIVERED');
            })
            ->limit(max(1, (int) config('java_traffic_outbox.batch_size', 100)))
            ->pluck('event.id');

        $processed = 0;
        foreach ($eventIds as $eventId) {
            $processed += $this->finalizeEvent((int) $eventId, $now) ? 1 : 0;
        }
        return $processed;
    }

    /**
     * Reconcile current group targets and finalize only when none is unfinished.
     */
    private function finalizeEvent(int $eventId, int $now): bool
    {
        return DB::transaction(function () use ($eventId, $now) {
            $event = DB::table(self::OUTBOX_TABLE)
                ->where('id', $eventId)
                ->where('processing_status', 'PENDING')
                ->lockForUpdate()
                ->first(['id', 'event_key', 'event_type', 'user_id', 'group_id']);
            if (!$event) {
                return false;
            }

            // A delayed delivery may outlive a group change. Before declaring the
            // event complete, add any target from the latest authoritative group;
            // the unique key makes this safe across scheduler/worker races.
            $currentGroupId = DB::table('v2_user')->where('id', $event->user_id)->value('group_id');
            $groupIds = array_values(array_unique(array_filter([
                $event->group_id === null ? null : (int) $event->group_id,
                $currentGroupId === null ? null : (int) $currentGroupId,
            ], fn($groupId) => $groupId !== null && $groupId > 0)));
            if ($groupIds !== []) {
                $servers = Server::query()
                    ->where('enabled', true)
                    ->where(function ($query) use ($groupIds) {
                        foreach ($groupIds as $index => $groupId) {
                            $method = $index === 0 ? 'whereJsonContains' : 'orWhereJsonContains';
                            $query->{$method}('group_ids', (string) $groupId);
                        }
                    })
                    ->get(['id']);
                $initialAction = $event->event_type === 'TRAFFIC_EXCEEDED' ? 'REMOVE' : 'ADD';
                foreach ($servers as $server) {
                    DB::table(self::DELIVERY_TABLE)->insertOrIgnore([
                        'outbox_event_id' => (int) $event->id,
                        'event_key' => $event->event_key,
                        'user_id' => (int) $event->user_id,
                        'node_id' => (int) $server->id,
                        'delivery_action' => $initialAction,
                        'delivery_status' => 'PENDING',
                        'attempt_count' => 0,
                        'next_attempt_at' => $now,
                        'created_at' => $now,
                        'updated_at' => $now,
                    ]);
                }
            }

            $unfinished = DB::table(self::DELIVERY_TABLE)
                ->where('outbox_event_id', $eventId)
                ->where('delivery_status', '!=', 'DELIVERED')
                ->exists();
            if ($unfinished) {
                return false;
            }
            return $this->markEventProcessed($eventId, $now);
        }, 3);
    }

    /**
     * Conditionally mark the Java Outbox row processed.
     */
    private function markEventProcessed(int $eventId, int $now): bool
    {
        return DB::table(self::OUTBOX_TABLE)
            ->where('id', $eventId)
            ->where('processing_status', 'PENDING')
            ->update(['processing_status' => 'PROCESSED', 'processed_at' => $now]) === 1;
    }
}
