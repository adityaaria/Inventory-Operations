'use strict';
const fs=require('node:fs');const {execFileSync}=require('node:child_process');
(async()=>{
 const evidence=process.argv[2]||'docs/testing/business-enhancements-2026-10-07';
 const context=JSON.parse(fs.readFileSync(evidence+'/context.json','utf8'));const fixtures=JSON.parse(fs.readFileSync(evidence+'/http.json','utf8')).fixtures;
 if(!context.project.startsWith('inventory-e2e-business-')||context.url!=='http://localhost:18086')throw Error('Refusing browser writes outside the disposable business project');
 const docker=fs.existsSync('/Applications/Docker.app/Contents/Resources/bin/docker')?'/Applications/Docker.app/Contents/Resources/bin/docker':'docker';
 const container=execFileSync(docker,['compose','-p',context.project,'-f','compose.yaml','-f',context.compose_override,'ps','-q','app'],{encoding:'utf8'}).trim();
 const inspect=JSON.parse(execFileSync(docker,['inspect',container],{encoding:'utf8'}))[0];
 if(inspect.Config.Labels['com.docker.compose.project']!==context.project||!inspect.NetworkSettings.Ports['8080/tcp'].some(p=>p.HostPort==='18086'))throw Error('Target ownership mismatch');
 const tabs=await(await fetch('http://127.0.0.1:9225/json')).json();const ws=new WebSocket(tabs.find(t=>t.type==='page').webSocketDebuggerUrl);await new Promise(r=>ws.onopen=r);
 let id=0;const pending=new Map();const exceptions=new Map();ws.onmessage=e=>{const m=JSON.parse(e.data);if(m.id){const p=pending.get(m.id);pending.delete(m.id);m.error?p.reject(Error(m.error.message)):p.resolve(m.result);}else if(m.method==='Runtime.exceptionThrown')exceptions.set(m.params.exceptionDetails.exceptionId,{url:m.params.exceptionDetails.url,text:m.params.exceptionDetails.text,description:m.params.exceptionDetails.exception?.description,stack:m.params.exceptionDetails.stackTrace});else if(m.method==='Runtime.exceptionRevoked')exceptions.delete(m.params.exceptionId);};
 const call=(method,params={})=>new Promise((resolve,reject)=>{const n=++id;pending.set(n,{resolve,reject});ws.send(JSON.stringify({id:n,method,params}));});
 const evaluate=async expression=>{const r=await call('Runtime.evaluate',{expression,awaitPromise:true,returnByValue:true});if(r.exceptionDetails)throw Error(r.exceptionDetails.text);return r.result.value;};
 const sleep=ms=>new Promise(r=>setTimeout(r,ms));
 async function wait(expression){const until=Date.now()+12000;while(Date.now()<until){try{if(await evaluate(expression))return;}catch{}await sleep(100);}throw Error('Browser condition timed out: '+expression);}
 async function navigate(path){await call('Page.navigate',{url:context.url+path});await wait(`document.readyState==='complete' && location.href.includes(${JSON.stringify(path)})`);await sleep(150);}
 async function login(email){await navigate('/login');await evaluate(`fetch('/login',{method:'POST',body:new URLSearchParams({email:${JSON.stringify(email)},password:'password',csrf_token:document.querySelector('[name=csrf_token]').value})}).then(r=>{if(!r.ok)throw Error('Login failed')})`);await navigate('/inventory-operations');}
 async function logout(){await evaluate(`fetch('/logout',{method:'POST',body:new URLSearchParams({csrf_token:document.querySelector('[name=csrf_token]').value})})`);}
 await call('Page.enable');await call('Runtime.discardConsoleEntries');await call('Runtime.enable');await login('warehouse1@example.test');
 const results=[];for(const width of [360,390,768,1440]){
  await call('Emulation.setDeviceMetricsOverride',{width,height:900,deviceScaleFactor:1,mobile:width<768});
  for(const path of ['/timeline?kind=PO&id='+fixtures.po_id,'/timeline?kind=SO&id='+fixtures.so_id,'/timeline?kind=Operation&id='+fixtures.posted_operation,'/work-queue','/inventory-operations','/inventory-operations?kind=Transfer','/replenishment','/inventory-operations/create','/inventory-operations/show?id='+fixtures.posted_operation,'/purchase-orders/show?id='+fixtures.po_id,'/sales-orders/show?id='+fixtures.so_id]){
   await navigate(path);const row=await evaluate(`({overflow:document.documentElement.scrollWidth>innerWidth,title:document.querySelector('h1')?.textContent,errors:!!document.querySelector('main h1')&&document.querySelector('main h1').textContent==='Server Error'})`);
   if(row.overflow||row.errors)throw Error(JSON.stringify({width,path,...row}));results.push({width,path,passed:true});
  }
 }
 await call('Emulation.setDeviceMetricsOverride',{width:390,height:844,deviceScaleFactor:1,mobile:true});await navigate('/inventory-operations');
 await navigate('/inventory-operations');
 await evaluate(`document.querySelector('a[href="/inventory-operations/create"]').click()`);await wait(`!!document.querySelector('.modal-panel [data-stock-proposal]')`);
 await wait(`!document.querySelector('.modal-panel [type=submit]').disabled`);
 await evaluate(`document.querySelector('.modal-panel [data-stock-add]').click()`);
 await wait(`document.querySelectorAll('.modal-panel [data-stock-items] [data-stock-item]').length===2 && document.querySelector('.modal-panel [type=submit]').disabled`);
 results.push({flow:'duplicate product disables multi-item submission',passed:true});
 const baseline=await evaluate(`document.querySelector('.modal-panel [name=baseline]').value`);
 await evaluate(`document.querySelector('.modal-panel [data-stock-remove]:not([disabled])').click()`);
 await wait(`document.querySelectorAll('.modal-panel [data-stock-items] [data-stock-item]').length===1 && !document.querySelector('.modal-panel [type=submit]').disabled`);
 if(await evaluate(`document.querySelector('.modal-panel [name=baseline]').value`)!==baseline)throw Error('Removing an item changed the count snapshot');
 results.push({flow:'remove item preserves first count baseline',passed:true});
 await evaluate(`document.querySelector('.modal-panel [data-stock-add]').click()`);
 await evaluate(`(()=>{const rows=document.querySelectorAll('.modal-panel [data-stock-items] [data-stock-item]');const first=rows[0].querySelector('[data-stock-input=product_id]').value;const second=rows[1].querySelector('[data-stock-input=product_id]');second.value=[...second.options].find(o=>o.value!==first).value;second.dispatchEvent(new Event('change',{bubbles:true}));})()`);
 await wait(`!document.querySelector('.modal-panel [type=submit]').disabled`);
 const overflow=await evaluate(`document.documentElement.scrollWidth>innerWidth`);if(overflow)throw Error('Multi-item modal overflows mobile viewport');
 await evaluate(`(()=>{const form=document.querySelector('.modal-panel [data-stock-proposal]');for(const row of form.querySelectorAll('[data-stock-items] [data-stock-item]'))row.querySelector('[data-stock-input=quantity]').value=Number(row.querySelector('[data-stock-input=baseline]').value)+1;form.elements.reason.value='Browser multi-product count';form.querySelector('[type=submit]').click();})()`);
 await wait(`location.pathname==='/inventory-operations/show' && document.querySelector('.card .status-badge')?.textContent==='PendingApproval'`);
 if(await evaluate(`document.querySelectorAll('table tbody tr').length`)!==2)throw Error('Multi-item proposal lost an item');
 results.push({flow:'mobile modal submits two-product proposal awaiting independent approval',passed:true});
 await navigate('/inventory-operations');
 const sourceBalance=await evaluate(`fetch('/inventory-operations/balance?product_id=${fixtures.product_id}&warehouse_id=1').then(r=>r.json())`);
 const sourceWarehouse=Number(sourceBalance.quantity??sourceBalance.data?.quantity)>=1?1:2;const destinationWarehouse=sourceWarehouse===1?2:1;
 await evaluate(`document.querySelector('a[href="/inventory-operations/create"]').click()`);await wait(`!!document.querySelector('.modal-panel [data-stock-proposal]')`);
 await evaluate(`(()=>{const f=document.querySelector('.modal-panel [data-stock-proposal]');f.elements.kind.value='Transfer';f.elements.kind.dispatchEvent(new Event('change',{bubbles:true}));f.elements.product_id.value='${fixtures.product_id}';f.elements.warehouse_id.value='${sourceWarehouse}';f.elements.destination_id.value='${destinationWarehouse}';f.elements.quantity.value='1';f.elements.reason.value='Browser transfer approval flow';})()`);
 await wait(`!document.querySelector('.modal-panel [type=submit]').disabled`);await evaluate(`document.querySelector('.modal-panel [type=submit]').click()`);
 await wait(`location.pathname==='/inventory-operations/show'`);const transfer=await evaluate(`Number(new URLSearchParams(location.search).get('id'))`);
 await logout();await login('admin@example.test');await navigate('/inventory-operations/show?id='+transfer);
 await evaluate(`(()=>{const f=document.querySelector('form[action="/inventory-operations/decide"]');f.elements.reason.value='Verified via browser';f.querySelector('[value=Approved]').click();})()`);
 await wait(`document.querySelector('.card .status-badge')?.textContent==='Approved'`);
 await logout();await login('warehouse1@example.test');await navigate('/inventory-operations/show?id='+transfer);await evaluate(`document.querySelector('form[action="/inventory-operations/post"] [type=submit]').click()`);await wait(`document.querySelector('.card .status-badge')?.textContent==='Posted'`);
 results.push({flow:'modal transfer proposal -> independent Admin approve -> Warehouse post',sourceWarehouse,destinationWarehouse,passed:true});
 await navigate('/sales-orders/show?id='+fixtures.so_id);await evaluate(`document.querySelector('a[href*="kind=CustomerReturn"]').click()`);await wait(`!!document.querySelector('.modal-panel [data-stock-proposal]') && !document.querySelector('.modal-panel [type=submit]').disabled`);
 const returnFields=await evaluate(`(()=>{const f=document.querySelector('.modal-panel [data-stock-proposal]');return {kind:f.elements.kind.value,source:f.elements.source_ledger_id.value,productDisabled:f.elements.product_id.disabled,warehouseDisabled:f.elements.warehouse_id.disabled};})()`);
 if(returnFields.kind!=='CustomerReturn'||!returnFields.productDisabled||!returnFields.warehouseDisabled)throw Error('Return modal did not initialize');
 await evaluate(`(()=>{const f=document.querySelector('.modal-panel [data-stock-proposal]');f.elements.quantity.value='1';f.elements.reason.value='Browser returned goods';f.querySelector('[type=submit]').click();})()`);await wait(`!!document.querySelector('.modal-panel [name=fit_for_stock]')?.validity.valueMissing && location.pathname==='/sales-orders/show'`);
 results.push({flow:'return modal initializes original source and requires goods-fit confirmation',passed:true});
 await evaluate(`(()=>{const f=document.querySelector('.modal-panel [data-stock-proposal]');f.elements.fit_for_stock.checked=true;f.querySelector('[type=submit]').click();})()`);await wait(`location.pathname==='/inventory-operations/show'`);results.push({flow:'confirmed customer-return proposal submits through modal',passed:true});
 if(exceptions.size)throw Error('Browser JS exception: '+JSON.stringify([...exceptions.values()]));
 fs.writeFileSync(evidence+'/browser.json',JSON.stringify({passed:true,scope:'Chrome emulation; physical Android/iPhone pending',results,exceptions:[...exceptions.values()]},null,2)+'\n');console.log(JSON.stringify({passed:true,checks:results.length}));await call('Browser.close');
})().catch(e=>{console.error(e.message);process.exit(1);});
