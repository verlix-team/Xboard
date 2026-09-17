<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;

/** 过渡管理端唯一翻译写入方；结构仍由 Java Flyway 管理，绝不运行时建表或猜测语言。 */
class PlanTranslationService
{
    public const TABLE = 'java_plan_translation';
    public const LOCALES = ['zh-CN', 'en-US', 'ru-RU'];

    /** 迁移未执行时允许查看原套餐，但禁止保存不完整的新合同。 */
    public function available(): bool
    {
        return Schema::hasTable(self::TABLE);
    }

    /** 批量读取全部翻译，避免套餐目录 N+1 查询，不修改订单快照。 */
    public function directory(array $planIds): array
    {
        $result = [];
        foreach (DB::table(self::TABLE)->whereIn('plan_id', $planIds)->orderBy('locale')->get() as $row) {
            $result[(int) $row->plan_id][] = [
                'locale' => $row->locale, 'name' => $row->name, 'content' => $row->content,
                'isDefault' => (bool) $row->is_default,
            ];
        }
        return $result;
    }

    /** 稳定内容版本；锁住套餐后比较，防止两个管理页面静默覆盖。 */
    public static function version(array $rows): string
    {
        usort($rows, fn ($a, $b) => strcmp($a['locale'], $b['locale']));
        return hash('sha256', json_encode($rows, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
    }

    /** 校验支持语言、完整默认项及特性支持标记，未填写的其他语言可省略。 */
    public static function normalize(array $rows, string $defaultLocale): array
    {
        if (!in_array($defaultLocale, self::LOCALES, true)) {
            throw ValidationException::withMessages(['defaultLocale' => '请选择支持的默认语言']);
        }
        $result = [];
        foreach ($rows as $row) {
            $locale = $row['locale'] ?? '';
            $name = $row['name'] ?? null;
            $content = $row['content'] ?? '';
            if (!in_array($locale, self::LOCALES, true) || isset($result[$locale])
                || !is_string($name) || trim($name) === '' || mb_strlen($name) > 255
                || !is_string($content) || strlen($content) > 65535) {
                throw ValidationException::withMessages(['translations' => '翻译语言不可重复，名称不能为空且不超过255字，描述不超过65535字节']);
            }
            $result[$locale] = ['locale' => $locale, 'name' => trim($name), 'content' => $content,
                'isDefault' => $locale === $defaultLocale];
        }
        if (!isset($result[$defaultLocale])) {
            throw ValidationException::withMessages(['defaultLocale' => '默认语言必须填写套餐名称和描述字段']);
        }
        $flags = self::supportFlags($result[$defaultLocale]['content']);
        foreach ($result as $row) {
            if (self::supportFlags($row['content']) !== $flags) {
                throw ValidationException::withMessages(['translations' => '各语言的特性数量、顺序和支持标记必须一致，不能混用特性JSON和Markdown']);
            }
        }
        ksort($result);
        return array_values($result);
    }

    /** 与 Java 用户端使用相同描述规则；翻译文字不改变支持/不支持状态。 */
    private static function supportFlags(string $content): ?array
    {
        try {
            $tree = json_decode($content, false, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            if (str_starts_with(ltrim($content), '[{')) {
                throw ValidationException::withMessages(['translations' => '套餐特性JSON格式错误']);
            }
            return null;
        }
        if (!is_array($tree)) return null;
        $flags = [];
        foreach ($tree as $item) {
            if (!is_object($item) || !isset($item->feature) || !is_string($item->feature)
                || !isset($item->support) || !is_bool($item->support)) {
                throw ValidationException::withMessages(['translations' => '套餐特性必须包含文字feature和布尔support']);
            }
            $flags[] = $item->support;
        }
        return $flags;
    }

    /** 必须在源套餐事务和同一连接内调用；默认唯一索引切换也在事务内完成。 */
    public function save(int $planId, array $rows): void
    {
        if (DB::connection()->transactionLevel() < 1) {
            throw new \LogicException('翻译保存必须处于套餐事务内');
        }
        $now = time();
        DB::table(self::TABLE)->where('plan_id', $planId)->update(['is_default' => 0]);
        DB::table(self::TABLE)->where('plan_id', $planId)->whereNotIn('locale', array_column($rows, 'locale'))->delete();
        foreach ($rows as $row) {
            DB::table(self::TABLE)->upsert([[
                'plan_id' => $planId, 'locale' => $row['locale'], 'name' => $row['name'],
                'content' => $row['content'], 'is_default' => $row['isDefault'] ? 1 : 0,
                'created_at' => $now, 'updated_at' => $now,
            ]], ['plan_id', 'locale'], ['name', 'content', 'is_default', 'updated_at']);
        }
    }
}
