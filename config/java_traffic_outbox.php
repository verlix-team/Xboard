<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Java traffic Outbox consumer
    |--------------------------------------------------------------------------
    |
    | Xboard remains the only runtime allowed to push traffic-driven user
    | changes to nodes. Keep this disabled until Hop Flyway V007 has been
    | applied and the WebSocket worker is running on every target deployment.
    |
    */
    'enabled' => env('XBOARD_JAVA_TRAFFIC_OUTBOX_CONSUMER_ENABLED', false),
    'batch_size' => max(1, (int) env('XBOARD_JAVA_TRAFFIC_OUTBOX_BATCH_SIZE', 100)),
    'claim_lease_seconds' => max(30, (int) env('XBOARD_JAVA_TRAFFIC_OUTBOX_CLAIM_LEASE_SECONDS', 120)),
    'retry_base_seconds' => max(1, (int) env('XBOARD_JAVA_TRAFFIC_OUTBOX_RETRY_BASE_SECONDS', 5)),
    'retry_max_seconds' => max(5, (int) env('XBOARD_JAVA_TRAFFIC_OUTBOX_RETRY_MAX_SECONDS', 300)),
];
