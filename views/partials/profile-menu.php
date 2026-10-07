<details class="sidebar-profile">
    <summary><span class="profile-avatar" aria-hidden="true">IO</span><span class="profile-label"><strong>Profile</strong><small><?= htmlspecialchars($workspaceRole === 'WarehouseStaff' ? 'Warehouse Staff' : (in_array($workspaceRole, ['Admin', 'Sales'], true) ? $workspaceRole : 'Account'), ENT_QUOTES, 'UTF-8') ?></small></span></summary>
    <form method="post" action="/logout">
        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars((string) ($GLOBALS['csrf_token'] ?? ''), ENT_QUOTES, 'UTF-8') ?>">
        <button type="submit" class="sidebar-logout">Logout</button>
    </form>
</details>
