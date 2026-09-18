'use strict';

(function exposeNavigation(root) {
    const navItems = [
        ['Dashboard', '/dashboard'],
        ['Products', '/products'],
        ['Purchase Orders', '/purchase-orders'],
        ['Sales Orders', '/sales-orders'],
        ['Reports', '/reports'],
        ['Users', '/users'],
        ['Categories', '/categories'],
        ['Warehouses', '/warehouses'],
        ['Suppliers', '/suppliers'],
        ['Customers', '/customers'],
    ];

    root.InventoryNavigation = {
        buildShell() {
            const main = document.querySelector('main.page');
            if (!main || document.querySelector('.app-shell') || document.body.classList.contains('auth-body')) {
                return;
            }

            const shell = document.createElement('div');
            shell.className = 'app-shell';

            const sidebar = document.createElement('aside');
            sidebar.className = 'sidebar';
            sidebar.innerHTML = `
                <a class="brand" href="/">
                    <span class="brand-mark">IO</span>
                    <span><strong>Inventory Ops</strong><small>Order Management</small></span>
                </a>
                <nav class="side-nav" aria-label="Main navigation">
                    ${navItems.map(([label, href]) => {
                        const active = location.pathname === href || (href !== '/' && location.pathname.startsWith(href));
                        return `<a class="${active ? 'is-active' : ''}" href="${href}">${label}</a>`;
                    }).join('')}
                </nav>
            `;

            const content = document.createElement('div');
            content.className = 'main-content';
            main.parentNode?.insertBefore(shell, main);
            shell.append(sidebar, content);
            content.append(main);
        },
    };
})(typeof globalThis === 'object' ? globalThis : this);
