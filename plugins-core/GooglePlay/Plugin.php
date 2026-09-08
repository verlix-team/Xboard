<?php

namespace Plugin\GooglePlay;

use App\Contracts\PaymentInterface;
use App\Exceptions\ApiException;
use App\Services\Plugin\AbstractPlugin;

/**
 * Google Play Billing 不提供 Xboard 用户结账路由，每个购买令牌都由 Java 验证。
 */
class Plugin extends AbstractPlugin implements PaymentInterface
{
    public function boot(): void
    {
        $this->filter('available_payment_methods', function (array $methods): array {
            $methods['GooglePlay'] = [
                'name' => $this->getConfig('display_name', 'Google Play'),
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
            'google_cloud_project_id' => [
                'label' => 'Google Cloud Project ID',
                'type' => 'string',
                'required' => true,
            ],
            'package_name' => [
                'label' => 'Android Package Name',
                'type' => 'string',
                'required' => true,
            ],
            'service_account_env' => [
                'label' => '服务账号凭证环境变量名',
                'type' => 'string',
                'required' => true,
                'description' => '只填写凭证引用，不填写 JSON 私钥内容',
            ],
            'rtdn_audience' => [
                'label' => 'RTDN OIDC Audience',
                'type' => 'string',
                'required' => true,
                'description' => 'Pub/Sub push subscription 配置的精确 audience',
            ],
            'rtdn_service_account_email' => [
                'label' => 'RTDN Push Service Account Email',
                'type' => 'string',
                'required' => true,
                'description' => '允许调用 RTDN 入口的唯一 Google 服务账号邮箱',
            ],
            'environment' => [
                'label' => '产品映射环境',
                'type' => 'select',
                'default' => 'TEST',
                'options' => [
                    ['label' => '测试', 'value' => 'TEST'],
                    ['label' => '生产', 'value' => 'PRODUCTION'],
                ],
            ],
        ];
    }

    public function pay($order): array
    {
        throw new ApiException('Google Play purchases must be started by Play Billing Client');
    }

    public function notify($params)
    {
        throw new ApiException('Google Play RTDN is handled by the Hop Java user backend only');
    }
}
