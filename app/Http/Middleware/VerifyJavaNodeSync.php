<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

/** 校验 Hop Java 即时节点同步入口的 HMAC、时钟窗口和单次重放。 */
class VerifyJavaNodeSync
{
    public function handle(Request $request, Closure $next)
    {
        if (!(bool) config('java_traffic_outbox.immediate_enabled', false)) {
            return response()->json(['message' => 'Not Found'], 404);
        }

        $secret = (string) config('java_traffic_outbox.immediate_secret', '');
        if (strlen($secret) < 32) {
            return response()->json(['message' => 'Node sync unavailable'], 503);
        }

        $timestamp = (string) $request->header('X-Hop-Timestamp', '');
        $signature = strtolower((string) $request->header('X-Hop-Signature', ''));
        if (!preg_match('/^[0-9]{10,}$/', $timestamp)
            || !preg_match('/^[0-9a-f]{64}$/', $signature)) {
            return response()->json(['message' => 'Unauthorized'], 401);
        }

        $skew = max(5, (int) config('java_traffic_outbox.immediate_clock_skew_seconds', 60));
        if (abs(time() - (int) $timestamp) > $skew) {
            return response()->json(['message' => 'Unauthorized'], 401);
        }

        $expected = self::signature($secret, $timestamp, $request->getContent());
        if (!hash_equals($expected, $signature)) {
            return response()->json(['message' => 'Unauthorized'], 401);
        }

        $replayKey = 'java_node_sync_replay:' . hash('sha256', $timestamp . "\n" . $signature);
        if (!Cache::add($replayKey, true, $skew * 2)) {
            return response()->json(['message' => 'Duplicate request'], 409);
        }

        return $next($request);
    }

    /** 对时间戳和原始 JSON 字节计算与 Java 一致的 HMAC-SHA256。 */
    public static function signature(string $secret, string $timestamp, string $body): string
    {
        return hash_hmac('sha256', $timestamp . "\n" . $body, $secret);
    }
}
