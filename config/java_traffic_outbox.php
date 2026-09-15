<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Java user-state Outbox consumer
    |--------------------------------------------------------------------------
    |
    | Xboard remains the only runtime allowed to push Java traffic or
    | entitlement-driven user changes to nodes. Keep this disabled until the
    | matching Hop Flyway versions and every WebSocket worker are deployed.
    |
    */
    'enabled' => env('XBOARD_JAVA_TRAFFIC_OUTBOX_CONSUMER_ENABLED', false),
    // 即时入口只负责唤醒原生队列链；持久 Outbox 仍负责失败恢复。
    'immediate_enabled' => env('XBOARD_JAVA_NODE_SYNC_IMMEDIATE_ENABLED', false),
    'immediate_secret' => env('XBOARD_JAVA_NODE_SYNC_SECRET', ''),
    'immediate_clock_skew_seconds' => max(5, (int) env('XBOARD_JAVA_NODE_SYNC_CLOCK_SKEW_SECONDS', 60)),
    // 周期全量同步用于修复 Redis/WS 已发布但 Agent 未应用的极端漂移。
    'full_sync_enabled' => env('XBOARD_NODE_USER_FULL_SYNC_ENABLED', false),
    'batch_size' => max(1, (int) env('XBOARD_JAVA_TRAFFIC_OUTBOX_BATCH_SIZE', 100)),
    'claim_lease_seconds' => max(30, (int) env('XBOARD_JAVA_TRAFFIC_OUTBOX_CLAIM_LEASE_SECONDS', 120)),
    'retry_base_seconds' => max(1, (int) env('XBOARD_JAVA_TRAFFIC_OUTBOX_RETRY_BASE_SECONDS', 5)),
    'retry_max_seconds' => max(5, (int) env('XBOARD_JAVA_TRAFFIC_OUTBOX_RETRY_MAX_SECONDS', 300)),
];
