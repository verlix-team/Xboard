/* 原Xboard套餐弹窗扩展。使用原包React及原Form实例，不读取Token、不猜测语言。 */
(() => {
  'use strict';
  const locales = ['zh-CN', 'en-US', 'ru-RU'];
  const labels = { 'zh-CN': '中文', 'en-US': 'English', 'ru-RU': 'Русский' };
  const drafts = new WeakMap();
  const components = new WeakMap();
  function initial(plan) {
    const rows = Object.fromEntries(locales.map(locale => [locale, { locale, name: '', content: '' }]));
    for (const row of plan?.translations || []) {
      if (locales.includes(row.locale)) rows[row.locale] = { locale: row.locale, name: row.name || '', content: row.content || '' };
    }
    return { id: plan?.id ?? null, rows, defaultLocale: plan?.defaultLocale || 'zh-CN',
      version: plan?.translationVersion, available: !plan || plan.translationsAvailable === true };
  }
  function payload(params, form, plan) {
    const draft = drafts.get(form);
    if (!draft || !draft.available) throw new Error('套餐国际化尚未就绪，请重新打开或先执行V026迁移。');
    if (String(params.id ?? '') !== String(draft.id ?? '') || String(plan?.id ?? '') !== String(draft.id ?? '')) {
      throw new Error('套餐编辑上下文已变化，请重新打开，未提交任何修改。');
    }
    if (draft.id && !/^[a-f0-9]{64}$/.test(draft.version || '')) throw new Error('缺少翻译版本，请重新打开套餐。');
    const translations = [];
    for (const locale of locales) {
      const row = draft.rows[locale];
      if (!row.name.trim() && !row.content.trim() && locale !== draft.defaultLocale) continue;
      if (!row.name.trim()) throw new Error(`${labels[locale]}套餐名称不能为空。`);
      if ([...row.name].length > 255 || new TextEncoder().encode(row.content).length > 65535) {
        throw new Error(`${labels[locale]}名称超过255字或说明超过65535字节。`);
      }
      translations.push({ locale, name: row.name.trim(), content: row.content });
    }
    // 完整集合与原套餐参数一起走原save事务，服务端校验JSON支持标记及409版本冲突。
    const defaults = draft.rows[draft.defaultLocale];
    return { ...params, ...(!draft.id ? { name: defaults.name.trim(), content: defaults.content } : {}),
      translations, defaultLocale: draft.defaultLocale,
      ...(draft.id ? { translationVersion: draft.version } : {}) };
  }
  // 仅新建时填充原表单必填内部字段；已有套餐不因改翻译而覆盖内部业务名称/说明。
  function syncCreateFields(form, draft) {
    if (draft.id !== null) return;
    const row = draft.rows[draft.defaultLocale];
    form.setValue('name', row.name.trim(), { shouldDirty: true });
    form.setValue('content', row.content, { shouldDirty: true });
  }
  function editor(React) {
    if (components.has(React)) return components.get(React);
    const h = React.createElement;
    function Editor({ form, plan, open }) {
      const [draft, setDraft] = React.useState(() => initial(plan));
      const [locale, setLocale] = React.useState('zh-CN');
      React.useEffect(() => {
        if (open) { const fresh = initial(plan); drafts.set(form, fresh); setDraft(fresh); setLocale(fresh.defaultLocale); }
        else drafts.delete(form);
        return () => drafts.delete(form);
      }, [form, plan, open]);
      function update(next) { drafts.set(form, next); syncCreateFields(form, next); setDraft(next); }
      function field(key, value) {
        update({ ...draft, rows: { ...draft.rows, [locale]: { ...draft.rows[locale], [key]: value } } });
      }
      return h('section', { className: 'hop-plan-translations', 'aria-label': '套餐内容国际化' },
        h('h3', null, '套餐内容国际化'),
        h('p', null, '套餐名称和说明统一在这里编辑，用户Web和客户端按语言展示；未填写的语言回退到默认语言。'),
        !draft.available && h('p', { role: 'alert' }, '国际化结构尚未迁移，不能保存套餐。请先执行Java V026。'),
        h('div', { className: 'hop-plan-language-tabs', role: 'group', 'aria-label': '内容语言' },
          ...locales.map(value => h('button', { key: value, type: 'button', 'aria-pressed': locale === value,
            onClick: () => setLocale(value) }, labels[value] + (draft.rows[value].name.trim() ? ' · 已填写' : ' · 未填写')))),
        h('label', null, '默认回退语言', h('select', { value: draft.defaultLocale,
          onChange: event => update({ ...draft, defaultLocale: event.target.value }) },
          ...locales.map(value => h('option', { key: value, value }, labels[value])))),
        h('label', null, labels[locale] + '套餐名称', h('input', { key: locale + '-name', type: 'text',
          value: draft.rows[locale].name, maxLength: 255, onChange: event => field('name', event.target.value) })),
        h('label', null, labels[locale] + '套餐说明', h('textarea', { key: locale + '-content', rows: 6,
          value: draft.rows[locale].content, onChange: event => field('content', event.target.value) })),
        h('p', null, '说明支持Markdown、HTML和feature/support JSON原文；各语言特性数量、顺序和支持标记须一致。切换语言保留未提交内容。'),
        plan?.id && h('button', { type: 'button', onClick: () => {
          if (!window.confirm(`确认原内容确实属于${labels[locale]}，并覆盖此语言草稿？`)) return;
          const original = form.getValues();
          update({ ...draft, rows: { ...draft.rows, [locale]: { locale, name: original.name || '', content: original.content || '' } } });
        } }, '将原内容导入当前语言'),
        h('p', null, '全空的非默认语言不会保存；清空已有语言表示删除该翻译。点击原弹窗“提交”统一保存。'));
    }
    components.set(React, Editor);
    return Editor;
  }
  window.HopPlanTranslations = Object.freeze({ editor, payload, initial });
})();
