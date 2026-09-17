<?php

namespace Tests\Unit\Services;

use App\Http\Controllers\V2\Admin\PlanController;
use App\Http\Requests\Admin\PlanSave;
use App\Models\Plan;
use App\Observers\PlanObserver;
use App\Services\PlanTranslationService;
use Illuminate\Container\Container;
use Illuminate\Contracts\Routing\ResponseFactory;
use Illuminate\Database\Capsule\Manager;
use Illuminate\Events\Dispatcher;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Facade;
use Mockery;
use PHPUnit\Framework\TestCase;

/** 明确指定SQLite内存库，不启动应用或RefreshDatabase，绝不触碰.env共享数据。 */
class PlanTranslationTransactionTest extends TestCase
{
    private Manager $database;
    private Container $previousContainer;
    private $previousFacades;

    protected function setUp(): void
    {
        $this->previousContainer = Container::getInstance();
        $this->previousFacades = Facade::getFacadeApplication();
        $container = new Container();
        Container::setInstance($container);
        $this->database = new Manager($container);
        $this->database->addConnection(['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '']);
        $this->database->setEventDispatcher(new Dispatcher($container));
        $this->database->setAsGlobal();
        $this->database->bootEloquent();
        $container->instance('db', $this->database->getDatabaseManager());
        $container->instance('db.schema', $this->database->getConnection()->getSchemaBuilder());
        $responses = Mockery::mock(ResponseFactory::class);
        $responses->shouldReceive('json')->andReturnUsing(fn ($data, $status = 200) => new JsonResponse($data, $status));
        $container->instance(ResponseFactory::class, $responses);
        $logger = Mockery::mock(); $logger->shouldReceive('error');
        $container->instance('log', $logger);
        Facade::clearResolvedInstances(); Facade::setFacadeApplication($container);
        $connection = $this->database->getConnection();
        $connection->statement('CREATE TABLE v2_plan (id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT, content TEXT, group_id INTEGER, transfer_enable INTEGER, speed_limit INTEGER, device_limit INTEGER, capacity_limit INTEGER, reset_traffic_method INTEGER, prices TEXT, tags TEXT, sort INTEGER, created_at INTEGER, updated_at INTEGER)');
        $connection->statement('CREATE TABLE v2_user (id INTEGER PRIMARY KEY, plan_id INTEGER, group_id INTEGER, transfer_enable INTEGER, speed_limit INTEGER, device_limit INTEGER, expired_at INTEGER, updated_at INTEGER)');
        $connection->statement('CREATE TABLE v2_server_group (id INTEGER PRIMARY KEY, name TEXT)');
        $connection->statement('CREATE TABLE java_plan_translation (plan_id INTEGER NOT NULL, locale TEXT NOT NULL, name TEXT NOT NULL, content TEXT NOT NULL, is_default INTEGER NOT NULL, created_at INTEGER NOT NULL, updated_at INTEGER NOT NULL, PRIMARY KEY(plan_id, locale))');
        $connection->statement('CREATE UNIQUE INDEX only_one_default ON java_plan_translation(plan_id) WHERE is_default=1');
        Plan::observe(PlanObserver::class);
    }

    protected function tearDown(): void
    {
        Plan::flushEventListeners();
        $this->database->getConnection()->disconnect();
        Facade::clearResolvedInstances(); Facade::setFacadeApplication($this->previousFacades);
        Container::setInstance($this->previousContainer);
        Mockery::close();
        parent::tearDown();
    }

    private function params(): array
    {
        return ['name' => '原始业务名称', 'content' => '原始说明', 'group_id' => 1, 'transfer_enable' => 100,
            'speed_limit' => 100, 'device_limit' => 5, 'capacity_limit' => null, 'reset_traffic_method' => 1,
            'prices' => ['monthly' => 299], 'tags' => [], 'defaultLocale' => 'zh-CN',
            'translations' => [['locale' => 'zh-CN', 'name' => '中文套餐', 'content' => '说明'],
                ['locale' => 'ru-RU', 'name' => 'Тариф', 'content' => 'Описание']]];
    }

    private function request(array $params): PlanSave
    {
        $request = Mockery::mock(PlanSave::class)->makePartial();
        $request->shouldReceive('validated')->andReturn($params);
        $request->shouldReceive('input')->with('id')->andReturn($params['id'] ?? null);
        $request->shouldReceive('boolean')->with('force_update')->andReturn(true);
        return $request;
    }

    public function test_create_and_default_switch_are_atomic_and_preserve_original_and_user_fields(): void
    {
        $service = new PlanTranslationService(); $controller = new PlanController($service);
        $this->assertSame(200, $controller->save($this->request($this->params()))->getStatusCode());
        $plan = Plan::firstOrFail();
        $this->database->table('v2_user')->insert(['id' => 1, 'plan_id' => $plan->id, 'group_id' => 2, 'transfer_enable' => 8888, 'speed_limit' => 1, 'device_limit' => 1]);
        $params = $this->params(); $params['id'] = $plan->id;
        $params['translationVersion'] = PlanTranslationService::version($service->directory([$plan->id])[$plan->id]);
        $params['defaultLocale'] = 'ru-RU'; $params['translations'][1]['name'] = 'Новый тариф';
        $this->assertSame(200, $controller->save($this->request($params))->getStatusCode());
        $this->assertSame('原始业务名称', $plan->fresh()->name);
        $this->assertSame('原始说明', $plan->fresh()->content);
        $this->assertSame(8888, $this->database->table('v2_user')->value('transfer_enable'));
        $this->assertSame('ru-RU', $this->database->table(PlanTranslationService::TABLE)->where('is_default', 1)->value('locale'));
        $this->assertSame(409, $controller->save($this->request($params))->getStatusCode());
        $this->assertSame(2, $this->database->table(PlanTranslationService::TABLE)->count());
    }

    public function test_translation_failure_rolls_back_plan_and_subscriber_entitlement_writes(): void
    {
        $service = new PlanTranslationService(); $controller = new PlanController($service);
        $controller->save($this->request($this->params())); $plan = Plan::firstOrFail();
        $this->database->table('v2_user')->insert(['id' => 1, 'plan_id' => $plan->id, 'group_id' => 2, 'transfer_enable' => 8888, 'speed_limit' => 1, 'device_limit' => 1]);
        $before = $service->directory([$plan->id])[$plan->id];
        $failing = Mockery::mock(PlanTranslationService::class)->makePartial();
        $failing->shouldReceive('save')->once()->andReturnUsing(function ($id, $rows) use ($service) {
            $service->save($id, $rows);
            throw new \RuntimeException('test_模拟翻译落库失败');
        });
        $params = $this->params(); $params['id'] = $plan->id;
        $params['translationVersion'] = PlanTranslationService::version($before);
        $params['transfer_enable'] = 200; $params['defaultLocale'] = 'ru-RU';
        $response = (new PlanController($failing))->save($this->request($params));
        $this->assertSame(500, $response->getStatusCode());
        $this->assertSame(100, (int) $plan->fresh()->transfer_enable);
        $this->assertSame(8888, $this->database->table('v2_user')->value('transfer_enable'));
        $this->assertSame($before, $service->directory([$plan->id])[$plan->id]);
    }

    public function test_missing_migration_blocks_saving_without_creating_tables(): void
    {
        $this->database->getConnection()->statement('DROP TABLE java_plan_translation');
        $response = (new PlanController(new PlanTranslationService()))->save($this->request($this->params()));
        $this->assertSame(503, $response->getStatusCode());
        $this->assertSame(0, Plan::count());
        $this->assertFalse($this->database->getConnection()->getSchemaBuilder()->hasTable(PlanTranslationService::TABLE));
    }

    public function test_fetch_returns_all_languages_version_and_explicit_readiness(): void
    {
        $service = new PlanTranslationService(); $controller = new PlanController($service);
        $controller->save($this->request($this->params()));
        $plan = Plan::firstOrFail();
        $payload = $controller->fetch(new \Illuminate\Http\Request())->getData(true)['data'][0];
        $this->assertTrue($payload['translationsAvailable']);
        $this->assertSame('zh-CN', $payload['defaultLocale']);
        $this->assertCount(2, $payload['translations']);
        $this->assertSame('Тариф', $payload['translations'][0]['name']);
        $this->assertArrayNotHasKey('isDefault', $payload['translations'][0]);
        $this->assertSame(PlanTranslationService::version($service->directory([$plan->id])[$plan->id]), $payload['translationVersion']);
        $this->database->getConnection()->statement('DROP TABLE java_plan_translation');
        $unavailable = $controller->fetch(new \Illuminate\Http\Request())->getData(true)['data'][0];
        $this->assertFalse($unavailable['translationsAvailable']);
        $this->assertNull($unavailable['defaultLocale']);
        $this->assertSame([], $unavailable['translations']);
        $this->assertSame('原始业务名称', $unavailable['name']);
    }

    public function test_new_plan_creation_is_rolled_back_with_failed_translation(): void
    {
        $service = new PlanTranslationService();
        $failing = Mockery::mock(PlanTranslationService::class)->makePartial();
        $failing->shouldReceive('save')->once()->andReturnUsing(function ($id, $rows) use ($service) {
            $service->save($id, $rows);
            throw new \RuntimeException('test_新套餐翻译落库失败');
        });
        $this->assertSame(500, (new PlanController($failing))->save($this->request($this->params()))->getStatusCode());
        $this->assertSame(0, Plan::count());
        $this->assertSame(0, $this->database->table(PlanTranslationService::TABLE)->count());
    }

    public function test_audit_preserves_translation_metadata_but_excludes_full_content(): void
    {
        $this->database->getConnection()->statement('CREATE TABLE v2_admin_audit_log (admin_id INTEGER, action TEXT, method TEXT, uri TEXT, request_data TEXT, ip TEXT, created_at INTEGER, updated_at INTEGER)');
        $params = $this->params() + ['translationVersion' => str_repeat('a', 64), 'password' => 'test_not_logged'];
        $request = \Illuminate\Http\Request::create('/api/v2/test_admin/plan/save', 'POST', $params);
        $request->setUserResolver(fn () => (object) ['id' => 1, 'is_admin' => true]);
        $middleware = new \App\Http\Middleware\RequestLog();
        $middleware->handle($request, fn () => new JsonResponse(['data' => true], 200));
        $record = $this->database->table('v2_admin_audit_log')->first();
        $data = json_decode($record->request_data, true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame('plan.save', $record->action);
        $this->assertSame(['zh-CN', 'ru-RU'], $data['translationLocales']);
        $this->assertSame('zh-CN', $data['defaultLocale']);
        $this->assertSame(str_repeat('a', 64), $data['translationVersion']);
        $this->assertSame(200, $data['responseStatus']);
        foreach (['translations', 'content', 'password'] as $field) $this->assertArrayNotHasKey($field, $data);
        $request->setUserResolver(fn () => (object) ['id' => 1, 'is_admin' => false]);
        $middleware->handle($request, fn () => new JsonResponse(['data' => false], 403));
        $this->assertSame(1, $this->database->table('v2_admin_audit_log')->count());
    }
}
