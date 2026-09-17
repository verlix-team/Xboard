<?php

namespace App\Services;

/** 固定管理产物的受控扩展：不改上游子模块，不猜测DOM或拦截网络请求。 */
class OriginalAdminAssetService
{
    public const ENTRY = 'assets/index-CEIYH7i8.js';
    public const SOURCE_SHA256 = 'f04f09a95bfdb04fa5132e336bf87c364d4e360cb95774872ff18eb69e9bcd77';

    public function __construct(private string $entryPath, private string $extensionPath) {}

    /** 扩展代码与集成代码均进入URL版本，防止浏览器沿用旧缓存。 */
    public function revision(): string
    {
        return hash('sha256', self::SOURCE_SHA256 . file_get_contents($this->extensionPath) . file_get_contents(__FILE__));
    }

    public function url(): string
    {
        // 同一目录保留原产物的import.meta.url/Monaco Worker相对资源位置。
        return '/assets/admin/assets/hop-plan-i18n-' . $this->revision() . '.js';
    }

    /** 内容指纹放在文件名，原部署资源解析器可完整检查CSS而非遗漏查询参数。 */
    public function stylesheetUrl(): string
    {
        return '/assets/admin/assets/hop-plan-i18n-' . hash_file('sha256', dirname($this->extensionPath) . '/plan-translations.css') . '.css';
    }

    /** 校验精确源码指纹与唯一锚点，固定包升级时必须重新审查集成。 */
    public function javascript(): string
    {
        $source = file_get_contents($this->entryPath);
        if (hash('sha256', $source) !== self::SOURCE_SHA256) {
            throw new \RuntimeException('原管理前端版本不匹配，不能应用套餐国际化扩展');
        }
        $anchors = [
            'async e=>{o(!0),OD(e).then(({data:e})=>' =>
                'async e=>{let v;try{v=window.HopPlanTranslations.payload(e,d,n)}catch(err){gE.error(err.message);return}o(!0),OD(v).then(({data:e})=>',
            'children:[Q.jsxs("div",{className:"grid grid-cols-1 gap-4 md:grid-cols-2",children:[Q.jsx(TYt,{control:d.control,name:"name",label:c("plan.form.name.label")' =>
                'children:[Q.jsx(window.HopPlanTranslations.editor(H),{form:d,plan:n,open:e}),Q.jsxs("div",{className:"grid grid-cols-1 gap-4 md:grid-cols-2",children:[Q.jsx(TYt,{control:d.control,name:"name",label:c("plan.form.name.label")',
        ];
        foreach ($anchors as $before => $after) {
            if (substr_count($source, $before) !== 1) {
                throw new \RuntimeException('原管理前端集成锚点不匹配');
            }
            $source = str_replace($before, $after, $source);
        }
        return file_get_contents($this->extensionPath) . "\n" . $source;
    }
}
