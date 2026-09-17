<?php

namespace Tests\Unit\Services;

use App\Services\PlanTranslationService;
use App\Http\Requests\Admin\PlanSave;
use Illuminate\Container\Container;
use Illuminate\Support\Facades\Facade;
use Illuminate\Translation\ArrayLoader;
use Illuminate\Translation\Translator;
use Illuminate\Validation\Factory;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\TestCase;

/** 纯内容验证，不启动Laravel应用、不读取.env、不连接共享库。 */
class PlanTranslationServiceTest extends TestCase
{
    private $previousFacades;

    protected function setUp(): void
    {
        $this->previousFacades = Facade::getFacadeApplication();
        $container = new Container();
        $container->instance('validator', new Factory(new Translator(new ArrayLoader(), 'en'), $container));
        Facade::clearResolvedInstances();
        Facade::setFacadeApplication($container);
    }

    protected function tearDown(): void
    {
        Facade::clearResolvedInstances();
        Facade::setFacadeApplication($this->previousFacades);
        parent::tearDown();
    }

    public function test_default_locale_and_empty_description_are_preserved(): void
    {
        $rows = PlanTranslationService::normalize([
            ['locale' => 'ru-RU', 'name' => ' Тариф ', 'content' => null],
            ['locale' => 'zh-CN', 'name' => '标准套餐', 'content' => '说明'],
        ], 'zh-CN');
        $this->assertSame(['ru-RU', 'zh-CN'], array_column($rows, 'locale'));
        $this->assertSame('Тариф', $rows[0]['name']);
        $this->assertSame('', $rows[0]['content']);
        $this->assertSame([false, true], array_column($rows, 'isDefault'));
    }

    public function test_missing_default_unknown_duplicate_and_partial_names_are_rejected(): void
    {
        $valid = ['locale' => 'zh-CN', 'name' => '套餐', 'content' => '说明'];
        foreach ([[$valid], [$valid, $valid], [$valid, ['locale' => 'fr-FR', 'name' => 'Plan']],
            [$valid, ['locale' => 'ru-RU', 'name' => ' ', 'content' => 'Описание']]] as $index => $rows) {
            try {
                PlanTranslationService::normalize($rows, $index === 0 ? 'en-US' : 'zh-CN');
                $this->fail('不完整翻译不能保存');
            } catch (ValidationException $e) {
                $this->assertNotEmpty($e->errors());
            }
        }
    }

    public function test_features_allow_translated_text_but_not_changed_support_flags(): void
    {
        $zh = ['locale' => 'zh-CN', 'name' => '套餐', 'content' => '[{"feature":"流媒体","support":false}]'];
        $ru = ['locale' => 'ru-RU', 'name' => 'Тариф', 'content' => '[{"feature":"Стриминг","support":false}]'];
        $this->assertCount(2, PlanTranslationService::normalize([$zh, $ru], 'zh-CN'));
        $this->expectException(ValidationException::class);
        $ru['content'] = '[{"feature":"Стриминг","support":true}]';
        PlanTranslationService::normalize([$zh, $ru], 'zh-CN');
    }

    public function test_json_markdown_mismatch_and_malformed_features_are_rejected(): void
    {
        $zh = ['locale' => 'zh-CN', 'name' => '套餐', 'content' => '[{"feature":"流媒体","support":false}]'];
        foreach (['Описание', '[{"feature":"x"}', '[{"feature":"x","support":1}]', '[{}]'] as $content) {
            try {
                PlanTranslationService::normalize([$zh, ['locale' => 'ru-RU', 'name' => 'Тариф', 'content' => $content]], 'zh-CN');
                $this->fail('错误结构不能保存');
            } catch (ValidationException $e) {
                $this->assertNotEmpty($e->errors());
            }
        }
    }

    public function test_utf8_storage_limit_and_version_detect_actual_content_changes(): void
    {
        $zh = ['locale' => 'zh-CN', 'name' => '套餐', 'content' => '说明', 'isDefault' => true];
        $ru = ['locale' => 'ru-RU', 'name' => 'Тариф', 'content' => 'Описание', 'isDefault' => false];
        $this->assertSame(PlanTranslationService::version([$zh, $ru]), PlanTranslationService::version([$ru, $zh]));
        $changed = $ru; $changed['content'] = 'Новое описание';
        $this->assertNotSame(PlanTranslationService::version([$zh, $ru]), PlanTranslationService::version([$zh, $changed]));
        $this->expectException(ValidationException::class);
        PlanTranslationService::normalize([array_merge($zh, ['content' => str_repeat('中', 21846)])], 'zh-CN');
    }

    public function test_request_rules_require_supported_unique_translations_and_valid_version(): void
    {
        $factory = Facade::getFacadeApplication()['validator'];
        $rules = (new PlanSave())->rules();
        $base = ['name' => '原名称', 'transfer_enable' => 100, 'defaultLocale' => 'ru-RU',
            'translations' => [['locale' => 'ru-RU', 'name' => 'Тариф', 'content' => null]]];
        $this->assertTrue($factory->make($base, $rules)->passes());
        $this->assertFalse($factory->make(array_diff_key($base, ['translations' => true]), $rules)->passes());
        $duplicate = $base; $duplicate['translations'][] = $base['translations'][0];
        $this->assertFalse($factory->make($duplicate, $rules)->passes());
        $invalid = $base; $invalid['translations'][0]['locale'] = 'fr-FR';
        $this->assertFalse($factory->make($invalid, $rules)->passes());
        $this->assertFalse($factory->make($base + ['translationVersion' => 'bad-version'], $rules)->passes());
    }
}
