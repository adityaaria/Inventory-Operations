'use strict';

(function exposeNavigation(root) {
    // Third element = roles allowed to see the item; omitted/undefined means every
    // authenticated role can see it. Kept in sync with each section's actual write
    // capability (see PurchaseOrderService/SalesOrderService/UserController guards) so the
    // menu only surfaces sections that are actually part of that role's daily workflow.
    const navItems = [
        ['Dashboard', '/dashboard'],
        ['Products', '/products'],
        ['Purchase Orders', '/purchase-orders', ['Admin', 'WarehouseStaff']],
        ['Sales Orders', '/sales-orders'],
        ['Reports', '/reports'],
        ['Users', '/users', ['Admin']],
        ['Categories', '/categories'],
        ['Warehouses', '/warehouses'],
        ['Suppliers', '/suppliers', ['Admin', 'WarehouseStaff']],
        ['Customers', '/customers', ['Admin', 'Sales']],
    ];

    function currentRole() {
        const match = document.cookie.match(/(?:^|; )user_role=([^;]*)/);
        return match ? decodeURIComponent(match[1]) : '';
    }

    root.InventoryNavigation = {
        buildShell() {
            const main = document.querySelector('main.page');
            if (!main || document.querySelector('.app-shell') || document.body.classList.contains('auth-body')) {
                return;
            }

            const role = currentRole();
            const visibleNavItems = navItems.filter(([, , allowedRoles]) => !allowedRoles || allowedRoles.includes(role));

            const shell = document.createElement('div');
            shell.className = 'app-shell';

            const sidebar = document.createElement('aside');
            sidebar.className = 'sidebar';
            sidebar.id = 'primary-navigation';
            sidebar.innerHTML = `
                <a class="brand" href="/">
                    <span class="brand-mark">IO</span>
                    <span><strong>Inventory Ops</strong><small>Order Management</small></span>
                </a>
                <nav class="side-nav" aria-label="Main navigation">
                    ${visibleNavItems.map(([label, href]) => {
                        const active = location.pathname === href || (href !== '/' && location.pathname.startsWith(href));
                        return `<a class="${active ? 'is-active' : ''}" href="${href}"${active ? ' aria-current="page"' : ''}>${label}</a>`;
                    }).join('')}
                </nav>
            `;

            const content = document.createElement('div');
            content.className = 'main-content';
            main.id = main.id || 'main-content';

            const skipLink = document.createElement('a');
            skipLink.className = 'skip-link';
            skipLink.href = '#main-content';
            skipLink.textContent = 'Skip to main content';

            const menuButton = document.createElement('button');
            menuButton.className = 'sidebar-toggle';
            menuButton.type = 'button';
            menuButton.setAttribute('aria-controls', sidebar.id);
            menuButton.setAttribute('aria-expanded', 'false');
            menuButton.textContent = 'Menu';

            const backdrop = document.createElement('button');
            backdrop.className = 'sidebar-backdrop';
            backdrop.type = 'button';
            backdrop.setAttribute('aria-label', 'Close navigation menu');
            backdrop.tabIndex = -1;

            const closeDrawer = () => {
                sidebar.classList.remove('is-open');
                backdrop.classList.remove('is-open');
                document.body.classList.remove('has-sidebar-drawer');
                menuButton.setAttribute('aria-expanded', 'false');
                menuButton.focus();
            };

            const openDrawer = () => {
                sidebar.classList.add('is-open');
                backdrop.classList.add('is-open');
                document.body.classList.add('has-sidebar-drawer');
                menuButton.setAttribute('aria-expanded', 'true');
            };

            menuButton.addEventListener('click', () => {
                if (sidebar.classList.contains('is-open')) {
                    closeDrawer();
                    return;
                }
                openDrawer();
            });
            backdrop.addEventListener('click', closeDrawer);
            document.addEventListener('keydown', (event) => {
                if (event.key === 'Escape' && sidebar.classList.contains('is-open')) {
                    closeDrawer();
                }
            });

            main.parentNode?.insertBefore(shell, main);
            shell.append(sidebar, backdrop, content);
            content.append(skipLink, menuButton);
            content.append(main);
        },
    };
})(typeof globalThis === 'object' ? globalThis : this);
