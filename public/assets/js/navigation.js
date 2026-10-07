'use strict';

(function exposeNavigation(root) {
    root.InventoryNavigation = {
        buildShell() {
            // PHP renders the shell before first paint; JS only enables the drawer.
            if (document.body.classList.contains('auth-body')) return;
            const shell = document.querySelector('.app-shell');
            if (!shell || shell.dataset.navigationReady) return;
            const sidebar = shell.querySelector('.sidebar');
            const menuButton = shell.querySelector('.sidebar-toggle');
            const backdrop = shell.querySelector('.sidebar-backdrop');
            const closeButton = shell.querySelector('.sidebar-close');
            const mobile = root.matchMedia('(max-width: 960px)');
            if (!sidebar || !menuButton || !backdrop) return;
            shell.dataset.navigationReady = 'true';
            const syncAvailability = () => {
                const hidden = mobile.matches && !sidebar.classList.contains('is-open');
                sidebar.inert = hidden;
                sidebar.setAttribute('aria-hidden', String(hidden));
            };
            const closeDrawer = (restoreFocus = true) => {
                sidebar.classList.remove('is-open');
                backdrop.classList.remove('is-open');
                document.body.classList.remove('has-sidebar-drawer');
                menuButton.setAttribute('aria-expanded', 'false');
                syncAvailability();
                if (restoreFocus) menuButton.focus();
            };
            const openDrawer = () => {
                sidebar.classList.add('is-open');
                backdrop.classList.add('is-open');
                document.body.classList.add('has-sidebar-drawer');
                menuButton.setAttribute('aria-expanded', 'true');
                syncAvailability();
                closeButton?.focus();
            };
            menuButton.addEventListener('click', () => {
                if (sidebar.classList.contains('is-open')) closeDrawer();
                else openDrawer();
            });
            backdrop.addEventListener('click', () => closeDrawer());
            closeButton?.addEventListener('click', () => closeDrawer());
            mobile.addEventListener('change', () => closeDrawer(false));
            syncAvailability();
            document.addEventListener('keydown', event => {
                if (!mobile.matches || !sidebar.classList.contains('is-open')) return;
                if (event.key === 'Escape') closeDrawer();
                if (event.key === 'Tab') {
                    const focusable = [...sidebar.querySelectorAll('a[href], summary, button:not([disabled]), input:not([type=hidden])')]
                        .filter(element => element.getClientRects().length > 0);
                    const first = focusable[0];
                    const last = focusable.at(-1);
                    if (!first) return;
                    if (event.shiftKey && document.activeElement === first) {
                        event.preventDefault();
                        last.focus();
                    } else if (!event.shiftKey && document.activeElement === last) {
                        event.preventDefault();
                        first.focus();
                    }
                }
            });
        },
    };
})(typeof globalThis === 'object' ? globalThis : this);
