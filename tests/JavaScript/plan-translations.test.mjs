// 独立逻辑回归，不连接共享数据库或真实HTTP服务。
import { test } from 'node:test';
import assert from 'node:assert/strict';
import fs from 'node:fs';
import vm from 'node:vm';
const window = {};
vm.runInNewContext(fs.readFileSync(new URL('../../public/assets/hop-admin/plan-translations.js', import.meta.url), 'utf8'), { window, TextEncoder });
const api = window.HopPlanTranslations;
const React = { createElement() { return null; }, useState(initial) { return [typeof initial === 'function' ? initial() : initial, () => {}]; }, useEffect(effect) { effect(); } };
function prepare(plan, open=true) {
  const form = { getValues: () => ({ name: '原始名称', content: '原始描述' }) };
  api.editor(React)({ form, plan, open }); return form;
}
const existing = () => ({ id: 9, translationsAvailable: true, translationVersion: 'a'.repeat(64), defaultLocale: 'zh-CN', translations: [
  { locale: 'zh-CN', name: '中文套餐', content: '说明' }, { locale: 'ru-RU', name: 'Тариф', content: 'Описание' }] });
test('complete translations join native params without changing prices, traffic or canonical content', () => {
  const plan = existing(); const form = prepare(plan);
  const raw = { id: 9, prices: { monthly: 17.99 }, transfer_enable: 100, name: '原始名称', content: '原始描述', force_update: true };
  const saved = api.payload(raw, form, plan);
  assert.equal(saved.translationVersion, 'a'.repeat(64)); assert.equal(saved.translations.length, 2);
  for (const key of Object.keys(raw)) assert.deepEqual(saved[key], raw[key]);
  assert.equal(saved.translations[1].name, 'Тариф');
});
test('wrong plan identity or missing version never submits another plan draft', () => {
  const plan = existing(); const form = prepare(plan);
  assert.throws(() => api.payload({ id: 10 }, form, plan), /上下文/);
  plan.translationVersion = null; const unversioned = prepare(plan);
  assert.throws(() => api.payload({ id: 9 }, unversioned, plan), /版本/);
});
test('unavailable metadata and closed modal are fail closed', () => {
  const plan = existing(); plan.translationsAvailable = false;
  assert.throws(() => api.payload({ id: 9 }, prepare(plan), plan), /尚未就绪/);
  assert.throws(() => api.payload({ id: 9 }, prepare(existing(), false), existing()), /尚未就绪/);
});
test('new draft does not guess original language or automatically seed translations', () => {
  const initial = api.initial({ name: '原文', content: '原文' });
  assert.equal(initial.rows['zh-CN'].name, ''); assert.equal(initial.rows['en-US'].content, '');
  assert.throws(() => api.payload({ id: null }, prepare(null), null), /名称不能为空/);
});
test('default name and UTF8 description limits are enforced before native request', () => {
  const plan = existing(); plan.translations[0].name = '中'.repeat(256);
  assert.throws(() => api.payload({ id: 9 }, prepare(plan), plan), /超过/);
  plan.translations[0].name = '中文'; plan.translations[0].content = '俄'.repeat(21846);
  assert.throws(() => api.payload({ id: 9 }, prepare(plan), plan), /超过/);
});
test('new canonical fields come only from chosen default translation, not a second editor', () => {
  const plan = { id: null, translationsAvailable: true, defaultLocale: 'ru-RU', translations: [
    { locale: 'zh-CN', name: '中文套餐', content: '中文说明' },
    { locale: 'ru-RU', name: 'Тариф', content: 'Описание' }] };
  const saved = api.payload({ id: null, name: 'stale original', content: 'stale original', prices: { monthly: 10 } }, prepare(plan), plan);
  assert.equal(saved.name, 'Тариф'); assert.equal(saved.content, 'Описание');
  assert.equal(saved.defaultLocale, 'ru-RU'); assert.equal(saved.prices.monthly, 10);
  assert.equal('translationVersion' in saved, false);
});
