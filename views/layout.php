<?php $user = current_user(); $flashes = take_flashes(); ?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="<?= e(csrf_token()) ?>">
    <title><?= e($title ?? 'Support Hub') ?> | Support Hub</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.8/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.13.1/font/bootstrap-icons.min.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <link href="/assets/app.css" rel="stylesheet">
</head>
<body class="<?= $user ? 'app-shell' : 'auth-shell' ?>">
<?php if ($user): ?>
    <aside class="sidebar offcanvas-lg offcanvas-start" tabindex="-1" id="sidebar" aria-label="Main navigation">
        <div class="sidebar-brand">
            <span class="brand-mark"><i class="bi bi-life-preserver"></i></span>
            <span><strong>Support Hub</strong><small>Wise Operations</small></span>
            <button class="btn-close btn-close-white d-lg-none ms-auto" data-bs-dismiss="offcanvas" data-bs-target="#sidebar" aria-label="Close navigation"></button>
        </div>
        <nav class="nav flex-column px-3 pb-4">
            <a class="nav-link <?= active_nav('/') ?>" href="/"><i class="bi bi-grid-1x2"></i>Dashboard</a>
            <a class="nav-link <?= active_nav('/search') ?>" href="/search"><i class="bi bi-search"></i>Search</a>
            <div class="nav-label">Documentation</div>
            <a class="nav-link <?= active_nav('/knowledge-base') ?>" href="/knowledge-base"><i class="bi bi-journal-text"></i>Knowledge Base</a>
            <a class="nav-link <?= active_nav('/errors') ?>" href="/errors"><i class="bi bi-exclamation-octagon"></i>Error Database</a>
            <a class="nav-link <?= active_nav('/sops') ?>" href="/sops"><i class="bi bi-list-check"></i>SOP Library</a>
            <a class="nav-link <?= active_nav('/requests') ?>" href="/requests"><i class="bi bi-inbox"></i>Requests</a>
            <div class="nav-label">Directories</div>
            <a class="nav-link <?= active_nav('/systems') ?>" href="/systems"><i class="bi bi-hdd-stack"></i>Systems</a>
            <a class="nav-link <?= active_nav('/carriers') ?>" href="/carriers"><i class="bi bi-truck"></i>Carriers</a>
            <?php if (has_role('Admin')): ?>
                <a class="nav-link <?= active_nav('/reports') ?>" href="/reports"><i class="bi bi-bar-chart"></i>Reports</a>
            <?php endif; ?>
            <?php if (has_role('Admin', 'Editor')): ?>
                <div class="nav-label">Management</div>
                <a class="nav-link <?= active_nav('/admin') ?>" href="/admin"><i class="bi bi-gear"></i>Administration</a>
            <?php endif; ?>
        </nav>
    </aside>
    <div class="app-main">
        <header class="topbar">
            <button class="btn btn-icon d-lg-none" type="button" data-bs-toggle="offcanvas" data-bs-target="#sidebar" aria-label="Open navigation"><i class="bi bi-list fs-4"></i></button>
            <form class="top-search" action="/search" method="get" role="search">
                <i class="bi bi-search"></i>
                <input name="q" type="search" value="<?= e($_GET['q'] ?? '') ?>" placeholder="Search an error, issue, SOP, request, or system..." aria-label="Global search">
            </form>
            <div class="dropdown">
                <button class="btn user-menu dropdown-toggle" data-bs-toggle="dropdown" aria-expanded="false">
                    <span class="avatar"><?= e(strtoupper(substr($user['name'], 0, 1))) ?></span>
                    <span class="d-none d-md-inline text-start"><strong><?= e($user['name']) ?></strong><small><?= e($user['role_name']) ?></small></span>
                </button>
                <div class="dropdown-menu dropdown-menu-end p-2 shadow-sm">
                    <div class="px-2 py-1 small text-secondary"><?= e($user['email']) ?></div>
                    <hr class="dropdown-divider">
                    <form method="post" action="/logout"><?= csrf_field() ?><button class="dropdown-item text-danger"><i class="bi bi-box-arrow-right me-2"></i>Sign out</button></form>
                </div>
            </div>
        </header>
        <main class="page-content">
            <?php foreach ($flashes as $flash): ?>
                <div class="alert alert-<?= e($flash['type']) ?> alert-dismissible fade show" role="alert">
                    <?= e($flash['message']) ?><button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                </div>
            <?php endforeach; ?>
            <?= $content ?>
        </main>
    </div>
<?php else: ?>
    <?= $content ?>
<?php endif; ?>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.8/dist/js/bootstrap.bundle.min.js"></script>
<script src="/assets/app.js"></script>
</body>
</html>
