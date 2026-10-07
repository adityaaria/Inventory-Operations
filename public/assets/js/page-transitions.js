'use strict';
// Register before first paint; interrupted native transitions must not affect navigation.
for (const eventName of ['pageswap', 'pagereveal']) {
    window.addEventListener(eventName, (event) => {
        if (!event.viewTransition) return;
        for (const promise of [event.viewTransition.ready, event.viewTransition.finished, event.viewTransition.updateCallbackDone]) {
            promise?.catch((error) => {
                if (!['AbortError', 'InvalidStateError', 'TimeoutError'].includes(error.name)) throw error;
            });
        }
    });
}
// When the browser aborts a cross-document transition before reveal, it rejects an internal promise that is never
// exposed to pagereveal (viewTransition is null), so only this narrow handler can mark it as expected.
window.addEventListener('unhandledrejection', (event) => {
    const reason = event.reason;
    if (reason && ['AbortError', 'InvalidStateError', 'TimeoutError'].includes(reason.name) && /transition/i.test(String(reason.message))) event.preventDefault();
});
// POST/redirect navigation is not eligible for a cross-document transition.
// Disable the outgoing opt-in before creating an ineligible incoming transition.
window.addEventListener('submit', (event) => {
    if (event.target.method?.toLowerCase() !== 'post') return;
    if (document.getElementById('mutation-navigation-transition')) return;
    const style = document.createElement('style');
    style.id = 'mutation-navigation-transition';
    style.textContent = '@view-transition { navigation: none; }';
    document.head.appendChild(style);
}, true);
