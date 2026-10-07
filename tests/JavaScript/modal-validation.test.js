const test = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const vm = require('node:vm');

test('server validation reuses the dialog renderer and keeps the native page intact', async () => {
    const rendered = [];
    const button = {dataset: {}, textContent:'Create', disabled:false, classList:{add() {},remove() {}}};
    const form = {
        dataset:{}, elements:[], action:'/categories',
        querySelectorAll:()=>[], querySelector:()=>button,
        getAttribute:name=>name==='method'?'post':'/categories',
        closest:selector=>selector==='.modal-backdrop'?{}:null,
        addEventListener(name,callback) { this.submit=callback; },
    };
    const context = {
        InventoryValidation:{validateFields:()=>({})},
        InventoryUi:{needsConfirmation:()=>false},
        InventoryHttp:{fetchHtml:async()=>({html:'<main>Validation failed</main>',response:{status:422,ok:false}})},
        FormData:class {},
        location:{href:'/categories',reload(){throw new Error('Validation must remain in dialog');}},
    };
    vm.runInNewContext(fs.readFileSync('public/assets/js/forms.js','utf8'),context);
    context.InventoryForms.create({modal:{renderForm:html=>rendered.push(html)},state:{confirmedForms:new WeakSet()}})
        .enhanceForms({querySelectorAll:()=>[form]});
    let prevented=false;
    form.submit({preventDefault(){prevented=true;}});
    await new Promise(resolve=>setImmediate(resolve));
    assert.equal(prevented,true);
    assert.deepEqual(rendered,['<main>Validation failed</main>']);
});

test('expired modal submission keeps the form and offers sign-in without retrying the mutation', async () => {
    const http = require('../../public/assets/js/http.js');
    const alerts = [];
    let submitted = 0;
    const button = {dataset:{},textContent:'Create',disabled:false,classList:{add(){},remove(){}}};
    const container = {querySelector:()=>null,insertBefore:alert=>alerts.push(alert)};
    const form = {
        dataset:{},elements:[],action:'/categories',querySelectorAll:()=>[],querySelector:()=>button,
        getAttribute:name=>name==='method'?'post':'/categories',
        closest:selector=>selector==='.modal-backdrop'?{}:selector==='.modal-body'?container:null,
        addEventListener(name,callback){this.submit=callback;},requestSubmit(){throw new Error('Must not replay POST');},
    };
    const context = {
        InventoryValidation:{validateFields:()=>({})},InventoryUi:{needsConfirmation:()=>false},
        InventoryHttp:{...http,fetchHtml:async()=>{submitted++;throw new http.HttpResponseError({status:401},'');}},
        document:{createElement:()=>({setAttribute(){},querySelector:()=>null})},FormData:class {},
        location:{reload(){throw new Error('Form must remain visible');}},
    };
    vm.runInNewContext(fs.readFileSync('public/assets/js/forms.js','utf8'),context);
    context.InventoryForms.create({modal:{},state:{confirmedForms:new WeakSet()}}).enhanceForms({querySelectorAll:()=>[form]});
    form.submit({preventDefault(){}});
    await new Promise(resolve=>setImmediate(resolve));
    assert.equal(submitted,1);
    assert.equal(button.disabled,false);
    assert.match(alerts[0].innerHTML,/Your session has ended/);
    assert.match(alerts[0].innerHTML,/href="\/login"/);
    assert.doesNotMatch(alerts[0].innerHTML,/data-retry-submit/);
});
