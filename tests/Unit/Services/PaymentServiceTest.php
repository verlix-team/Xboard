<?php

namespace Tests\Unit\Services;

use App\Contracts\PaymentInterface;
use App\Exceptions\ApiException;
use App\Services\PaymentService;
use App\Services\Plugin\AbstractPlugin;
use App\Services\Plugin\PluginManager;
use Illuminate\Container\Container;
use Illuminate\Support\Facades\Facade;
use Mockery;
use PHPUnit\Framework\TestCase;

/** 管理端编辑停用支付插件时的边界回归测试。 */
class PaymentServiceTest extends TestCase
{
    protected function tearDown(): void
    {
        Mockery::close();
        Facade::clearResolvedInstances();
        Facade::setFacadeApplication(null);
        Container::setInstance(null);
        parent::tearDown();
    }

    /** 验证管理端可回填停用插件表单，但普通支付服务仍拒绝该插件。 */
    public function test_admin_edit_can_load_installed_disabled_plugin_form(): void
    {
        $paymentModel = Mockery::mock();
        $paymentAlias = Mockery::mock('alias:App\Models\Payment');
        $paymentAlias->shouldReceive('find')->twice()->with(9)->andReturn($paymentModel);
        $paymentModel->shouldReceive('makeVisible')->twice()->with('config')->andReturnSelf();
        $paymentModel->shouldReceive('toArray')->twice()->andReturn([
            'id' => 9,
            'uuid' => 'test-uuid',
            'payment' => 'AlipayF2F',
            'config' => ['app_id' => 'saved-app'],
            'enable' => false,
            'notify_domain' => null,
        ]);

        $plugin = new FakeDisabledPaymentPlugin('alipay_f2f');
        $pluginManager = Mockery::mock(PluginManager::class);
        $pluginManager->shouldReceive('getInstalledPaymentPluginForMethod')
            ->once()->with('AlipayF2F')->andReturn($plugin);

        $container = new Container();
        $container->instance('app', $container);
        $container->instance('hook.filters', []);
        $container->instance(PluginManager::class, $pluginManager);
        Container::setInstance($container);
        Facade::setFacadeApplication($container);

        $form = (new PaymentService('', 9, null, true))->form();

        $this->assertSame('saved-app', $form['app_id']['value']);
        $this->expectException(ApiException::class);
        new PaymentService('', 9);
    }
}

/** 仅用于验证停用插件管理表单的测试支付实现。 */
final class FakeDisabledPaymentPlugin extends AbstractPlugin implements PaymentInterface
{
    public function form(): array
    {
        return [
            'app_id' => [
                'type' => 'string',
                'label' => 'APP ID',
            ],
        ];
    }

    public function pay($order): array
    {
        return [];
    }

    public function notify($params): bool
    {
        return true;
    }
}
