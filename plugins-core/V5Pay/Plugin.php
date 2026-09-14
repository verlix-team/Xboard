<?php

namespace Plugin\V5Pay;

use App\Contracts\PaymentInterface;
use App\Exceptions\ApiException;
use App\Services\Plugin\AbstractPlugin;

/**
 * Xboard 只维护 V5Pay 支付实例配置，Java 是发起、回调和查询的唯一执行方。
 */
class Plugin extends AbstractPlugin implements PaymentInterface
{
    public function boot(): void
    {
        $this->filter('available_payment_methods', function (array $methods): array {
            $methods['V5Pay'] = [
                'name' => $this->getConfig('display_name', 'V5Pay'),
                'icon' => $this->getConfig('icon', 'V5'),
                'plugin_code' => $this->getPluginCode(),
                'type' => 'plugin',
            ];
            return $methods;
        });
    }

    public function form(): array
    {
        return [
            'merchant_no' => [
                'label' => 'V5Pay Merchant No',
                'type' => 'string',
                'required' => true,
            ],
            'app_key' => [
                'label' => 'V5Pay App Key',
                'type' => 'string',
                'required' => true,
            ],
            'secret_key_env' => [
                'label' => 'Secret Key 环境变量名',
                'type' => 'string',
                'required' => true,
                'description' => '只填写环境变量名称，不填写真实 secretKey',
            ],
            'environment' => [
                'label' => '运行环境',
                'type' => 'select',
                'default' => 'UAT',
                'options' => [
                    ['label' => 'UAT', 'value' => 'UAT'],
                    ['label' => '生产', 'value' => 'PRODUCTION'],
                ],
            ],
            'product_name' => [
                'label' => '商户或应用名称',
                'type' => 'string',
                'required' => true,
            ],
            'product_website' => [
                'label' => '商品 HTTPS 站点',
                'type' => 'string',
                'required' => true,
                'description' => '俄罗斯商品报备使用，必须填写 HTTPS 地址',
            ],
        ];
    }

    public function pay($order): array
    {
        throw new ApiException('V5Pay is executed by the Hop Java user backend only');
    }

    public function notify($params)
    {
        throw new ApiException('V5Pay callback and query are handled by the Hop Java user backend only');
    }
}
