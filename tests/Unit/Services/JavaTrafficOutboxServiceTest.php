<?php

namespace Tests\Unit\Services;

use App\Services\JavaTrafficOutboxService;
use Illuminate\Config\Repository;
use Illuminate\Container\Container;
use PHPUnit\Framework\TestCase;

class JavaTrafficOutboxServiceTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $container = new Container();
        $container->instance('config', new Repository([
            'java_traffic_outbox' => [
                'retry_base_seconds' => 5,
                'retry_max_seconds' => 300,
            ],
        ]));
        Container::setInstance($container);
    }

    public function test_retry_delay_is_bounded_exponential(): void
    {
        $this->assertSame(5, JavaTrafficOutboxService::retryDelaySeconds(1));
        $this->assertSame(10, JavaTrafficOutboxService::retryDelaySeconds(2));
        $this->assertSame(300, JavaTrafficOutboxService::retryDelaySeconds(20));
    }

    public function test_add_payload_contains_stable_event_and_current_credentials(): void
    {
        $user = (object) [
            'uuid' => 'test-uuid',
            'speed_limit' => 128,
            'device_limit' => 2,
        ];

        $this->assertSame([
            'event_id' => 'traffic-restored:test-event',
            'action' => 'add',
            'users' => [[
                'id' => 42,
                'uuid' => 'test-uuid',
                'speed_limit' => 128,
                'device_limit' => 2,
            ]],
        ], JavaTrafficOutboxService::deltaPayload(
            'traffic-restored:test-event', 'ADD', 42, $user
        ));
    }

    public function test_remove_payload_does_not_expose_user_credentials(): void
    {
        $this->assertSame([
            'event_id' => 'traffic-exceeded:test-event',
            'action' => 'remove',
            'users' => [['id' => 42]],
        ], JavaTrafficOutboxService::deltaPayload(
            'traffic-exceeded:test-event', 'REMOVE', 42, null
        ));
    }
}
