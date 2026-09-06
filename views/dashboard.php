<div class="page-heading d-flex flex-wrap justify-content-between align-items-end gap-3">
    <div><p class="eyebrow">Operations knowledge</p><h1>Good day, <?= e(explode(' ', current_user()['name'])[0]) ?></h1><p>Find an answer, follow a procedure, or investigate an operational issue.</p></div>
    <span class="sample-label"><i class="bi bi-flask"></i> Sample / Development Documentation</span>
</div>

<section class="search-hero" aria-labelledby="search-heading">
    <div><span class="hero-icon"><i class="bi bi-search"></i></span><h2 id="search-heading">What can we help you solve?</h2><p>Search across errors, procedures, requests, systems, and carrier guidance.</p></div>
    <form action="/search" method="get" class="hero-search"><i class="bi bi-search"></i><input name="q" type="search" placeholder="Search an error, issue, SOP, request, or system..." aria-label="Search all documentation" required><button class="btn btn-accent" type="submit">Search</button></form>
    <div class="quick-links">
        <span>Quick access:</span>
        <?php foreach (array_slice($systems, 0, 3) as $system): ?><a href="/knowledge-base?system_id=<?= (int) $system['id'] ?>"><i class="bi bi-hdd-stack"></i><?= e($system['name']) ?></a><?php endforeach; ?>
        <?php foreach (array_slice($carriers, 0, 3) as $carrier): ?><a href="/knowledge-base?carrier_id=<?= (int) $carrier['id'] ?>"><i class="bi bi-truck"></i><?= e($carrier['name']) ?></a><?php endforeach; ?>
    </div>
</section>

<section class="stat-grid" aria-label="Documentation counts">
    <?php $stats = [['Error','Errors','danger'],['SOP','SOPs','teal'],['Article','Articles','blue'],['Request','Requests','amber']]; ?>
    <?php foreach ($stats as [$key,$label,$color]): ?><a class="stat-card" href="<?= $key === 'Error' ? '/errors' : ($key === 'SOP' ? '/sops' : ($key === 'Request' ? '/requests' : '/knowledge-base?type=Article')) ?>"><span class="stat-icon <?= $color ?>"><i class="bi <?= article_icon($key) ?>"></i></span><span><strong><?= (int) $counts[$key] ?></strong><small><?= e($label) ?></small></span><i class="bi bi-arrow-right ms-auto"></i></a><?php endforeach; ?>
</section>

<div class="content-grid">
    <section>
        <div class="section-heading"><div><p class="eyebrow">Frequently consulted</p><h2>Popular documentation</h2></div><a href="/knowledge-base">View all <i class="bi bi-arrow-right"></i></a></div>
        <div class="article-list">
            <?php foreach ($popular as $article): ?><a class="article-row" href="/articles/<?= (int) $article['id'] ?>"><span class="type-icon"><i class="bi <?= article_icon($article['type']) ?>"></i></span><span class="article-row-main"><span><span class="badge type-badge"><?= e($article['type']) ?></span><?php if ($article['severity']): ?><span class="badge <?= severity_class($article['severity']) ?>"><?= e($article['severity']) ?></span><?php endif; ?></span><strong><?= e($article['title']) ?></strong><small><?= e($article['system_name'] ?? 'General') ?><?= $article['carrier_name'] ? ' · ' . e($article['carrier_name']) : '' ?></small></span><span class="views"><i class="bi bi-eye"></i><?= (int) $article['view_count'] ?></span><i class="bi bi-chevron-right"></i></a><?php endforeach; ?>
        </div>
    </section>
    <aside>
        <div class="section-heading"><div><p class="eyebrow">Recently updated</p><h2>Latest SOPs</h2></div></div>
        <div class="sop-list"><?php foreach ($recentSops as $sop): ?><a href="/articles/<?= (int) $sop['id'] ?>"><span class="sop-number">SOP</span><span><strong><?= e($sop['title']) ?></strong><small><?= e($sop['system_name']) ?> · <?= date('M j', strtotime($sop['updated_at'])) ?></small></span></a><?php endforeach; ?></div>
    </aside>
</div>

<section class="troubleshooter" id="troubleshooter">
    <div class="troubleshooter-intro"><span class="hero-icon light"><i class="bi bi-signpost-split"></i></span><div><p class="eyebrow">Guided support</p><h2>What should I do?</h2><p>Describe the operational context to surface the most relevant published procedures.</p></div></div>
    <form method="post" action="/troubleshoot" class="guide-form needs-validation" novalidate data-loading-form>
        <?= csrf_field() ?>
        <div><label class="form-label" for="issue_type">Issue type</label><select class="form-select" id="issue_type" name="issue_type" required><option value="">Choose an issue</option><?php foreach (['order','shipment','label','rate','address','api','access','configuration'] as $issue): ?><option value="<?= $issue ?>"><?= e(ucfirst($issue)) ?></option><?php endforeach; ?></select></div>
        <div><label class="form-label" for="guide_system">System</label><select class="form-select" id="guide_system" name="system_id"><option value="">Any system</option><?php foreach ($systems as $item): ?><option value="<?= (int) $item['id'] ?>"><?= e($item['name']) ?></option><?php endforeach; ?></select></div>
        <div><label class="form-label" for="guide_carrier">Carrier</label><select class="form-select" id="guide_carrier" name="carrier_id"><option value="">Any carrier</option><?php foreach ($carriers as $item): ?><option value="<?= (int) $item['id'] ?>"><?= e($item['name']) ?></option><?php endforeach; ?></select></div>
        <div><label class="form-label" for="outcome">Outcome or symptom</label><input class="form-control" id="outcome" name="outcome" placeholder="Optional details"></div>
        <button class="btn btn-light" type="submit" data-loading-text="Finding guidance..."><span>Find guidance</span><i class="bi bi-arrow-right"></i></button>
    </form>
</section>
