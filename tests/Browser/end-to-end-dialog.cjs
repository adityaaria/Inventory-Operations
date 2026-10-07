const fs = require('node:fs');
const path = require('node:path');
(async () => {
 const tabs = await (await fetch('http://127.0.0.1:9223/json')).json();
 const ws = new WebSocket(tabs.find(t => t.type === 'page').webSocketDebuggerUrl);
 await new Promise((resolve, reject) => { ws.onopen = resolve; ws.onerror = reject; });
 let next = 1; const pending = new Map(); const events = new Map();
 ws.onmessage = ({data}) => { const message = JSON.parse(data); if (message.id) { const p = pending.get(message.id); pending.delete(message.id); message.error ? p.reject(message.error) : p.resolve(message.result); } else { events.get(message.method)?.(); } };
 const call = (method, params = {}) => new Promise((resolve, reject) => { const id = next++; pending.set(id, {resolve, reject}); ws.send(JSON.stringify({id, method, params})); });
 const evaluate = async expression => { const r = await call('Runtime.evaluate', {expression, awaitPromise: true, returnByValue: true}); if (r.exceptionDetails) throw new Error(JSON.stringify(r.exceptionDetails)); return r.result.value; };
 const navigate = async route => { const loaded = new Promise(resolve => events.set('Page.loadEventFired', resolve)); await call('Page.navigate', {url: (process.env.E2E_URL || 'http://localhost:18085') + route}); await loaded; await evaluate('new Promise(resolve => requestAnimationFrame(() => requestAnimationFrame(resolve)))'); };
 await call('Page.enable'); await call('Runtime.enable');
 const errors = []; events.set('Runtime.exceptionThrown', () => errors.push('Browser JS exception'));
 await call('Emulation.setDeviceMetricsOverride', {width:1440,height:1000,deviceScaleFactor:1,mobile:false});
 await navigate('/login');
 await evaluate(`fetch('/login', {method:'POST', body:new URLSearchParams({email:'admin@example.test',password:'password',csrf_token:document.querySelector('[name=csrf_token]').value})}).then(r=>{if(!r.ok) throw new Error('Login failed')})`);

 const results=[];
 const waitFor=expression=>evaluate(`new Promise((resolve,reject)=>{let attempts=0;function check(){if(${expression})return resolve(true);if(++attempts>300)return reject(new Error('Timed out'));requestAnimationFrame(check);}check();})`);
 const measure=async selector=>evaluate(`(()=>{const panel=document.querySelector(${JSON.stringify(selector)});const footer=panel.querySelector('.modal-footer');const buttons=[...footer.querySelectorAll('button')];const rect=footer.getBoundingClientRect();const first=buttons[0].getBoundingClientRect(),last=buttons.at(-1).getBoundingClientRect();return {headers:panel.querySelectorAll('.modal-header').length,duplicate:panel.querySelectorAll('.modal-body .page-header').length,border:getComputedStyle(footer).borderTopWidth,between:getComputedStyle(footer).justifyContent,left:first.left,right:last.right,footerLeft:rect.left,footerRight:rect.right,below:first.top>rect.top&&last.top>rect.top,direction:getComputedStyle(footer).flexDirection,panelOverflow:panel.scrollWidth>panel.clientWidth+1,panelLeft:panel.getBoundingClientRect().left,panelRight:panel.getBoundingClientRect().right,panelHeight:panel.getBoundingClientRect().height,viewportWidth:innerWidth,viewportHeight:innerHeight};})()`);
 const verify=result=>{if(result.headers!==1||result.duplicate||result.border!=='1px'||result.between!=='space-between'||!result.below||result.direction!=='row'||result.left>=result.right||result.panelOverflow||result.panelLeft< -1||result.panelRight>result.viewportWidth+1||result.panelHeight>result.viewportHeight)throw new Error(JSON.stringify(result));};
 for(const [width,height] of [[320,568],[390,844],[568,320],[768,1024],[1440,900]]){
  await call('Emulation.setDeviceMetricsOverride',{width,height,deviceScaleFactor:1,mobile:width<=960});
  for(const section of ['products','users','categories','warehouses','suppliers','customers','purchase-orders','sales-orders']){
   await navigate('/'+section);
   await evaluate(`document.querySelector('a[href="/${section}/create"]').click()`);
   await waitFor(`document.querySelector('.modal-backdrop .modal-footer') && document.querySelector('.modal-backdrop').getAttribute('aria-busy')==='false'`);
   const result=await measure('.modal-backdrop .modal-panel');verify(result);results.push({width,dialog:section+'/create',...result});
   await evaluate(`document.querySelector('.modal-backdrop [data-cancel-href]').click()`);
   await waitFor(`document.querySelector('.modal-backdrop').hidden`);
   if(['products','users','categories','warehouses','suppliers','customers'].includes(section)){
    await evaluate(`document.querySelector('.data-table a[href*="/edit?"]').click()`);
    await waitFor(`document.querySelector('.modal-backdrop .modal-footer') && document.querySelector('.modal-backdrop').getAttribute('aria-busy')==='false'`);
    const edited=await measure('.modal-backdrop .modal-panel');verify(edited);results.push({width,dialog:section+'/edit',...edited});
    await evaluate(`document.querySelector('.modal-backdrop [data-cancel-href]').click()`);
    await waitFor(`document.querySelector('.modal-backdrop').hidden`);

    await evaluate(`document.querySelector('[data-dialog-open]').click()`);
    const imported=await measure('.import-dialog .modal-panel');verify(imported);results.push({width,dialog:section+'/import',...imported});
    await evaluate(`document.querySelector('.import-dialog [data-dialog-close]').click()`);
    await waitFor(`document.querySelector('.import-dialog').hidden`);
   }
  }
  await navigate('/categories');
  await evaluate(`document.querySelector('a[href="/categories/create"]').click()`);
  await waitFor(`document.querySelector('.modal-backdrop .modal-footer') && document.querySelector('.modal-backdrop').getAttribute('aria-busy')==='false'`);
  await evaluate(`(()=>{const form=document.querySelector('.modal-backdrop form');form.querySelector('[name=name]').removeAttribute('required');form.requestSubmit();})()`);
  await waitFor(`document.querySelector('.modal-backdrop .alert')`);
  const validation=await measure('.modal-backdrop .modal-panel');verify(validation);results.push({width,dialog:'categories/server-validation',...validation});
  await evaluate(`document.querySelector('.modal-backdrop [data-cancel-href]').click()`);
  await waitFor(`document.querySelector('.modal-backdrop').hidden`);
  await evaluate(`window.confirmTest=InventoryModal.create({state:{}});window.confirmTest.askConfirmation('Layout check');void 0;`);
  const confirmation=await measure('.confirm-panel');verify(confirmation);results.push({width,dialog:'confirmation',...confirmation});
  await evaluate(`document.querySelector('.confirm-cancel').click()`);
  await waitFor(`document.querySelector('.confirm-backdrop').hidden`);
 }
 if(errors.length)throw new Error(errors.join(';'));
 fs.writeFileSync('docs/testing/end-to-end-2026-10-07/browser/dialog-results.json',JSON.stringify(results,null,2)+'\n');
 console.log(JSON.stringify({checks:results.length,sizes:[[320,568],[390,844],[568,320],[768,1024],[1440,900]],errors:errors.length,serverValidation:true,cancelCloses:true}));
 await call('Browser.close'); ws.close();
})().catch(error => { console.error(error); process.exit(1); });
