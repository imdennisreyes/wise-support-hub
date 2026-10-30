<?php

final class ArticleController
{
    public static function browse(?string $type = null): void
    {
        require_auth();
        $filters = $_GET;
        if ($type) $filters['type'] = $type;
        $articles = Article::browse($filters, has_role('Admin', 'Editor'));
        $titles = ['Error' => 'Error Database', 'SOP' => 'SOP Library', 'Request' => 'Requests'];
        render('browse', [
            'title' => $titles[$type] ?? 'Knowledge Base', 'fixedType' => $type, 'articles' => $articles,
            'systems' => Taxonomy::all('systems'), 'categories' => Taxonomy::all('categories'), 'carriers' => Taxonomy::all('carriers'),
        ]);
    }

    public static function show(int $id): void
    {
        require_auth();
        $article = Article::find($id, !has_role('Admin', 'Editor'));
        if (!$article) {
            http_response_code(404);
            render('error', ['title' => 'Article not found', 'message' => 'This article does not exist or is not available to your role.']);
            return;
        }
        Database::connection()->prepare('UPDATE knowledge_articles SET view_count = view_count + 1 WHERE id = ?')->execute([$id]);
        $feedbackStmt = Database::connection()->prepare('SELECT * FROM article_feedback WHERE article_id = ? AND user_id = ?');
        $feedbackStmt->execute([$id, current_user()['id']]);
        render('article', [
            'title' => $article['title'], 'article' => $article, 'related' => Article::related($id),
            'versions' => Article::versions($id), 'feedback' => $feedbackStmt->fetch() ?: null,
        ]);
    }

    public static function feedback(int $id): void
    {
        require_auth();
        verify_csrf();
        $article = Article::find($id, !has_role('Admin', 'Editor'));
        if (!$article) { http_response_code(404); exit('Article not found.'); }
        $helpful = ($_POST['helpful'] ?? '') === 'yes' ? 1 : 0;
        $reason = trim($_POST['reason'] ?? '');
        if (!$helpful && $reason === '') {
            flash('danger', 'Please tell us what was missing or unclear.');
            redirect('/articles/' . $id . '#feedback');
        }
        $pdo = Database::connection();
        if (Database::isSqlite()) {
            $sql = 'INSERT INTO article_feedback (article_id,user_id,is_helpful,reason,created_at,updated_at) VALUES (?,?,?,?,CURRENT_TIMESTAMP,CURRENT_TIMESTAMP) ON CONFLICT(article_id,user_id) DO UPDATE SET is_helpful=excluded.is_helpful, reason=excluded.reason, updated_at=CURRENT_TIMESTAMP';
        } else {
            $sql = 'INSERT INTO article_feedback (article_id,user_id,is_helpful,reason,created_at,updated_at) VALUES (?,?,?,?,CURRENT_TIMESTAMP,CURRENT_TIMESTAMP) ON DUPLICATE KEY UPDATE is_helpful=VALUES(is_helpful), reason=VALUES(reason), updated_at=CURRENT_TIMESTAMP';
        }
        $pdo->prepare($sql)->execute([$id, current_user()['id'], $helpful, $reason ?: null]);
        flash('success', 'Thank you. Your feedback has been recorded.');
        redirect('/articles/' . $id . '#feedback');
    }

    public static function download(int $id): void
    {
        require_auth();

        $stmt = Database::connection()->prepare(
            'SELECT source_document_name, source_document_data FROM knowledge_articles WHERE id = ? AND deleted_at IS NULL'
        );
        $stmt->execute([$id]);
        $row = $stmt->fetch();

        if (!$row || empty($row['source_document_data'])) {
            http_response_code(404);
            render('error', ['title' => 'No source file', 'message' => 'No original document is attached to this article.']);
            return;
        }

        $filename = $row['source_document_name'] ?: 'document';
        $ext      = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
        $mime     = match ($ext) {
            'pdf'  => 'application/pdf',
            'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
            default => 'application/octet-stream',
        };

        header('Content-Type: ' . $mime);
        header('Content-Disposition: attachment; filename="' . rawurlencode($filename) . '"');
        header('Content-Length: ' . strlen($row['source_document_data']));
        header('Cache-Control: no-store, no-cache');
        header('X-Content-Type-Options: nosniff');
        echo $row['source_document_data'];
        exit;
    }
}
