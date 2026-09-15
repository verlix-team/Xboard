<?php

namespace App\Http\Controllers\V2\Internal;

use App\Http\Controllers\Controller;
use App\Jobs\NodeUserSyncJob;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** 接收 Java 提交后的非敏感唤醒，仅由 Xboard 队列执行实际节点推送。 */
class NodeUserSyncController extends Controller
{
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'eventKey' => ['required', 'string', 'max:128', 'regex:/^[A-Za-z0-9:_-]+$/'],
        ]);

        if (!Schema::hasTable('java_traffic_event_outbox')) {
            return response()->json(['message' => 'Node sync Outbox unavailable'], 503);
        }

        $event = DB::table('java_traffic_event_outbox')
            ->where('event_key', $validated['eventKey'])
            ->where('event_type', 'ENTITLEMENT_CHANGED')
            ->first(['user_id', 'group_id']);
        if (!$event) {
            return response()->json(['message' => 'Node sync event not found'], 404);
        }

        NodeUserSyncJob::dispatch(
            (int) $event->user_id,
            'updated',
            $event->group_id === null ? null : (int) $event->group_id
        );

        return response()->json(['data' => true], 202);
    }
}
