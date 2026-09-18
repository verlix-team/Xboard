import http from 'node:http';
import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';
import { execFileSync } from 'node:child_process';
import assert from 'node:assert/strict';
import vm from 'node:vm';
const { chromium } = await import(process.env.PLAYWRIGHT_MODULE || new URL('../../../hop-user-web/node_modules/@playwright/test/index.mjs', import.meta.url).href);
const root=fileURLToPath(new URL('../../', import.meta.url)).replace(/\/$/, '');
const script=process.env.ORIGINAL_ADMIN_SCRIPT_FILE ? fs.readFileSync(process.env.ORIGINAL_ADMIN_SCRIPT_FILE) : execFileSync('php',['-r',`require '${root}/vendor/autoload.php'; $a=new App\\Services\\OriginalAdminAssetService('${root}/public/assets/admin/assets/index-CEIYH7i8.js','${root}/public/assets/hop-admin/plan-translations.js'); echo $a->javascript();`],{maxBuffer:30*1024*1024});
const uiLocale=process.env.ORIGINAL_ADMIN_PREVIEW_LOCALE || 'zh-CN';
assert.ok(['zh-CN','en-US','ru-RU'].includes(uiLocale));
const localeContext={window:{}};
vm.runInNewContext(fs.readFileSync(`${root}/public/assets/admin/locales/${uiLocale}.js`,'utf8'),localeContext);
const texts=localeContext.window.XBOARD_TRANSLATIONS[uiLocale].subscribe.plan;
const screenshot=(width)=>`/private/tmp/hop-original-admin-${width}${uiLocale==='zh-CN'?'':'-'+uiLocale}.png`;
const base={id:91,name:'test_original_admin',content:'原始说明',tags:[],group_id:1,transfer_enable:100,prices:{monthly:17.99},speed_limit:100,device_limit:5,capacity_limit:null,reset_traffic_method:0,users_count:0,active_users_count:0,show:1,sell:1,renew:1,
 translationsAvailable:true,translationVersion:'a'.repeat(64),defaultLocale:'zh-CN',translations:[{locale:'zh-CN',name:'中文测试套餐',content:'中文说明'}]};
let plan=structuredClone(base),saved=[];
const server=http.createServer((req,res)=>{
 const url=new URL(req.url,'http://localhost');
 if(url.pathname.startsWith('/api/v2/')){
  console.log('PREVIEW_API',url.pathname);let body='';req.on('data',b=>body+=b);req.on('end',()=>{
   let data=[];
   if(url.pathname.endsWith('/plan/fetch')) data=[plan];
   else if(url.pathname.endsWith('/plan/save')){const p=JSON.parse(body);saved.push(p);plan={...plan,...p,id:p.id??92,translationVersion:'b'.repeat(64)};data=true;}
   else if(url.pathname.endsWith('/server/group/fetch')) data=[{id:1,name:'默认分组'}];
   else if(url.pathname.endsWith('/user/info')) data={id:1,email:'test_preview@example.invalid',is_admin:1};
   else if(url.pathname.endsWith('/guest/comm/config')) data={app_name:'test_preview',is_email_verify:0};
   res.writeHead(200,{'Content-Type':'application/json'});res.end(JSON.stringify({data}));
  });return;
 }
 if(url.pathname==='/assets/admin/assets/preview-i18n.js'){res.writeHead(200,{'Content-Type':'application/javascript'});res.end(script);return;}
 if(url.pathname.startsWith('/assets/')){
  const target=path.resolve(root+'/public','.'+url.pathname);
  if(!target.startsWith(root+'/public/assets/')||!fs.existsSync(target)){res.writeHead(404);res.end();return;}
  res.writeHead(200,{'Content-Type':target.endsWith('.css')?'text/css; charset=UTF-8':target.endsWith('.js')?'application/javascript; charset=UTF-8':'application/octet-stream'});res.end(fs.readFileSync(target));return;
 }
 res.writeHead(200,{'Content-Type':'text/html; charset=UTF-8'});res.end(`<!doctype html><html><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1"><script>window.settings={base_url:'/',secure_path:'test_admin',title:'test_preview'};</script><script src="/assets/admin/locales/en-US.js"></script><script src="/assets/admin/locales/zh-CN.js"></script><script src="/assets/admin/locales/ru-RU.js"></script><link rel="stylesheet" href="/assets/admin/assets/index-DiYa-_z_.css"><link rel="stylesheet" href="/assets/hop-admin/plan-translations.css"><script type="module" src="/assets/admin/assets/preview-i18n.js"></script></head><body><div id="root"></div></body></html>`);
});
let browser;
try{
 await new Promise((resolve,reject)=>{server.once('error',reject);server.listen(0,'127.0.0.1',resolve);});
 browser=await chromium.launch({headless:true});
 const context=await browser.newContext({viewport:{width:1440,height:1100},locale:uiLocale});
 const page=await context.newPage();const errors=[];page.on('pageerror',e=>errors.push(e.message));
 await page.addInitScript(locale=>{localStorage.setItem('XBOARD_ACCESS_TOKEN',JSON.stringify({value:'test_preview_only',time:Date.now(),expire:null}));localStorage.setItem('i18nextLng',locale);},uiLocale);
 await page.goto(`http://127.0.0.1:${server.address().port}/#/finance/plan`);
 try{await page.getByText('test_original_admin',{exact:true}).waitFor({timeout:15000});}catch(e){console.log('PREVIEW_STATE',page.url(),(await page.locator('body').innerText()).slice(0,1300),errors);await page.screenshot({path:'/private/tmp/hop-original-admin-debug.png'});throw e;}
 const row=page.getByRole('row').filter({hasText:'test_original_admin'});
 console.log('ROW_BUTTONS',await row.locator('button').allTextContents());
 await row.locator('button').filter({has:page.locator('span.sr-only')}).first().click();
 const region=page.getByRole('region',{name:'套餐内容国际化'});await region.waitFor();
 const dialog=page.getByRole('dialog');
 async function assertSingleEditor(){
  assert.equal(await dialog.getByText(texts.form.name.label,{exact:true}).count(),0);
  assert.equal(await dialog.getByText(texts.form.content.label,{exact:true}).count(),0);
  assert.equal(await dialog.locator('input[name="name"], textarea[name="content"], .rc-md-editor').count(),0);
  assert.equal(await dialog.getByRole('button',{name:texts.form.content.template.button,exact:true}).count(),0);
  assert.equal(await dialog.getByRole('region',{name:'套餐内容国际化'}).count(),1);
 }
 await assertSingleEditor();
 await region.getByLabel('中文套餐名称').fill('');
 await dialog.getByRole('button',{name:texts.form.submit.submit,exact:true}).click();
 await page.getByText('中文套餐名称不能为空。',{exact:true}).waitFor();
 assert.equal(saved.length,0);assert.equal(await dialog.isVisible(),true);
 await region.getByLabel('中文套餐名称').fill('中文测试套餐');
 await region.getByRole('button',{name:/Русский/}).click();
 await region.getByLabel('Русский套餐名称').fill('Тестовый тариф');
 await region.getByLabel('Русский套餐说明').fill('Описание тестового тарифа');
 await region.getByRole('button',{name:/English/}).click();
 await region.getByLabel('English套餐名称').fill('Test plan');await region.getByLabel('English套餐说明').fill('Test description');
 await region.getByRole('button',{name:/Русский/}).click();assert.equal(await region.getByLabel('Русский套餐名称').inputValue(),'Тестовый тариф');
 assert.equal(await region.getByLabel('默认回退语言').inputValue(),'zh-CN');
 await region.locator('h3').scrollIntoViewIfNeeded();await page.screenshot({path:screenshot(1440)});
 console.log('DIALOG_BUTTONS',await dialog.getByRole('button').allTextContents());
 await dialog.getByRole('button',{name:texts.form.submit.submit,exact:true}).click();
 await page.waitForFunction(()=>!document.querySelector('.hop-plan-translations'));
 assert.equal(saved.length,1);assert.equal(saved[0].translations.length,3);assert.equal(saved[0].translationVersion,'a'.repeat(64));assert.equal(saved[0].prices.monthly,17.99);assert.equal(saved[0].transfer_enable,100);
 assert.equal(saved[0].name,base.name);assert.equal(saved[0].content,base.content);
 await row.locator('button').filter({has:page.locator('span.sr-only')}).first().click();await region.waitFor();
 await region.getByRole('button',{name:/Русский/}).click();assert.equal(await region.getByLabel('Русский套餐名称').inputValue(),'Тестовый тариф');
 await page.setViewportSize({width:390,height:844});await region.getByRole('button',{name:/Русский/}).click();await region.locator('h3').scrollIntoViewIfNeeded();await page.screenshot({path:screenshot(390)});
 await assertSingleEditor();
 await region.getByLabel('Русский套餐名称').fill('Несохранённый черновик');
 await dialog.getByRole('button',{name:texts.form.submit.cancel,exact:true}).click();
 await page.getByRole('button',{name:texts.add,exact:true}).click();await region.waitFor();
 await assertSingleEditor();
 assert.equal(await region.getByLabel('中文套餐名称').inputValue(),'');
 assert.equal(await region.getByRole('button',{name:'将原内容导入当前语言',exact:true}).count(),0);
 const newDefaultName='New plan '+'n'.repeat(246);
 await region.getByRole('button',{name:/English/}).click();
 await region.getByLabel('English套餐名称').fill(newDefaultName);
 await region.getByLabel('English套餐说明').fill('New default description');
 await region.getByLabel('默认回退语言').selectOption('en-US');
 await region.getByRole('button',{name:/中文/}).click();
 await region.getByLabel('中文套餐名称').fill('新中文套餐');await region.getByLabel('中文套餐说明').fill('新中文说明');
 await region.getByRole('button',{name:/Русский/}).click();
 await region.getByLabel('Русский套餐名称').fill('Новый тариф');await region.getByLabel('Русский套餐说明').fill('Новое описание');
 await dialog.locator('input[name="transfer_enable"]').fill('100');
 await dialog.locator('input[name="prices.monthly"]').fill('9.99');
 await dialog.getByRole('button',{name:texts.form.submit.submit,exact:true}).click();
 await page.waitForFunction(()=>!document.querySelector('.hop-plan-translations'));
 assert.equal(saved.length,2);assert.equal(saved[1].id,null);assert.equal(saved[1].translations.length,3);
 assert.equal(saved[1].name,newDefaultName);assert.equal(saved[1].name.length,255);
 assert.equal(saved[1].content,'New default description');assert.equal(saved[1].defaultLocale,'en-US');
 assert.equal('translationVersion' in saved[1],false);assert.equal(Number(saved[1].prices.monthly),9.99);
 assert.equal(Number(saved[1].transfer_enable),100);
 await page.setViewportSize({width:1440,height:1100});
 const newRow=page.getByRole('row').filter({hasText:newDefaultName});
 await newRow.locator('button').filter({has:page.locator('span.sr-only')}).first().click();await region.waitFor();
 assert.equal(await region.getByLabel('默认回退语言').inputValue(),'en-US');
 await region.getByRole('button',{name:/Русский/}).click();
 await region.getByLabel('Русский套餐名称').fill('');await region.getByLabel('Русский套餐说明').fill('');
 await dialog.getByRole('button',{name:texts.form.submit.submit,exact:true}).click();
 await page.waitForFunction(()=>!document.querySelector('.hop-plan-translations'));
 assert.equal(saved.length,3);assert.equal(saved[2].id,92);assert.equal(saved[2].translationVersion,'b'.repeat(64));
 assert.equal(saved[2].translations.length,2);assert.equal(saved[2].translations.some(r=>r.locale==='ru-RU'),false);
 assert.equal(saved[2].name,newDefaultName);assert.equal(saved[2].content,'New default description');
 assert.equal(await page.evaluate(()=>document.documentElement.scrollWidth>innerWidth),false);assert.deepEqual(errors,[]);
 console.log(`ORIGINAL_FIXED_BUNDLE_BROWSER_OK uiLocale=${uiLocale} originalDialog=true singleEditor=true threeLanguages=true nativeSubmit=true reopen=true emptyDefaultRejected=true cancelDraftDiscarded=true deleteNonDefault=true existingCanonicalUnchanged=true newDefaultCanonical=true new255CharacterName=true unchangedPriceAndTraffic=true viewports=1440,390 pageErrors=0`);
}finally{if(browser)await browser.close();await new Promise(r=>server.close(r));}
