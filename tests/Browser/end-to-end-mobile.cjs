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

 const routes=['/dashboard','/reports','/reports?type=stock-ledger','/products/show?id=1'];
 for(const section of ['products','users','categories','warehouses','suppliers','customers','purchase-orders','sales-orders']){
  routes.push('/'+section,'/'+section+'/create');
  await navigate('/'+section);
  const edit=await evaluate(`document.querySelector('.data-table a[href*="/edit?"]')?.getAttribute('href')`);if(edit)routes.push(edit);
 }
 routes.push('/purchase-orders/show?id=1','/sales-orders/show?id=1','/login','/mobile-not-found');

 const results=[];const screenshots='docs/testing/end-to-end-2026-10-07/browser';fs.mkdirSync(screenshots,{recursive:true});
 const sizes=[[320,568],[360,800],[390,844],[430,932],[568,320],[844,390],[768,1024],[960,600],[1440,900]];
 const measure=()=>evaluate(`(()=>{
  const main=document.querySelector('main');
  const controls=[...main.querySelectorAll('input:not([type=hidden]):not([type=checkbox]):not([type=radio]),select,textarea')].filter(e=>e.getClientRects().length&&!e.closest('.table-scroll'));
  const tables=[...main.querySelectorAll('table')];const gaps=[];
  tables.forEach(t=>{const wrapper=t.closest('.table-scroll');if(!wrapper)return;const previous=wrapper.previousElementSibling,next=wrapper.nextElementSibling;if(previous?.classList.contains('filters'))gaps.push(t.getBoundingClientRect().top-previous.getBoundingClientRect().bottom);if(next?.classList.contains('pagination'))gaps.push(next.getBoundingClientRect().top-t.getBoundingClientRect().bottom);});
  return {documentOverflow:document.documentElement.scrollWidth>innerWidth+1,mainOverflow:main.scrollWidth>main.clientWidth+1,controlsOut:controls.filter(e=>e.getBoundingClientRect().right>innerWidth+1||e.getBoundingClientRect().left< -1).length,smallControls:controls.filter(e=>innerWidth<=960&&e.getBoundingClientRect().height<43).map(e=>({tag:e.tagName,name:e.name,height:e.getBoundingClientRect().height,min:getComputedStyle(e).minHeight})),tables:tables.length,localTables:main.querySelectorAll('.table-scroll table').length,gaps,sidebarClosedInert:innerWidth<=960&&document.querySelector('.sidebar')?document.querySelector('.sidebar').inert:true};
 })()`);
 for(const [width,height] of sizes){
  await call('Emulation.setDeviceMetricsOverride',{width,height,deviceScaleFactor:1,mobile:width<=960});
  for(const route of routes){
   await navigate(route);
   const result=await measure();
   if(result.documentOverflow||result.mainOverflow||result.controlsOut||result.smallControls.length||result.tables!==result.localTables||result.gaps.some(g=>Math.abs(g)>1)||!result.sidebarClosedInert)throw new Error(JSON.stringify({width,height,route,...result}));
   results.push({width,height,route,...result});
   if([320,390,768].includes(width)&&['/dashboard','/products','/products/create','/reports','/purchase-orders/show?id=1','/login'].includes(route)){
    const shot=await call('Page.captureScreenshot',{format:'png',captureBeyondViewport:false});fs.writeFileSync(screenshots+'/'+route.slice(1).replaceAll('/','-').replaceAll('?','-').replaceAll('=','-')+'-'+width+'.png',Buffer.from(shot.data,'base64'));
   }
  }
  await navigate('/products');
  if(width<=960){
   await evaluate(`document.querySelector('.sidebar-toggle').click()`);
   await evaluate(`new Promise((resolve,reject)=>{let n=0;function check(){if(Math.abs(document.querySelector('.sidebar').getBoundingClientRect().left)<0.5)return resolve();if(++n>300)return reject(new Error('Drawer transition timeout'));requestAnimationFrame(check);}check();})`);
   const drawer=await evaluate(`(()=>{const s=document.querySelector('.sidebar');return {inert:s.inert,expanded:document.querySelector('.sidebar-toggle').getAttribute('aria-expanded'),left:s.getBoundingClientRect().left,width:s.getBoundingClientRect().width,height:s.getBoundingClientRect().height,focused:document.activeElement===document.querySelector('.sidebar-close')};})()`);
   if(drawer.inert||drawer.expanded!=='true'||Math.abs(drawer.left)>1||drawer.width>width||drawer.height>height+1||!drawer.focused)throw new Error('Drawer '+JSON.stringify(drawer));
   await evaluate(`document.querySelector('.sidebar-close').click()`);
   if(!await evaluate(`document.querySelector('.sidebar').inert&&document.activeElement===document.querySelector('.sidebar-toggle')`))throw new Error('Drawer close/focus');
  }
  const scroll=await evaluate(`(()=>{const region=document.querySelector('.table-scroll');region.scrollLeft=region.scrollWidth;return {needsScroll:region.scrollWidth>region.clientWidth+1,local:region.scrollLeft>0,main:document.querySelector('main').scrollLeft,page:document.documentElement.scrollLeft};})()`);
  if((scroll.needsScroll&&!scroll.local)||scroll.main||scroll.page)throw new Error('Table scroll '+JSON.stringify(scroll));
  await navigate('/mobile-not-found');
  const tree=await call('Page.getFrameTree');
  await call('Page.setDocumentContent',{frameId:tree.frameTree.frame.id,html:fs.readFileSync('views/errors/500.php','utf8')});
  await evaluate(`new Promise(resolve=>requestAnimationFrame(()=>requestAnimationFrame(resolve)))`);
  const error500=await measure();if(error500.documentOverflow||error500.mainOverflow)throw new Error('500 template overflow');results.push({width,height,route:'500 template',...error500});
 }

 for(const email of ['sales1@example.test','warehouse1@example.test']){
  await navigate('/login');await evaluate(`fetch('/login',{method:'POST',body:new URLSearchParams({email:${JSON.stringify(email)},password:'password',csrf_token:document.querySelector('[name=csrf_token]').value})}).then(r=>{if(!r.ok)throw new Error('Role login')})`);
  for(const width of [320,768]){
   await call('Emulation.setDeviceMetricsOverride',{width,height:800,deviceScaleFactor:1,mobile:true});
   for(const route of ['/dashboard','/products','/products/show?id=1','/sales-orders','/reports','/users']){
    await navigate(route);const result=await measure();if(result.documentOverflow||result.mainOverflow||result.controlsOut||result.smallControls.length||!result.sidebarClosedInert)throw new Error('Role mobile '+JSON.stringify({email,route,...result}));results.push({email,width,height:800,route,...result});
   }
  }
 }
 await navigate('/reports');
 await call('Emulation.setDeviceMetricsOverride',{width:320,height:568,deviceScaleFactor:1,mobile:true});
 await evaluate(`document.querySelector('h1').textContent='LongOrderNumber'.repeat(12);document.querySelector('.field-label').textContent='LongFieldLabel'.repeat(12);document.querySelector('select option').textContent='LongProductName'.repeat(12)`);
 const stress=await measure();if(stress.documentOverflow||stress.mainOverflow||stress.controlsOut)throw new Error('Long content overflow');
 if(errors.length)throw new Error(errors.join(';'));
 fs.writeFileSync(screenshots+'/browser-results.json',JSON.stringify({sizes,results,stress,errors},null,2)+'\n');
 console.log(JSON.stringify({pages:results.length,sizes,errors:errors.length,checks:'no document/main overflow, controls fit and >=44px, all tables contained, zero filter/table/pagination gaps, drawer close/focus/inert, local horizontal table scrolling'}));
 await call('Browser.close'); ws.close();
})().catch(error => { console.error(error); process.exit(1); });
