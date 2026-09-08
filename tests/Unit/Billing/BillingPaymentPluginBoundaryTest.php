<?php

namespace Tests\Unit\Billing;

use App\Exceptions\ApiException;
use PHPUnit\Framework\TestCase;

/** 新支付插件提供管理表单，但禁止 PHP 执行用户支付。 */
class BillingPaymentPluginBoundaryTest extends TestCase
{
    public function test_stripe_plugin_keeps_secrets_as_environment_references(): void
    {
        require_once dirname(__DIR__, 3) . '/plugins-core/StripeGpay/Plugin.php';
        $plugin = new \Plugin\StripeGpay\Plugin('stripe_gpay');

        $form = $plugin->form();

        $this->assertArrayHasKey('secret_key_env', $form);
        $this->assertArrayHasKey('webhook_secret_env', $form);
        $this->assertStringContainsString('环境变量名称', $form['secret_key_env']['description']);
        $this->expectException(ApiException::class);
        $plugin->pay([]);
    }

    public function test_google_play_plugin_refuses_xboard_user_checkout(): void
    {
        require_once dirname(__DIR__, 3) . '/plugins-core/GooglePlay/Plugin.php';
        $plugin = new \Plugin\GooglePlay\Plugin('google_play');

        $this->assertArrayHasKey('service_account_env', $plugin->form());
        $this->assertArrayHasKey('rtdn_audience', $plugin->form());
        $this->assertArrayHasKey('rtdn_service_account_email', $plugin->form());
        $this->expectException(ApiException::class);
        $plugin->pay([]);
    }

    public function test_product_mapping_migration_uses_versioned_unique_keys(): void
    {
        $migration = file_get_contents(dirname(__DIR__, 3)
            . '/database/migrations/2026_09_07_000001_create_v2_billing_product_mapping.php');

        $this->assertStringContainsString("Schema::create('v2_billing_product_mapping'", $migration);
        $this->assertStringContainsString('uk_v2_billing_mapping_external', $migration);
        $this->assertStringContainsString('uk_v2_billing_mapping_plan', $migration);
        $this->assertStringContainsString("->default(false)", $migration);
    }

    public function test_mapping_controller_protects_play_purchase_context_references(): void
    {
        $controller = file_get_contents(dirname(__DIR__, 3)
            . '/app/Http/Controllers/V2/Admin/BillingProductController.php');

        $this->assertStringContainsString('java_billing_agreement', $controller);
        $this->assertStringContainsString('java_google_play_purchase_context', $controller);
        $this->assertStringContainsString("'monthly', 'quarterly', 'half_yearly'", $controller);
    }

    public function test_billing_ledger_admin_api_is_read_only_and_omits_credentials(): void
    {
        $controller = file_get_contents(dirname(__DIR__, 3)
            . '/app/Http/Controllers/V2/Admin/BillingLedgerController.php');
        $routes = file_get_contents(dirname(__DIR__, 3) . '/app/Http/Routes/V2/AdminRoute.php');

        $this->assertStringContainsString("'prefix' => 'billing/ledger'", $routes);
        $this->assertStringContainsString("\$router->get('/agreements'", $routes);
        $this->assertStringContainsString("\$router->get('/cycles'", $routes);
        $this->assertStringContainsString("\$router->get('/events'", $routes);
        $this->assertStringNotContainsString('java_billing_credential', $controller);
        $this->assertStringNotContainsString('encrypted_value', $controller);
    }
}
