const test=require('node:test');const assert=require('node:assert/strict');const fs=require('node:fs');const vm=require('node:vm');
test('native transition cancellation is handled without masking unrelated failures',()=>{
    const handlers={};vm.runInNewContext(fs.readFileSync('public/assets/js/page-transitions.js','utf8'),{window:{addEventListener:(name,handler)=>handlers[name]=handler}});
    for(const name of ['pageswap','pagereveal']){
        assert.doesNotThrow(()=>handlers[name]({}));let reject;
        handlers[name]({viewTransition:{ready:{catch:handler=>reject=handler}}});
        const handled=[]; handlers[name]({viewTransition:Object.fromEntries(['ready','finished','updateCallbackDone'].map(key=>[key,{catch:handler=>handled.push(handler)}]))});
        assert.equal(handled.length,3);
        for(const errorName of ['AbortError','InvalidStateError','TimeoutError'])assert.doesNotThrow(()=>reject({name:errorName}));
        const unexpected=new Error('Unexpected transition failure');assert.throws(()=>reject(unexpected),/Unexpected transition failure/);
    }
});
test('POST submission disables outgoing cross-document opt-in while GET navigation retains it',()=>{
    const handlers={};let style;
    vm.runInNewContext(fs.readFileSync('public/assets/js/page-transitions.js','utf8'),{
        window:{addEventListener:(name,handler)=>handlers[name]=handler},
        document:{getElementById:()=>style,createElement:()=>({}),head:{appendChild:node=>style=node}},
    });
    handlers.submit({target:{method:'get'}});assert.equal(style,undefined);
    handlers.submit({target:{method:'post'}});assert.equal(style.textContent,'@view-transition { navigation: none; }');
    const original=style;handlers.submit({target:{method:'post'}});assert.equal(style,original);
});

test('aborted transitions never exposed to pagereveal are marked handled; unrelated rejections still surface', () => {
    const handlers = {};
    vm.runInNewContext(fs.readFileSync('public/assets/js/page-transitions.js', 'utf8'), { window: { addEventListener: (name, handler) => handlers[name] = handler } });
    const rejection = reason => { const event = { reason, prevented: false, preventDefault() { this.prevented = true; } }; handlers.unhandledrejection(event); return event.prevented; };
    assert.equal(rejection({ name: 'InvalidStateError', message: 'Transition was aborted because of invalid state. ViewTransition opt-in disabled' }), true);
    assert.equal(rejection({ name: 'AbortError', message: 'Transition was skipped' }), true);
    assert.equal(rejection({ name: 'InvalidStateError', message: 'Form already submitted' }), false);
    assert.equal(rejection(new Error('Transition failure in application code')), false);
    assert.equal(rejection(undefined), false);
});
