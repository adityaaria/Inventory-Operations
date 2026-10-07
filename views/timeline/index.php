<?php require dirname(__DIR__).'/inventory-operations/start.php'; ?>
<p>Only persisted successful actions and ledger movements are shown. Actor, product and warehouse names reflect current master records.</p>
<?php if($history['limited']): ?><p class="alert">Showing the latest 100 events. Older audit history remains available to Admin in Audit Trail.</p><?php endif; ?>
<?php foreach($history['links'] as $link): ?><p><a class="button" href="<?= $link['kind']==='PO'?'/purchase-orders/show':'/sales-orders/show' ?>?id=<?= (int)$link['id'] ?>">Original <?= $escape($link['kind']) ?> #<?= (int)$link['id'] ?></a></p><?php endforeach; ?>
<ol class="transaction-timeline">
<?php foreach($history['events'] as $event): ?><li class="card"><div class="card-body"><time><?= $escape($event['event_at']) ?></time><h2><?= $escape($event['title']) ?></h2><p>By <?= $escape($event['actor']??'Unavailable actor') ?></p><?php foreach(['detail','decision','reason'] as $field):if($event[$field]!==''): ?><p><?= $escape($event[$field]) ?></p><?php endif;endforeach; ?><?php if($event['url']!==''): ?><a class="button" href="<?= $escape($event['url']) ?>">View related return</a><?php endif; ?></div></li><?php endforeach; ?>
</ol>
<?php require dirname(__DIR__).'/inventory-operations/end.php'; ?>
