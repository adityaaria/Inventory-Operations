<?php
$appTitle = 'Invoices'; $title = 'Invoice not created'; $subtitle = 'The delivery note could not be invoiced.';
$toolbar = '<a class="button" href="/delivery-notes/show?id=' . (int) $noteId . '">Delivery Note</a><a href="/delivery-notes?invoiced=no">Delivery notes not invoiced</a>';
require dirname(__DIR__) . '/inventory-operations/start.php';
require dirname(__DIR__) . '/inventory-operations/end.php';
