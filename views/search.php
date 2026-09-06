<div class="page-heading"><p class="eyebrow"><?= !empty($guided) ? 'Recommended next steps' : 'Global documentation' ?></p><h1><?= !empty($guided) ? 'Guided troubleshooting results' : 'Search' ?></h1><p><?= !empty($guided) ? 'Published guidance matched to the context you provided.' : 'Search errors, messages, procedures, and operational requests.' ?></p></div>
<form class="search-page-form" action="/search" method="get">
    <div class="search-box"><i class="bi bi-search"></i><input name="q" type="search" value="<?= e($query) ?>" placeholder="Search an error, issue, SOP, request, or system..." required><button class="btn btn-primary">Search</button></div>
    <div class="search-filters"><select class="form-select" name="type" aria-label="Type"><option value="">All types</option><?php foreach (['Article','Error','SOP','Request'] as $v): ?><option <?= ($_GET['type'] ?? '') === $v ? 'selected' : '' ?>><?= $v ?></option><?php endforeach; ?></select><select class="form-select" name="system_id" aria-label="System"><option value="">All systems</option><?php foreach ($systems as $item): ?><option value="<?= (int) $item['id'] ?>" <?= (string)($_GET['system_id'] ?? '') === (string)$item['id'] ? 'selected' : '' ?>><?= e($item['name']) ?></option><?php endforeach; ?></select><select class="form-select" name="carrier_id" aria-label="Carrier"><option value="">All carriers</option><?php foreach ($carriers as $item): ?><option value="<?= (int) $item['id'] ?>" <?= (string)($_GET['carrier_id'] ?? '') === (string)$item['id'] ? 'selected' : '' ?>><?= e($item['name']) ?></option><?php endforeach; ?></select></div>
</form>
<?php if ($query !== ''): ?>
    <div class="results-summary"><strong><?= count($results) ?></strong> result<?= count($results) === 1 ? '' : 's' ?> for “<?= e($query) ?>”</div>
    <?php if (!$results): ?><div class="empty-state"><i class="bi bi-search"></i><h2>No documentation found</h2><p>Try a shorter error message, exact error code, system, or carrier name. This search has been logged as a documentation gap.</p><a class="btn btn-outline-primary" href="/search">Clear search</a></div><?php endif; ?>
    <div class="result-list">
        <?php foreach ($results as $article): $score = min(100, max(1, (int) round((float)$article['relevance_score']))); ?>
            <article class="result-card">
                <div class="result-icon"><i class="bi <?= article_icon($article['type']) ?>"></i></div>
                <div class="result-body">
                    <div class="result-meta"><span class="badge type-badge"><?= e($article['type']) ?></span><span class="relevance"><i class="bi bi-stars"></i><?= $score >= 76 ? 'High relevance' : $score . '% match' ?></span><?php if ($article['is_sample']): ?><span class="sample-label compact">Sample / Development Documentation</span><?php endif; ?></div>
                    <h2><?= e($article['title']) ?></h2>
                    <div class="metadata"><span><i class="bi bi-hdd-stack"></i><?= e($article['system_name'] ?? 'General') ?></span><?php if ($article['carrier_name']): ?><span><i class="bi bi-truck"></i><?= e($article['carrier_name']) ?></span><?php endif; ?><?php if ($article['category_name']): ?><span><i class="bi bi-folder2"></i><?= e($article['category_name']) ?></span><?php endif; ?><?php if ($article['error_code']): ?><code><?= e($article['error_code']) ?></code><?php endif; ?></div>
                    <p><?= e($article['description']) ?></p>
                </div>
                <form action="/search/select" method="post" class="result-action" data-loading-form><?= csrf_field() ?><input type="hidden" name="log_id" value="<?= (int)$logId ?>"><input type="hidden" name="article_id" value="<?= (int)$article['id'] ?>"><button class="btn btn-outline-primary" data-loading-text="Opening..."><span><?= $article['type'] === 'SOP' ? 'View SOP' : 'View article' ?></span><i class="bi bi-arrow-right"></i></button></form>
            </article>
        <?php endforeach; ?>
    </div>
<?php else: ?><div class="empty-state"><i class="bi bi-compass"></i><h2>Start with what you know</h2><p>Enter an error code, message fragment, system, carrier, or task.</p></div><?php endif; ?>
