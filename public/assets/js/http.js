'use strict';

(function exposeHttp(root, factory) {
    const api = factory(root);

    if (typeof module === 'object' && module.exports) {
        module.exports = api;
        return;
    }

    root.InventoryHttp = api;
})(typeof globalThis === 'object' ? globalThis : this, (root) => {
    class HttpResponseError extends Error {
        constructor(response, body) {
            super(`HTTP request failed with status ${response.status}`);
            this.name = 'HttpResponseError';
            this.status = response.status;
            this.body = body;
            this.response = response;
        }
    }

    class NetworkRequestError extends Error {
        constructor(cause) {
            super('Network request failed.');
            this.name = 'NetworkRequestError';
            this.cause = cause;
        }
    }

    async function fetchHtml(url, options = {}) {
        const {
            allowedStatuses = [],
            body,
            fetchImpl = root.fetch.bind(root),
            headers = {},
            method = 'GET',
            signal,
        } = options;
        let response;
        try {
            response = await fetchImpl(url, {
                body,
                headers: {'X-Requested-With': 'fetch', ...headers},
                method,
                signal,
            });
        } catch (error) {
            if (error.name === 'AbortError') {
                throw error;
            }
            throw new NetworkRequestError(error);
        }
        const html = await response.text();

        if (!response.ok && !allowedStatuses.includes(response.status)) {
            throw new HttpResponseError(response, html);
        }

        return {html, response};
    }

    function createRequestCoordinator(controllerFactory = () => new AbortController()) {
        let active = null;

        function start() {
            active?.controller.abort();
            const controller = controllerFactory();
            const request = {controller};
            active = request;

            return {
                signal: controller.signal,
                isCurrent: () => active === request,
                finish: () => {
                    if (active === request) active = null;
                },
            };
        }

        function cancel() {
            active?.controller.abort();
            active = null;
        }

        return {cancel, start};
    }

    return {createRequestCoordinator, fetchHtml, HttpResponseError, NetworkRequestError};
});
