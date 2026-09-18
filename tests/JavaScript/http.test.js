const test = require('node:test');
const assert = require('node:assert/strict');
const {createRequestCoordinator, fetchHtml, HttpResponseError, NetworkRequestError} = require('../../public/assets/js/http.js');

test('fetchHtml returns response and body for successful responses', async () => {
    const result = await fetchHtml('/products/create', {
        fetchImpl: async () => ({
            ok: true,
            status: 200,
            text: async () => '<main>form</main>',
        }),
    });

    assert.equal(result.response.status, 200);
    assert.equal(result.html, '<main>form</main>');
});

test('fetchHtml rejects unexpected HTTP responses with body context', async () => {
    await assert.rejects(
        fetchHtml('/products/create', {
            fetchImpl: async () => ({
                ok: false,
                status: 500,
                text: async () => '<main>server error</main>',
            }),
        }),
        (error) => {
            assert.ok(error instanceof HttpResponseError);
            assert.equal(error.status, 500);
            assert.equal(error.body, '<main>server error</main>');
            return true;
        },
    );
});

test('fetchHtml permits explicitly allowed validation responses', async () => {
    const result = await fetchHtml('/products/create', {
        allowedStatuses: [422],
        fetchImpl: async () => ({
            ok: false,
            status: 422,
            text: async () => '<main>validation error</main>',
        }),
    });

    assert.equal(result.response.status, 422);
    assert.equal(result.html, '<main>validation error</main>');
});

test('fetchHtml forwards an abort signal to fetch', async () => {
    const controller = new AbortController();
    let receivedSignal;

    await fetchHtml('/products/create', {
        signal: controller.signal,
        fetchImpl: async (_url, options) => {
            receivedSignal = options.signal;
            return {ok: true, status: 200, text: async () => ''};
        },
    });

    assert.equal(receivedSignal, controller.signal);
});

test('fetchHtml classifies non-abort transport failures', async () => {
    await assert.rejects(
        fetchHtml('/products/create', {
            fetchImpl: async () => {
                throw new Error('offline');
            },
        }),
        (error) => error instanceof NetworkRequestError && error.name === 'NetworkRequestError',
    );
});

test('request coordinator aborts the previous request and marks it stale', () => {
    const controllers = [];
    const coordinator = createRequestCoordinator(() => {
        const controller = {signal: {}, aborted: false, abort() { this.aborted = true; }};
        controllers.push(controller);
        return controller;
    });

    const first = coordinator.start();
    const second = coordinator.start();

    assert.equal(controllers[0].aborted, true);
    assert.equal(first.isCurrent(), false);
    assert.equal(second.isCurrent(), true);

    coordinator.cancel();
    assert.equal(controllers[1].aborted, true);
    assert.equal(second.isCurrent(), false);
});
