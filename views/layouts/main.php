<?php
// Navigation mirrors the existing role-specific links; controller permissions remain authoritative.
$route = $_GET['route'] ?? 'dashboard';
$overview = ['dashboard', 'fa-gauge-high', 'Dashboard Overview', ['dashboard']];
$profileLink = ['profile', 'fa-id-card', 'Profile Management', ['profile']];
$events = ['preparedness-calendar', 'fa-calendar-check', 'Disaster Preparedness Management', ['preparedness', 'preparedness-calendar', 'preparedness-create', 'preparedness-evaluate', 'preparedness-predict', 'recommendations', 'recommendations-show', 'recommendations-purok-show']];
$events[2] = match ($currentRole) {
    'ICDRRMO' => 'Event Logs',
    'Barangay Head', 'Responder' => 'Event Lists',
    default => $events[2],
};
$allocations = ['recommendations', 'fa-network-wired', 'AI Priority Recommendations', ['recommendations', 'recommendations-show', 'recommendations-purok-show']];
$resources = ['reports', 'fa-boxes-stacked', 'Resource Management', ['reports', 'inventory', 'inventory-create', 'resource-management']];
$fieldReports = ['field-reports', 'fa-truck-medical', 'Responder Field Status', ['field-reports', 'field-reports-create', 'field-reports-show']];
$hazards = ['hazard-map', 'fa-map-location-dot', 'Live Hazard Map', ['hazard-map']];
$requests = ['disaster-requests-create', 'fa-file-circle-plus', 'Fill Up Disaster Request Form', ['disaster-requests', 'disaster-requests-create', 'disaster-requests-show']];
$requests[3] = array_merge($requests[3], ['disaster-requests-resource-show','disaster-requests-resource-review','disaster-requests-resource-allocate','disaster-requests-resource-complete','disaster-requests-resource-retry']);
if ($currentRole==='ICDRRMO') { $requests[0]='disaster-requests'; $requests[2]='Disaster Request Review'; }
$residents = ['pending-residents', 'fa-users-gear', 'Residents Account Management', ['pending-residents', 'residents-management']];
$navigation = [];
if ($currentRole === 'Resident') {
    $navigation = ['Overview' => [$overview], 'My community' => [$events,
        ['notifications', 'fa-hand-holding-heart', 'Relief Goods & Hub (Notifications & Alerts)', ['notifications']]], 'My account' => [$profileLink]];
} elseif ($currentRole === 'Responder') {
    $fieldReports[2] = 'Submit Fields';
    $responderAllocations = $allocations;
    $responderAllocations[2] = 'Priority Allocations';
    $navigation = ['Overview' => [$overview], 'Field operations' => [$fieldReports, $events],
        'Decision support' => [$resources], 'My account' => [$profileLink]];
} elseif ($currentRole === 'Barangay Head') {
    $navigation = ['Overview' => [$overview], 'Community preparedness' => [$hazards, $events],
        'Operations & resources' => [$requests, $resources, $fieldReports],
        'Administration' => [$residents], 'My account' => [$profileLink]];
} elseif ($currentRole === 'ICDRRMO') {
    $residents[2] = 'Resident Approvals';
    $navigation = ['Overview' => [$overview], 'Community preparedness' => [$hazards, $events],
        'Operations & resources' => [$requests, $resources, $fieldReports],
        'Administration' => [['users', 'fa-user-gear', 'System User Accounts', ['users', 'users-create']], $residents,
            ['relief-claims-oversight', 'fa-hand-holding-heart', 'Relief Claims Oversight', ['relief-claims-oversight']]],
        'My account' => [$profileLink]];
}
$currentRole = (string)($currentRole ?? 'Guest');
$assetVersion = '20261105-brand2';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="theme-color" content="#0F766E">
    <title><?= htmlspecialchars($title ?? 'Community preparedness') ?> — Padayon Hinaplanon</title>
    <link rel="icon" type="image/png" href="assets/images/barangay-hinaplanon-seal.png">
    <link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css" integrity="sha256-p4NxAoJBhIIN+hmNHrzRCf9tD/miZyoHS5obTRR9BMY=" crossorigin="">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="assets/css/style.css?v=<?= $assetVersion ?>">
    <link rel="stylesheet" href="assets/css/design-system.css?v=<?= $assetVersion ?>">
    <noscript><style>@media(max-width:1023px){.app-shell .sidebar{position:static;transform:none;width:100%}.app-wrapper{display:block}.app-shell .main-content-wrapper{width:100%;margin:0}.topbar{position:static}}</style></noscript>
</head>
<body class="app-shell" data-page="<?= htmlspecialchars($route) ?>">
<a class="skip-link" href="#main-content">Skip to main content</a>
<div class="app-wrapper">
    <aside class="sidebar" id="app-sidebar" aria-label="Main navigation">
        <a class="sidebar-brand" href="index.php?route=dashboard" aria-label="Padayon Hinaplanon dashboard"><?php require __DIR__ . '/../components/brand.php'; ?></a>
        <nav aria-label="<?= htmlspecialchars($currentRole) ?> pages">
            <ul class="sidebar-nav">
                <?php foreach ($navigation as $group => $links): ?>
                    <?php foreach ($links as [$target, $icon, $label, $activeRoutes]): ?>
                        <?php $active = in_array($route, $activeRoutes, true); ?>
                        <li class="<?= $active ? 'active' : '' ?>">
                            <a href="index.php?route=<?= $target ?>" title="<?= htmlspecialchars($label) ?>" <?= $active ? 'aria-current="page"' : '' ?>>
                                <i class="fa <?= $icon ?>" aria-hidden="true"></i>
                                <span class="nav-label"><?= htmlspecialchars($label) ?></span>
                                <?php if ($target === 'notifications' && !empty($unreadNotificationCount)): ?>
                                    <span class="badge badge-danger"><?= (int)$unreadNotificationCount ?><span class="sr-only"> unread notifications</span></span>
                                <?php endif; ?>
                            </a>
                        </li>
                    <?php endforeach; ?>
                <?php endforeach; ?>
            </ul>
        </nav>
        <div class="sidebar-footnote">Barangay Hinaplanon<br>Iligan City, Philippines</div>
    </aside>
    <button class="sidebar-backdrop" id="sidebar-backdrop" type="button" aria-label="Close navigation" tabindex="-1"></button>
    <div class="main-content-wrapper">
        <header class="topbar">
            <div class="topbar-start">
                <button type="button" class="icon-button" id="sidebar-toggle" aria-controls="app-sidebar" aria-expanded="true" aria-label="Collapse navigation" hidden>
                    <i class="fa fa-bars" aria-hidden="true"></i>
                </button>
                <img class="barangay-seal header-seal" src="assets/images/barangay-hinaplanon-seal.png" width="36" height="36" alt="Official seal of Barangay Hinaplanon, Iligan City">
                <div>
                    <div class="page-kicker">Padayon Hinaplanon</div>
                    <h1 class="page-title-header"><?= htmlspecialchars($title ?? 'Community preparedness') ?></h1>
                </div>
            </div>
            <div class="topbar-actions">
                <?php if ($currentRole === 'Resident'): ?>
                    <a href="index.php?route=notifications" class="notif-bell-btn" title="Notifications & Alerts" aria-label="View notifications and relief updates">
                        <i class="fa fa-bell" aria-hidden="true"></i>
                        <span id="notifBadgeCount" class="notif-badge-count" style="<?= empty($unreadNotificationCount) ? 'display: none;' : '' ?>"><?= (int)($unreadNotificationCount ?? 0) ?></span>
                    </a>
                <?php endif; ?>
                <details class="account-menu" id="account-menu">
                    <summary aria-label="Account menu">
                        <span class="account-avatar" aria-hidden="true"><?= htmlspecialchars(mb_substr($currentUser['firstname'] ?? 'U', 0, 1)) ?></span>
                        <span class="account-copy">
                            <span class="account-name"><?= htmlspecialchars(trim(($currentUser['firstname'] ?? '') . ' ' . ($currentUser['lastname'] ?? ''))) ?></span>
                            <span class="account-role"><?= htmlspecialchars($currentRole) ?></span>
                        </span>
                        <i class="fa fa-chevron-down account-chevron" aria-hidden="true"></i>
                    </summary>
                    <div class="account-dropdown">
                        <a href="index.php?route=profile"><i class="fa fa-user" aria-hidden="true"></i> My profile</a>
                        <a href="index.php?route=logout" class="sign-out"><i class="fa fa-right-from-bracket" aria-hidden="true"></i> Sign out</a>
                    </div>
                </details>
            </div>
        </header>
        <main class="content-container" id="main-content" tabindex="-1">
            <?php foreach (($flashes ?? []) as $type => $messages): ?>
                <?php foreach ($messages as $msg): ?>
                    <div class="alert alert-<?= htmlspecialchars($type) ?>" role="<?= in_array($type, ['danger', 'warning']) ? 'alert' : 'status' ?>">
                        <i class="fa fa-circle-info" aria-hidden="true"></i><div><?= htmlspecialchars($msg) ?></div>
                    </div>
                <?php endforeach; ?>
            <?php endforeach; ?>
            <?= $content ?>
        </main>
    </div>
</div>
<script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js" integrity="sha256-20nQCchB9co0qIjJZRGuk2/Z9VM+kNiyxNV1lvTlZBo=" crossorigin=""></script>
<script src="assets/js/app.js?v=<?= $assetVersion ?>"></script>
<script src="assets/js/ui.js?v=<?= $assetVersion ?>"></script>
</body>
</html>
