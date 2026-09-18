<?php

declare(strict_types=1);

namespace App\Controller;

use App\Http\Request;
use App\Http\Response;

final class HomeController
{
    public function index(Request $request): Response
    {
        $body = <<<'HTML'
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Inventory & Order Management</title>
    <link rel="icon" href="/favicon.svg" type="image/svg+xml">
    <link rel="stylesheet" href="/assets/css/app.css">
    <script defer src="/assets/js/http.js"></script>
    <script defer src="/assets/js/ui-helpers.js"></script>
    <script defer src="/assets/js/form-validation.js"></script>
    <script defer src="/assets/js/navigation.js"></script>
    <script defer src="/assets/js/modal.js"></script>
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
                <h1>Inventory & Order Management</h1>
                <p class="page-subtitle">Operational workspace for inventory, purchasing, sales, reporting, and master data.</p>
            </div>
        </header>
        <nav class="toolbar" aria-label="Primary navigation">
            <a class="button-primary" href="/dashboard">Dashboard</a>
            <a href="/reports">Reports</a>
            <a href="/products">Products</a>
            <a href="/purchase-orders">Purchase Orders</a>
            <a href="/sales-orders">Sales Orders</a>
            <a href="/categories">Categories</a>
            <a href="/warehouses">Warehouses</a>
            <a href="/suppliers">Suppliers</a>
            <a href="/customers">Customers</a>
            <a href="/users">Users</a>
        </nav>
    </main>
</body>
</html>
HTML;

        return Response::html($body);
    }
}
