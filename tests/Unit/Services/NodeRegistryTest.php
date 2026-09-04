<?php

namespace Tests\Unit\Services;

use App\Services\NodeRegistry;
use PHPUnit\Framework\TestCase;
use Workerman\Connection\TcpConnection;

class NodeRegistryTest extends TestCase
{
    public function test_send_rejects_failed_workerman_write(): void
    {
        $connection = $this->createMock(TcpConnection::class);
        $connection->expects($this->once())->method('send')->willReturn(false);
        NodeRegistry::add(900001, $connection);

        try {
            $this->assertFalse(NodeRegistry::send(900001, 'sync.user.delta', ['users' => []]));
        } finally {
            NodeRegistry::remove(900001, $connection);
        }
    }

    public function test_send_accepts_workerman_buffered_write(): void
    {
        $connection = $this->createMock(TcpConnection::class);
        $connection->expects($this->once())->method('send')->willReturn(null);
        NodeRegistry::add(900002, $connection);

        try {
            $this->assertTrue(NodeRegistry::send(900002, 'sync.user.delta', ['users' => []]));
        } finally {
            NodeRegistry::remove(900002, $connection);
        }
    }
}
