<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    /**
     * 创建由 Xboard 管理、供 Java Billing 适配器使用的产品映射。
     */
    public function up(): void
    {
        Schema::create('v2_billing_product_mapping', function (Blueprint $table) {
            $table->id();
            $table->string('provider', 24)->comment('支付渠道：STRIPE 或 GOOGLE_PLAY');
            $table->string('environment', 16)->comment('运行环境：TEST 或 PRODUCTION');
            $table->integer('plan_id')->comment('逻辑关联 v2_plan.id');
            $table->string('period', 32)->comment('Xboard 套餐周期键');
            $table->string('purchase_mode', 16)->comment('购买模式：ONE_TIME 或 AUTO_RENEW');
            $table->string('package_name', 255)->default('')->comment('Google Play 应用包名');
            $table->string('external_product_id', 255)->comment('渠道产品标识');
            $table->string('external_base_plan_id', 255)->default('')->comment('Google Play 基础方案标识');
            $table->string('external_offer_id', 255)->default('')->comment('可选渠道优惠标识');
            $table->unsignedInteger('version')->default(1)->comment('不可变映射版本');
            $table->boolean('enabled')->default(false)->comment('是否允许新购买使用此映射');
            $table->unsignedInteger('created_at')->comment('Unix 时间戳');
            $table->unsignedInteger('updated_at')->comment('Unix 时间戳');

            $table->unique(
                ['provider', 'environment', 'external_product_id', 'external_base_plan_id',
                    'external_offer_id', 'version'],
                'uk_v2_billing_mapping_external'
            );
            $table->unique(
                ['provider', 'environment', 'plan_id', 'period', 'purchase_mode', 'version'],
                'uk_v2_billing_mapping_plan'
            );
            $table->index(
                ['provider', 'environment', 'enabled'],
                'idx_v2_billing_mapping_available'
            );
        });
    }

    /**
     * 仅移除此迁移创建的产品映射表。
     */
    public function down(): void
    {
        Schema::dropIfExists('v2_billing_product_mapping');
    }
};
