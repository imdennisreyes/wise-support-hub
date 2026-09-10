<?php

final class Article
{
    public static function baseSelect(string $extraColumns = ''): string
    {
        return 'SELECT a.*, s.name AS system_name, m.name AS module_name, c.name AS category_name, cr.name AS carrier_name, u.name AS owner_name' . $extraColumns . '
            FROM knowledge_articles a
            LEFT JOIN systems s ON s.id = a.system_id
            LEFT JOIN modules m ON m.id = a.module_id
            LEFT JOIN categories c ON c.id = a.category_id
            LEFT JOIN carriers cr ON cr.id = a.carrier_id
            LEFT JOIN users u ON u.id = a.owner_id';
    }

    public static function find(int $id, bool $publishedOnly = false): ?array
    {
        $sql = self::baseSelect() . ' WHERE a.id = ? AND a.deleted_at IS NULL' . ($publishedOnly ? " AND a.status = 'Published'" : '');
        $stmt = Database::connection()->prepare($sql);
        $stmt->execute([$id]);
        $row = $stmt->fetch();
        if (!$row) return null;
        $row['tags'] = self::tags($id);
        return $row;
    }

    public static function tags(int $id): array
    {
        $stmt = Database::connection()->prepare('SELECT t.* FROM tags t JOIN article_tags at ON at.tag_id = t.id WHERE at.article_id = ? ORDER BY t.name');
        $stmt->execute([$id]);
        return $stmt->fetchAll();
    }

    public static function related(int $id): array
    {
        $stmt = Database::connection()->prepare(self::baseSelect() . ' JOIN related_articles ra ON ra.related_article_id = a.id WHERE ra.article_id = ? AND a.deleted_at IS NULL ORDER BY a.title');
        $stmt->execute([$id]);
        return $stmt->fetchAll();
    }

    public static function versions(int $id): array
    {
        $stmt = Database::connection()->prepare('SELECT av.*, u.name AS changed_by_name FROM article_versions av LEFT JOIN users u ON u.id = av.changed_by WHERE av.article_id = ? ORDER BY av.version_number DESC');
        $stmt->execute([$id]);
        return $stmt->fetchAll();
    }

    public static function browse(array $filters, bool $canSeeDrafts = false): array
    {
        $where = ['a.deleted_at IS NULL'];
        $params = [];
        if (!$canSeeDrafts) $where[] = "a.status = 'Published'";
        foreach (['type', 'status', 'severity'] as $field) {
            if (!empty($filters[$field])) { $where[] = "a.{$field} = ?"; $params[] = $filters[$field]; }
        }
        foreach (['system_id', 'category_id', 'carrier_id'] as $field) {
            if (!empty($filters[$field])) { $where[] = "a.{$field} = ?"; $params[] = (int) $filters[$field]; }
        }
        if (!empty($filters['updated'])) {
            $days = in_array((int) $filters['updated'], [7, 30, 90], true) ? (int) $filters['updated'] : 30;
            $modifier = Database::isSqlite() ? "datetime('now', '-{$days} days')" : "DATE_SUB(NOW(), INTERVAL {$days} DAY)";
            $where[] = "a.updated_at >= {$modifier}";
        }
        $sql = self::baseSelect() . ' WHERE ' . implode(' AND ', $where) . ' ORDER BY a.updated_at DESC LIMIT 200';
        $stmt = Database::connection()->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll();
    }

    public static function search(string $query, bool $canSeeDrafts = false, array $filters = []): array
    {
        $query = trim(preg_replace('/\s+/', ' ', $query));
        if ($query === '') return [];
        $pdo = Database::connection();
        $published = $canSeeDrafts ? '' : " AND a.status = 'Published'";
        $filterSql = '';
        $filterParams = [];
        foreach (['system_id', 'carrier_id'] as $field) {
            if (!empty($filters[$field])) { $filterSql .= " AND a.{$field} = ?"; $filterParams[] = (int) $filters[$field]; }
        }
        if (!empty($filters['type'])) { $filterSql .= ' AND a.type = ?'; $filterParams[] = $filters['type']; }
        $like = '%' . $query . '%';
        $starts = $query . '%';
        $tokens = array_values(array_filter(preg_split('/[^\pL\pN_.-]+/u', $query) ?: [], fn($token) => mb_strlen($token) >= 2));
        $tokens = array_slice(array_unique($tokens), 0, 10);
        if (!$tokens) $tokens = [$query];
        $haystack = "COALESCE(a.title,'') || ' ' || COALESCE(a.error_code,'') || ' ' || COALESCE(a.error_message,'') || ' ' || COALESCE(a.description,'') || ' ' || COALESCE(a.root_cause,'') || ' ' || COALESCE(a.resolution,'') || ' ' || COALESCE(a.sop_steps,'') || ' ' || COALESCE(a.keywords,'') || ' ' || COALESCE(s.name,'') || ' ' || COALESCE(cr.name,'')";
        if (!Database::isSqlite()) {
            $haystack = "CONCAT_WS(' ', a.title, a.error_code, a.error_message, a.description, a.root_cause, a.resolution, a.sop_steps, a.keywords, s.name, cr.name)";
        }
        $score = "CASE WHEN LOWER(a.error_code) = LOWER(?) THEN 100 WHEN LOWER(a.error_message) = LOWER(?) THEN 92 WHEN LOWER(a.error_message) LIKE LOWER(?) THEN 86 WHEN LOWER(a.title) = LOWER(?) THEN 82 WHEN LOWER(a.title) LIKE LOWER(?) THEN 76 WHEN LOWER(a.keywords) LIKE LOWER(?) THEN 68 WHEN LOWER(s.name) LIKE LOWER(?) OR LOWER(cr.name) LIKE LOWER(?) THEN 58 ELSE 42 END";
        $tokenSql = implode(' AND ', array_fill(0, count($tokens), "LOWER({$haystack}) LIKE LOWER(?)"));
        $sql = self::baseSelect(", {$score} AS relevance_score") . " WHERE a.deleted_at IS NULL {$published} {$filterSql} AND {$tokenSql} ORDER BY relevance_score DESC, a.updated_at DESC LIMIT 100";
        $params = [$query, $query, $like, $query, $starts, $like, $like, $like, ...$filterParams, ...array_map(fn($token) => '%' . $token . '%', $tokens)];
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $results = $stmt->fetchAll();
        if (!Database::isSqlite() && count($results) < 25) {
            try {
                $ftSql = self::baseSelect(", MATCH(a.title,a.error_code,a.error_message,a.description,a.root_cause,a.resolution,a.sop_steps,a.keywords) AGAINST (? IN NATURAL LANGUAGE MODE) * 10 AS relevance_score") . " WHERE a.deleted_at IS NULL {$published} {$filterSql} AND MATCH(a.title,a.error_code,a.error_message,a.description,a.root_cause,a.resolution,a.sop_steps,a.keywords) AGAINST (? IN NATURAL LANGUAGE MODE) ORDER BY relevance_score DESC LIMIT 100";
                $ft = $pdo->prepare($ftSql);
                $ft->execute([$query, ...$filterParams, $query]);
                $seen = array_column($results, 'id');
                foreach ($ft->fetchAll() as $row) if (!in_array($row['id'], $seen)) $results[] = $row;
            } catch (PDOException) {
                // LIKE results remain valid if a host has not enabled the FULLTEXT index yet.
            }
        }
        return $results;
    }

    public static function save(array $data, ?int $id, int $actorId): int
    {
        $pdo = Database::connection();
        $isNew = !$id;
        $fields = ['type','title','system_id','module_id','category_id','carrier_id','error_code','severity','error_message','description','root_cause','resolution','sop_steps','validation_steps','escalation_path','keywords','status','owner_id','is_sample'];
        $values = [];
        foreach ($fields as $field) $values[$field] = ($data[$field] ?? '') === '' ? null : $data[$field];
        $values['is_sample'] = !empty($data['is_sample']) ? 1 : 0;
        $pdo->beginTransaction();
        try {
            if ($id) {
                $set = implode(', ', array_map(fn($f) => "$f = ?", $fields));
                $stmt = $pdo->prepare("UPDATE knowledge_articles SET {$set}, updated_at = CURRENT_TIMESTAMP WHERE id = ? AND deleted_at IS NULL");
                $stmt->execute([...array_values($values), $id]);
            } else {
                $columns = implode(',', $fields);
                $marks = implode(',', array_fill(0, count($fields), '?'));
                $stmt = $pdo->prepare("INSERT INTO knowledge_articles ({$columns}, created_by, updated_by, created_at, updated_at) VALUES ({$marks}, ?, ?, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)");
                $stmt->execute([...array_values($values), $actorId, $actorId]);
                $id = (int) $pdo->lastInsertId();
            }
            if ($id) $pdo->prepare('UPDATE knowledge_articles SET updated_by = ? WHERE id = ?')->execute([$actorId, $id]);
            self::syncTags($id, $data['tags'] ?? '');
            self::syncRelated($id, $data['related_ids'] ?? []);
            self::recordVersion($id, $actorId, $data['change_summary'] ?? ($isNew ? 'Article created' : 'Article updated'));
            $pdo->commit();
            return $id;
        } catch (Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }
    }

    private static function syncTags(int $id, string|array $tags): void
    {
        $pdo = Database::connection();
        $pdo->prepare('DELETE FROM article_tags WHERE article_id = ?')->execute([$id]);
        $names = is_array($tags) ? $tags : explode(',', $tags);
        $stmt = $pdo->prepare('INSERT INTO article_tags (article_id, tag_id) VALUES (?, ?)');
        foreach (array_unique(array_filter(array_map('trim', $names))) as $name) {
            $tagId = Taxonomy::findOrCreate('tags', $name);
            if ($tagId) $stmt->execute([$id, $tagId]);
        }
    }

    private static function syncRelated(int $id, array|string $related): void
    {
        $pdo = Database::connection();
        $pdo->prepare('DELETE FROM related_articles WHERE article_id = ?')->execute([$id]);
        $stmt = $pdo->prepare('INSERT INTO related_articles (article_id, related_article_id) VALUES (?, ?)');
        foreach ((array) $related as $relatedId) {
            $relatedId = (int) $relatedId;
            if ($relatedId && $relatedId !== $id) $stmt->execute([$id, $relatedId]);
        }
    }

    public static function recordVersion(int $id, int $actorId, string $summary): void
    {
        $pdo = Database::connection();
        $articleStmt = $pdo->prepare(self::baseSelect() . ' WHERE a.id = ?');
        $articleStmt->execute([$id]);
        $article = $articleStmt->fetch();
        if (!$article) throw new RuntimeException('Cannot version a missing article.');
        $article['tags'] = self::tags($id);
        $stmt = $pdo->prepare('SELECT COALESCE(MAX(version_number), 0) + 1 FROM article_versions WHERE article_id = ?');
        $stmt->execute([$id]);
        $version = (int) $stmt->fetchColumn();
        $snapshot = json_encode($article, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        $pdo->prepare('INSERT INTO article_versions (article_id, version_number, snapshot_json, change_summary, changed_by, created_at) VALUES (?, ?, ?, ?, ?, CURRENT_TIMESTAMP)')->execute([$id, $version, $snapshot, trim($summary) ?: 'Article updated', $actorId]);
    }

    // ─── API helpers ────────────────────────────────────────────────────────

    /**
     * Return a filtered, paginated list of published articles for the REST API.
     *
     * Supported $params keys:
     *   updated_since  ISO-8601 datetime string – only articles updated on or after this time
     *   search         Full-text search query string
     *   page           1-based page number (default 1)
     *   limit          Records per page, capped at 200 (default 100)
     */
    public static function apiList(array $params): array
    {
        $pdo  = Database::connection();
        $page  = max(1, (int) ($params['page']  ?? 1));
        $limit = min(200, max(1, (int) ($params['limit'] ?? 100)));
        $offset = ($page - 1) * $limit;

        // ── search path ────────────────────────────────────────────────────
        if (!empty($params['search'])) {
            $rows = self::search(trim($params['search']));
            // apply updated_since filter on top of search results
            if (!empty($params['updated_since'])) {
                $since = $params['updated_since'];
                $rows  = array_values(array_filter($rows, fn($r) => $r['updated_at'] >= $since));
            }
            $total = count($rows);
            $rows  = array_slice($rows, $offset, $limit);
            return ['total' => $total, 'page' => $page, 'limit' => $limit, 'articles' => array_map([self::class, 'formatForApi'], $rows)];
        }

        // ── browse path ────────────────────────────────────────────────────
        $where  = ["a.deleted_at IS NULL", "a.status = 'Published'"];
        $bindParams = [];

        if (!empty($params['updated_since'])) {
            $where[]     = 'a.updated_at >= ?';
            $bindParams[] = $params['updated_since'];
        }

        // count query
        $countSql  = 'SELECT COUNT(*) FROM knowledge_articles a WHERE ' . implode(' AND ', $where);
        $countStmt = $pdo->prepare($countSql);
        $countStmt->execute($bindParams);
        $total = (int) $countStmt->fetchColumn();

        // data query
        $sql = self::baseSelect()
            . ' WHERE ' . implode(' AND ', $where)
            . ' ORDER BY a.updated_at DESC'
            . ' LIMIT ' . $limit . ' OFFSET ' . $offset;
        $stmt = $pdo->prepare($sql);
        $stmt->execute($bindParams);
        $rows = $stmt->fetchAll();

        // hydrate tags for each row
        foreach ($rows as &$row) {
            $row['tags'] = self::tags((int) $row['id']);
        }
        unset($row);

        return [
            'total'    => $total,
            'page'     => $page,
            'limit'    => $limit,
            'articles' => array_map([self::class, 'formatForApi'], $rows),
        ];
    }

    /**
     * Return a single published article formatted for the REST API.
     */
    public static function apiFind(int $id): ?array
    {
        $row = self::find($id, publishedOnly: true);
        return $row ? self::formatForApi($row) : null;
    }

    /**
     * Return all published article IDs + updated_at for sitemap generation.
     */
    public static function sitemapRows(): array
    {
        $sql = "SELECT id, updated_at FROM knowledge_articles
                WHERE deleted_at IS NULL AND status = 'Published'
                ORDER BY id";
        return Database::connection()->query($sql)->fetchAll();
    }

    /**
     * Shapes a raw article row into the public API response shape.
     * Only exposes fields that are safe for external consumption.
     */
    private static function formatForApi(array $row): array
    {
        // Build the "content" field from whichever rich-text columns are populated
        $contentParts = array_filter([
            $row['description']      ?? null,
            $row['root_cause']       ? "Root Cause:\n" . $row['root_cause']       : null,
            $row['resolution']       ? "Resolution:\n" . $row['resolution']       : null,
            $row['sop_steps']        ? "SOP Steps:\n"  . $row['sop_steps']        : null,
            $row['validation_steps'] ? "Validation:\n" . $row['validation_steps'] : null,
            $row['escalation_path']  ? "Escalation:\n" . $row['escalation_path']  : null,
        ]);
        $content = implode("\n\n", $contentParts);

        // Strip HTML tags so the AI gets plain text, not markup
        $content = html_entity_decode(strip_tags($content), ENT_QUOTES | ENT_HTML5, 'UTF-8');

        // Normalise updated_at to ISO-8601 / UTC
        $updatedAt = $row['updated_at'] ?? null;
        if ($updatedAt && !str_ends_with($updatedAt, 'Z')) {
            $updatedAt = (new DateTimeImmutable($updatedAt, new DateTimeZone('UTC')))->format('Y-m-d\TH:i:s\Z');
        }

        // Build tag name list
        $tagNames = [];
        if (!empty($row['tags'])) {
            $tagNames = array_column($row['tags'], 'name');
        }

        // Determine category: prefer category_name, fall back to type
        $category = $row['category_name'] ?? $row['system_name'] ?? $row['type'] ?? null;

        return [
            'id'         => (int) $row['id'],
            'type'       => $row['type'],
            'title'      => $row['title'],
            'content'    => $content,
            'category'   => $category,
            'system'     => $row['system_name']   ?? null,
            'module'     => $row['module_name']   ?? null,
            'carrier'    => $row['carrier_name']  ?? null,
            'severity'   => $row['severity']      ?? null,
            'error_code' => $row['error_code']    ?? null,
            'tags'       => $tagNames,
            'url'        => self::publicUrl((int) $row['id']),
            'updated_at' => $updatedAt,
            'visibility' => 'public',
        ];
    }

    /**
     * Returns the canonical public URL for an article.
     * Uses APP_URL env var if set, otherwise constructs from $_SERVER.
     */
    private static function publicUrl(int $id): string
    {
        $base = rtrim(getenv('APP_URL') ?: '', '/');
        if (!$base) {
            $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
            $host   = $_SERVER['HTTP_HOST'] ?? 'localhost';
            $base   = $scheme . '://' . $host;
        }
        return $base . '/articles/' . $id;
    }

    public static function archive(int $id, int $actorId): void
    {
        $pdo = Database::connection();
        $pdo->beginTransaction();
        try {
            $pdo->prepare("UPDATE knowledge_articles SET status = 'Archived', deleted_at = CURRENT_TIMESTAMP, updated_by = ?, updated_at = CURRENT_TIMESTAMP WHERE id = ?")->execute([$actorId, $id]);
            self::recordVersion($id, $actorId, 'Article archived');
            $pdo->commit();
        } catch (Throwable $e) { $pdo->rollBack(); throw $e; }
    }
}
