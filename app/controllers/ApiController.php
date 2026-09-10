<?php

declare(strict_types=1);

/**
 * Read-only REST API for article content.
 *
 * Authentication: every request must carry a valid bearer token.
 *   Authorization: Bearer <raw-token>
 *
 * Endpoints (all GET, all return JSON):
 *   GET /api/articles                      – paginated list of published articles
 *   GET /api/articles?search=receiving     – full-text search
 *   GET /api/articles?updated_since=<ISO>  – only articles updated on/after the date
 *   GET /api/articles?page=1&limit=100     – pagination
 *   GET /api/articles/{id}                 – single article
 *
 * All query parameters may be combined freely.
 */
final class ApiController
{
    // ─── Entry points called from index.php ──────────────────────────────────

    public static function articles(): void
    {
        self::boot();
        self::requireToken();

        $params = self::sanitiseListParams($_GET);
        $result = Article::apiList($params);

        self::json($result);
    }

    public static function article(int $id): void
    {
        self::boot();
        self::requireToken();

        $article = Article::apiFind($id);
        if (!$article) {
            self::json(['error' => 'Article not found.'], 404);
        }

        self::json(['article' => $article]);
    }

    // ─── Token authentication ─────────────────────────────────────────────────

    /**
     * Validates the Authorization: Bearer <token> header.
     * Compares SHA-256 hash of the supplied token against the api_tokens table.
     * Updates last_used_at on success.
     * Responds with 401 and terminates if the token is missing or invalid.
     */
    private static function requireToken(): void
    {
        $header = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
        if (!str_starts_with($header, 'Bearer ')) {
            self::json(['error' => 'Missing or malformed Authorization header. Expected: Bearer <token>.'], 401);
        }

        $rawToken  = substr($header, 7);
        $tokenHash = hash('sha256', $rawToken);

        $pdo  = Database::connection();

        // Use SHA2() on MySQL, hex(sha256()) is not a native SQLite function so we
        // hash in PHP and compare as a plain string — same result either way because
        // we stored the PHP hash at insertion time (see database/api_tokens.sql).
        $stmt = $pdo->prepare(
            "SELECT id FROM api_tokens WHERE token_hash = ? AND is_active = 1 LIMIT 1"
        );
        $stmt->execute([$tokenHash]);
        $tokenId = $stmt->fetchColumn();

        if (!$tokenId) {
            self::json(['error' => 'Invalid or inactive API token.'], 401);
        }

        // Soft-update last_used_at without raising an error if it fails (non-critical)
        try {
            $pdo->prepare("UPDATE api_tokens SET last_used_at = CURRENT_TIMESTAMP WHERE id = ?")
                ->execute([$tokenId]);
        } catch (Throwable) {
            // non-fatal – just log
            error_log('ApiController: could not update last_used_at for token ' . $tokenId);
        }
    }

    // ─── Helpers ─────────────────────────────────────────────────────────────

    /**
     * Set JSON Content-Type, CORS header for machine clients, and no-cache headers.
     * Called once at the top of every public method.
     */
    private static function boot(): void
    {
        // Only accept GET requests
        if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') {
            self::json(['error' => 'Method not allowed. The API is read-only.'], 405);
        }

        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-store, no-cache, must-revalidate');
        // Allow server-side AI integration services to call this endpoint.
        // Do NOT change this to a wildcard (*) unless you also verify the token there.
        $origin = $_SERVER['HTTP_ORIGIN'] ?? '';
        $allowed = array_filter(array_map('trim', explode(',', getenv('API_ALLOWED_ORIGINS') ?: '')));
        if ($origin && in_array($origin, $allowed, true)) {
            header('Access-Control-Allow-Origin: ' . $origin);
            header('Vary: Origin');
        }
        header('X-Content-Type-Options: nosniff');
        header('X-Robots-Tag: noindex');
    }

    /**
     * Validate and whitelist inbound query-string parameters.
     */
    private static function sanitiseListParams(array $raw): array
    {
        $params = [];

        // updated_since – must be a parseable datetime string
        if (!empty($raw['updated_since'])) {
            $ts = strtotime($raw['updated_since']);
            if ($ts !== false) {
                // Normalise to MySQL/SQLite comparable format
                $params['updated_since'] = date('Y-m-d H:i:s', $ts);
            }
        }

        // search – plain string, trimmed
        if (isset($raw['search']) && trim($raw['search']) !== '') {
            $params['search'] = trim($raw['search']);
        }

        // pagination
        $params['page']  = max(1, (int) ($raw['page']  ?? 1));
        $params['limit'] = min(200, max(1, (int) ($raw['limit'] ?? 100)));

        return $params;
    }

    /**
     * Encode $data as JSON, set the HTTP status code, and exit.
     * Never returns.
     */
    private static function json(array $data, int $status = 200): never
    {
        http_response_code($status);
        echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
        exit;
    }
}
