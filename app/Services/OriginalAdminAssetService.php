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
            'children:[Q.jsxs("div",{className:"grid grid-cols-1 gap-4 md:grid-cols-2",children:[Q.jsx(TYt,{control:d.control,name:"name",label:c("plan.form.name.label"),placeholder:c("plan.form.name.placeholder")}),' =>
                'children:[Q.jsx(window.HopPlanTranslations.editor(H),{form:d,plan:n,open:e}),Q.jsxs("div",{className:"grid grid-cols-1 gap-4",children:[',
            ',Q.jsx($y,{control:d.control,name:"content",render:({field:e})=>Q.jsxs(Gy,{className:"space-y-3",children:[Q.jsxs("div",{className:"flex items-center justify-between",children:[Q.jsx(Zy,{children:c("plan.form.content.label")}),Q.jsxs("div",{className:"flex items-center gap-2",children:[Q.jsxs(Lf,{variant:"outline",size:"sm",className:"h-8",type:"button",onClick:()=>e.onChange(c("plan.form.content.template.content")),children:[Q.jsx(Alt,{className:"mr-2 h-3.5 w-3.5"}),c("plan.form.content.template.button")]}),Q.jsxs(Lf,{variant:"outline",size:"sm",className:"h-8",type:"button",onClick:()=>l(!a),children:[a?Q.jsx(Vat,{className:"mr-2 h-3.5 w-3.5"}):Q.jsx(Wat,{className:"mr-2 h-3.5 w-3.5"}),c(a?"plan.form.content.preview_button.hide":"plan.form.content.preview_button.show")]})]})]}),Q.jsxs("div",{className:"grid gap-4 "+(a?"grid-cols-1 lg:grid-cols-2":"grid-cols-1"),children:[Q.jsx(Yy,{children:Q.jsx(f2t,{style:{height:"300px"},value:e.value||"",renderHTML:e=>u.render(e),onChange:({text:t})=>e.onChange(t),config:{view:{menu:!0,md:!0,html:!1},canView:{menu:!0,md:!0,html:!1,fullScreen:!1,hideMenu:!1}},className:"rounded-md border"})}),a&&Q.jsx("div",{className:"prose prose-sm dark:prose-invert h-[300px] max-w-none overflow-y-auto rounded-md border bg-muted/20 p-4",children:Q.jsx("div",{dangerouslySetInnerHTML:{__html:u.render(e.value||"")}})})]}),Q.jsx(Xy,{className:"text-xs",children:c("plan.form.content.description")}),Q.jsx(Qy,{})]})})' => '',
            'r6t=py({id:dy().nullable(),group_id:my([dy(),cy()]).nullable().optional(),name:cy().min(1).max(250),' =>
                'r6t=py({id:dy().nullable(),group_id:my([dy(),cy()]).nullable().optional(),name:cy().min(1).max(255),',
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
