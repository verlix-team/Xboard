<?php

namespace Tests\Unit\Services;

use App\Services\ProcessingAuthorityService;
use Illuminate\Container\Container;
use Illuminate\Support\Facades\DB;
use Mockery;
use PHPUnit\Framework\TestCase;

class ProcessingAuthorityServiceTest extends TestCase
{
    protected function tearDown(): void
    {
        Mockery::close();
        DB::clearResolvedInstances();
        parent::tearDown();
    }

    public function test_scan_and_execution_require_same_owner_and_epoch(): void
    {
        $builder = Mockery::mock();
        $builder->shouldReceive('where')->twice()->with('task_code', 'expiredOrder')->andReturnSelf();
        $builder->shouldReceive('first')->twice()->andReturn(
            (object) ['scan_owner' => 'XBOARD', 'execute_owner' => 'XBOARD', 'authority_epoch' => 8],
            (object) ['scan_owner' => 'XBOARD', 'execute_owner' => 'XBOARD', 'authority_epoch' => 8]
        );
        $this->bindDatabase($builder);

        $service = new ProcessingAuthorityService();
        $permit = $service->scanPermit('expiredOrder');

        $this->assertSame(8, $permit['authority_epoch']);
        $this->assertTrue($service->executionAllowed($permit));
    }

    public function test_epoch_change_invalidates_stale_scan_permit(): void
    {
        $builder = Mockery::mock();
        $builder->shouldReceive('where')->twice()->andReturnSelf();
        $builder->shouldReceive('first')->twice()->andReturn(
            (object) ['scan_owner' => 'XBOARD', 'execute_owner' => 'XBOARD', 'authority_epoch' => 11],
            (object) ['scan_owner' => 'JAVA', 'execute_owner' => 'JAVA', 'authority_epoch' => 12]
        );
        $this->bindDatabase($builder);

        $service = new ProcessingAuthorityService();
        $permit = $service->scanPermit('orderFulfillment');

        $this->assertFalse($service->executionAllowed($permit));
    }

    private function bindDatabase(object $builder): void
    {
        $database = Mockery::mock();
        $database->shouldReceive('table')->twice()->with('java_processing_authority')->andReturn($builder);
        $container = new Container();
        $container->instance('db', $database);
        Container::setInstance($container);
        DB::setFacadeApplication($container);
    }
}
