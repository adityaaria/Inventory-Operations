<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Reports - Inventory & Order Management</title>
    <link rel="icon" href="/favicon.svg" type="image/svg+xml">
    <link rel="stylesheet" href="/assets/css/app.css">
    <script defer src="/assets/js/http.js"></script>
    <script defer src="/assets/js/ui-helpers.js"></script>
    <script defer src="/assets/js/form-validation.js"></script>
    <script defer src="/assets/js/navigation.js"></script>
    <script defer src="/assets/js/modal.js"></script>
    <script defer src="/assets/js/dialog.js"></script>
    <script defer src="/assets/js/charts.js"></script>
    <script defer src="/assets/js/forms.js"></script>
    <script defer src="/assets/js/tables.js"></script>
    <script defer src="/assets/js/app.js"></script>
</head>
<body>
    <main class="page">
        <header class="page-header">
            <div>
                <p class="app-title">Inventory Operations</p>
                <h1>Reports</h1>
                <p class="page-subtitle">Download operational CSV exports for orders and stock movement.</p>
            </div>
            <nav class="toolbar"><a href="/">Home</a></nav>
        </header>
        <section class="report-grid">
            <article class="report-panel">
                <form class="form" method="get" action="/reports/orders.csv">
                    <label>From <input name="from" type="date"></label>
                    <label>To <input name="to" type="date"></label>
                    <button type="submit">Download Orders CSV</button>
                </form>
            </article>
            <article class="report-panel">
                <form class="form" method="get" action="/reports/stock-ledger.csv">
                    <label>From <input name="from" type="date"></label>
                    <label>To <input name="to" type="date"></label>
                    <button type="submit">Download Stock Ledger CSV</button>
                </form>
            </article>
        </section>
    </main>
</body>
</html>
