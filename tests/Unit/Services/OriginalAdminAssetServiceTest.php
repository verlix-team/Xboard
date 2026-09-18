<?php

namespace Tests\Unit\Services;

use App\Services\OriginalAdminAssetService;
use PHPUnit\Framework\TestCase;

/** 只读固定子模块及扩展文件，不启动Laravel或连接数据库。 */
class OriginalAdminAssetServiceTest extends TestCase
{
    private function service(): OriginalAdminAssetService
    {
        $root = dirname(__DIR__, 3);
        return new OriginalAdminAssetService($root . '/public/assets/admin/' . OriginalAdminAssetService::ENTRY,
            $root . '/public/assets/hop-admin/plan-translations.js');
    }

    public function test_exact_original_bundle_has_editor_and_atomic_save_integration(): void
    {
        $service = $this->service();
        $script = $service->javascript();
        $this->assertStringContainsString('window.HopPlanTranslations.editor(H),{form:d,plan:n,open:e}', $script);
        $this->assertStringContainsString('window.HopPlanTranslations.payload(e,d,n)', $script);
        $this->assertStringContainsString('OD(v).then', $script);
        $this->assertStringNotContainsString('name:"name",label:c("plan.form.name.label")', $script);
        $this->assertStringNotContainsString('c("plan.form.content.label")', $script);
        $this->assertStringNotContainsString('c("plan.form.content.template.button")', $script);
        $this->assertStringContainsString('name:cy().min(1).max(255)', $script);
        $this->assertSame(1, substr_count($script, 'window.HopPlanTranslations.payload(e,d,n)'));
        $this->assertMatchesRegularExpression('~^/assets/admin/assets/hop-plan-i18n-[a-f0-9]{64}\.js$~', $service->url());
        $this->assertMatchesRegularExpression('~^/assets/admin/assets/hop-plan-i18n-[a-f0-9]{64}\.css$~', $service->stylesheetUrl());
        $this->assertSame($service->revision(), $service->revision());
    }

    public function test_wrong_upstream_is_rejected_without_mutating_any_artifact(): void
    {
        $root = dirname(__DIR__, 3);
        $service = new OriginalAdminAssetService(__FILE__, $root . '/public/assets/hop-admin/plan-translations.js');
        $this->expectException(\RuntimeException::class);
        $service->javascript();
    }
}
