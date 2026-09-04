<?php

namespace Tests\Unit\Support;

use App\Support\Setting;
use Illuminate\Contracts\Cache\Repository;
use Mockery;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/** 站点设置持久化与缓存失效回归测试。 */
class SettingTest extends TestCase
{
    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    /** 验证 NULL 按“未配置”删除设置，且不会向数据库写入 NULL。 */
    public function test_null_value_removes_setting_instead_of_persisting_database_null(): void
    {
        $query = Mockery::mock();
        $query->shouldReceive('delete')->once()->andReturn(1);

        $model = Mockery::mock('alias:App\Models\Setting');
        $model->shouldReceive('where')->once()->with('name', 'app_url')->andReturn($query);
        $model->shouldNotReceive('createOrUpdate');

        $cache = Mockery::mock(Repository::class);
        $cache->shouldReceive('forget')->once()->with(Setting::CACHE_KEY);

        $reflection = new ReflectionClass(Setting::class);
        /** @var Setting $setting */
        $setting = $reflection->newInstanceWithoutConstructor();
        $cacheProperty = $reflection->getProperty('cache');
        $cacheProperty->setValue($setting, $cache);

        $this->assertTrue($setting->set('App_Url', null));
    }
}
