const test = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const vm = require('node:vm');

test('server validation reuses the dialog renderer and keeps the native page intact', async () => {
    const rendered = [];
    const button = {dataset: {}, textContent:'Create', disabled:false, classList:{add() {},remove() {}}};
    const form = {
        dataset:{}, elements:[], action:'/categories',
        querySelectorAll:()=>[], querySelector:selector=>selector.includes('button')?button:null,
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
    const container = {querySelector:()=>null};
    const form = {
        dataset:{},elements:[],action:'/categories',before:alert=>alerts.push(alert),querySelectorAll:()=>[],querySelector:selector=>selector.includes('button')?button:null,
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


test('selected approval decision survives button disabling in native and modal submissions', async () => {
    for (const isModal of [false, true]) {
        const hidden = [];
        const sent = [];
        const button = {name:'decision',value:'Rejected',dataset:{},textContent:'Reject',disabled:false,classList:{add(){},remove(){}}};
        const form = {
            dataset:{},elements:[],action:'/inventory-operations/decide',
            querySelectorAll:()=>[],querySelector:selector=>selector.includes('button')?button:null,
            getAttribute:name=>name==='method'?'post':'/inventory-operations/decide',
            closest:selector=>isModal && selector==='.modal-backdrop'?{}:null,
            addEventListener(name,callback){this.submit=callback;},append:element=>hidden.push(element),
        };
        const context = {
            InventoryValidation:{validateFields:()=>({})},InventoryUi:{needsConfirmation:()=>false},
            InventoryHttp:{fetchHtml:async(url,options)=>{sent.push(options.body.decision);return {html:'validation',response:{status:422,ok:false}};}},
            document:{createElement:()=>({dataset:{}})},
            FormData:class {constructor(target,submitter){assert.equal(submitter.disabled,false);this.decision=submitter.value;}},
            location:{href:'/inventory-operations/show?id=1'},
        };
        vm.runInNewContext(fs.readFileSync('public/assets/js/forms.js','utf8'),context);
        context.InventoryForms.create({modal:{renderForm(){},setPageLoading(){}},state:{confirmedForms:new WeakSet()}}).enhanceForms({querySelectorAll:()=>[form]});
        form.submit({submitter:button,preventDefault(){}});
        await new Promise(resolve=>setImmediate(resolve));
        assert.equal(hidden[0].name,'decision');assert.equal(hidden[0].value,'Rejected');
        if(isModal)assert.deepEqual(sent,['Rejected']);
    }
});

test('a form that already is the confirmation step (reason dialog) submits without a second confirmation', async () => {
    let loading = false;
    const button = {dataset: {}, textContent: 'Reject Order', disabled: false, classList: {add() {}, remove() {}}};
    const form = {
        dataset: {skipConfirm: ''}, elements: [], action: '/sales-orders/reject',
        querySelectorAll: () => [], querySelector: selector => (selector.includes('button') ? button : null),
        getAttribute: name => (name === 'method' ? 'post' : '/sales-orders/reject'),
        closest: () => null,
        addEventListener(name, callback) { this.submit = callback; },
    };
    const context = {
        InventoryValidation: {validateFields: () => ({})},
        InventoryUi: {needsConfirmation: () => true},
        FormData: class {},
        document: {createElement: () => ({dataset: {}})},
    };
    vm.runInNewContext(fs.readFileSync('public/assets/js/forms.js', 'utf8'), context);
    const modal = {askConfirmation() { throw new Error('Must not ask a second confirmation'); }, setPageLoading(active) { loading = active; }};
    context.InventoryForms.create({modal, state: {confirmedForms: new WeakSet()}}).enhanceForms({querySelectorAll: () => [form]});
    let prevented = false;
    await form.submit({preventDefault() { prevented = true; }});
    assert.equal(prevented, false, 'the native POST continues');
    assert.equal(loading, true);
});
