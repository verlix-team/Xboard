<?php

namespace Plugin\StripeGpay;

use App\Contracts\PaymentInterface;
use App\Exceptions\ApiException;
use App\Services\Plugin\AbstractPlugin;

/**
 * Xboard 只负责配置，Java 是用户支付的唯一执行方。
 */
class Plugin extends AbstractPlugin implements PaymentInterface
{
    public function boot(): void
    {
        $this->filter('available_payment_methods', function (array $methods): array {
            $methods['StripeGPay'] = [
                'name' => $this->getConfig('display_name', 'Google Pay'),
                'icon' => $this->getConfig('icon', 'G'),
                'plugin_code' => $this->getPluginCode(),
                'type' => 'plugin',
            ];
            return $methods;
        });
    }

    public function form(): array
    {
        return [
            'secret_key_env' => [
                'label' => 'Stripe Secret 环境变量名',
                'type' => 'string',
                'required' => true,
                'description' => '只填写环境变量名称，不填写 sk_ 开头的真实密钥',
            ],
            'webhook_secret_env' => [
                'label' => 'Webhook Secret 环境变量名',
                'type' => 'string',
                'required' => true,
                'description' => '只填写环境变量名称，不填写 whsec_ 开头的真实密钥',
            ],
            'livemode' => [
                'label' => '生产模式',
                'type' => 'boolean',
                'default' => false,
                'description' => '首次部署必须保持关闭并使用 Stripe 测试模式验收',
            ],
            'product_name' => [
                'label' => '收银台商品名称',
                'type' => 'string',
                'default' => 'Hop subscription',
            ],
            'reauthorization_return_url' => [
                'label' => '重新授权返回地址',
                'type' => 'string',
                'required' => true,
                'description' => '固定 HTTPS Billing 返回页，不接受客户端动态传入',
            ],
        ];
    }

    public function pay($order): array
    {
        throw new ApiException('StripeGPay is executed by the Hop Java user backend only');
    }

    public function notify($params)
    {
        throw new ApiException('StripeGPay webhook is handled by the Hop Java user backend only');
    }
}
