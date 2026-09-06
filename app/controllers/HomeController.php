<?php

final class HomeController
{
    public static function dashboard(): void
    {
        require_auth();
        $pdo = Database::connection();
        $visibility = has_role('Admin', 'Editor') ? '' : " AND status = 'Published'";
        $counts = [];
        foreach (['Article', 'Error', 'SOP', 'Request'] as $type) {
            $stmt = $pdo->prepare("SELECT COUNT(*) FROM knowledge_articles WHERE deleted_at IS NULL AND type = ? {$visibility}");
            $stmt->execute([$type]);
            $counts[$type] = (int) $stmt->fetchColumn();
        }
        $popular = $pdo->query(Article::baseSelect() . " WHERE a.deleted_at IS NULL AND a.status = 'Published' ORDER BY a.view_count DESC, a.updated_at DESC LIMIT 5")->fetchAll();
        $recentSops = $pdo->query(Article::baseSelect() . " WHERE a.deleted_at IS NULL AND a.status = 'Published' AND a.type = 'SOP' ORDER BY a.updated_at DESC LIMIT 4")->fetchAll();
        render('dashboard', [
            'title' => 'Dashboard', 'counts' => $counts, 'popular' => $popular, 'recentSops' => $recentSops,
            'systems' => Taxonomy::all('systems'), 'carriers' => Taxonomy::all('carriers'),
        ]);
    }

    public static function guide(): void
    {
        require_auth();
        verify_csrf();
        $issue = trim($_POST['issue_type'] ?? '');
        $systemId = (int) ($_POST['system_id'] ?? 0);
        $carrierId = (int) ($_POST['carrier_id'] ?? 0);
        $outcome = trim($_POST['outcome'] ?? '');
        if (!in_array($issue, ['order','shipment','label','rate','address','api','access','configuration'], true)) {
            flash('danger', 'Choose an issue type to continue.');
            redirect('/#troubleshooter');
        }
        $terms = [
            'order' => 'order import', 'shipment' => 'shipment validation', 'label' => 'label', 'rate' => 'rate',
            'address' => 'address postal code', 'api' => 'API', 'access' => 'access request', 'configuration' => 'configuration',
        ];
        $query = trim($terms[$issue] . ' ' . $outcome);
        $filters = ['system_id' => $systemId, 'carrier_id' => $carrierId];
        $results = Article::search($query, false, $filters);
        $logId = self::logSearch($query, count($results));
        render('search', [
            'title' => 'Guided troubleshooting', 'query' => $query, 'results' => $results, 'logId' => $logId,
            'guided' => true, 'systems' => Taxonomy::all('systems'), 'carriers' => Taxonomy::all('carriers'),
        ]);
    }

    public static function search(): void
    {
        require_auth();
        $query = trim($_GET['q'] ?? '');
        $results = [];
        $logId = null;
        if ($query !== '') {
            $results = Article::search($query, has_role('Admin', 'Editor'), $_GET);
            $logId = self::logSearch($query, count($results));
        }
        render('search', [
            'title' => 'Search', 'query' => $query, 'results' => $results, 'logId' => $logId,
            'systems' => Taxonomy::all('systems'), 'carriers' => Taxonomy::all('carriers'),
        ]);
    }

    public static function selectSearchResult(): void
    {
        require_auth();
        verify_csrf();
        $logId = (int) ($_POST['log_id'] ?? 0);
        $articleId = (int) ($_POST['article_id'] ?? 0);
        $stmt = Database::connection()->prepare('UPDATE search_logs SET selected_article_id = ?, selected_at = CURRENT_TIMESTAMP WHERE id = ? AND user_id = ?');
        $stmt->execute([$articleId, $logId, current_user()['id']]);
        redirect('/articles/' . $articleId);
    }

    private static function logSearch(string $query, int $count): int
    {
        $stmt = Database::connection()->prepare('INSERT INTO search_logs (user_id, query, result_count, created_at) VALUES (?, ?, ?, CURRENT_TIMESTAMP)');
        $stmt->execute([current_user()['id'], mb_substr($query, 0, 500), $count]);
        return (int) Database::connection()->lastInsertId();
    }

    public static function taxonomyPage(string $kind): void
    {
        require_auth();
        $table = $kind === 'systems' ? 'systems' : 'carriers';
        $items = Taxonomy::all($table);
        $pdo = Database::connection();
        foreach ($items as &$item) {
            $field = $table === 'systems' ? 'system_id' : 'carrier_id';
            $stmt = $pdo->prepare("SELECT COUNT(*) FROM knowledge_articles WHERE {$field} = ? AND status = 'Published' AND deleted_at IS NULL");
            $stmt->execute([$item['id']]);
            $item['article_count'] = (int) $stmt->fetchColumn();
        }
        render('taxonomy_index', ['title' => ucfirst($kind), 'kind' => $kind, 'items' => $items]);
    }
}
