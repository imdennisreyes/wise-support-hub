<?php

declare(strict_types=1);

/**
 * MCP (Model Context Protocol) server – Streamable HTTP transport.
 *
 * Endpoint: POST /mcp
 * Auth:     Authorization: Bearer <token>  (same api_tokens table as ApiController)
 *
 * Exposed tools:
 *   search_knowledge_base(query, type?)  – full-text search across all articles
 *   get_article(id)                      – fetch a single article's full content
 *
 * MCP spec reference: https://spec.modelcontextprotocol.io/specification/2024-11-05/
 */
final class McpController
{
    private const PROTOCOL_VERSION = '2024-11-05';
    private const SERVER_NAME      = 'Wise Support Hub';
    private const SERVER_VERSION   = '1.0.0';

    // ─── Entry point ─────────────────────────────────────────────────────────

    public static function handle(): void
    {
        self::boot();

        try {
            self::requireToken();

            $body    = (string) file_get_contents('php://input');
            $payload = json_decode($body, true);

            if (!is_array($payload)) {
                self::output(self::makeError(null, -32700, 'Parse error: body is not valid JSON.'));
            }

            // Batch request (top-level array of request objects)
            if (isset($payload[0]) && is_array($payload[0])) {
                $responses = [];
                foreach ($payload as $req) {
                    if (is_array($req)) {
                        $r = self::dispatch($req);
                        if ($r !== null) $responses[] = $r;
                    }
                }
                if ($responses) {
                    self::output($responses);
                }
                http_response_code(204);
                exit;
            }

            // Single request
            $response = self::dispatch($payload);
            if ($response !== null) {
                self::output($response);
            }
            http_response_code(204);
            exit;

        } catch (Throwable $e) {
            error_log('McpController: unhandled error: ' . $e->getMessage());
            http_response_code(500);
            self::output(self::makeError(null, -32603, 'Internal server error: ' . $e->getMessage()));
        }
    }

    // ─── JSON-RPC dispatcher ─────────────────────────────────────────────────

    private static function dispatch(array $req): ?array
    {
        $id             = $req['id'] ?? null;
        $method         = $req['method'] ?? '';
        $params         = $req['params'] ?? [];
        $isNotification = !array_key_exists('id', $req);

        try {
            $result = match ($method) {
                'initialize'              => self::mInitialize(),
                'initialized'             => null,           // notification – no response
                'ping'                    => new stdClass(), // empty object per spec
                'tools/list'              => self::mToolsList(),
                'tools/call'              => self::mToolsCall($params),
                'notifications/cancelled' => null,           // notification – no response
                default => throw new McpRpcException(-32601, "Method not found: {$method}"),
            };
        } catch (McpRpcException $e) {
            if ($isNotification) return null;
            return self::makeError($id, $e->getCode(), $e->getMessage());
        } catch (Throwable $e) {
            error_log('McpController dispatch error: ' . $e->getMessage());
            if ($isNotification) return null;
            return self::makeError($id, -32603, 'Internal server error.');
        }

        if ($isNotification || $result === null) return null;

        return ['jsonrpc' => '2.0', 'id' => $id, 'result' => $result];
    }

    // ─── MCP method handlers ─────────────────────────────────────────────────

    private static function mInitialize(): array
    {
        return [
            'protocolVersion' => self::PROTOCOL_VERSION,
            'serverInfo'      => [
                'name'    => self::SERVER_NAME,
                'version' => self::SERVER_VERSION,
            ],
            'capabilities' => [
                'tools' => new stdClass(), // signals tool support; empty = no list-change notifications
            ],
            'instructions' => implode(' ', [
                'You are connected to the Wise Support Hub knowledge base.',
                'Use search_knowledge_base to find SOPs, error guides, and troubleshooting articles.',
                'Use get_article to read the full content of a specific article.',
                'Use create_article to add a new SOP, error guide, or support article.',
                'Use update_article to edit an existing article.',
                'Always cite the article title, type, and URL in your response.',
                'Only answer based on what is in the knowledge base — do not guess.',
                'When creating or updating articles, confirm the details with the user before submitting.',
            ]),
        ];
    }

    private static function mToolsList(): array
    {
        return [
            'tools' => [
                [
                    'name'        => 'search_knowledge_base',
                    'description' => 'Search the Wise Support Hub for SOPs, error guides, troubleshooting articles, and support procedures. Returns matching article titles, IDs, and content previews.',
                    'inputSchema' => [
                        'type'       => 'object',
                        'properties' => [
                            'query' => [
                                'type'        => 'string',
                                'description' => 'Keywords, error codes, system names, or phrases to search for.',
                            ],
                            'type' => [
                                'type'        => 'string',
                                'enum'        => ['Article', 'Error', 'SOP', 'Request'],
                                'description' => 'Optional: limit results to a specific article type.',
                            ],
                        ],
                        'required'             => ['query'],
                        'additionalProperties' => false,
                    ],
                ],
                [
                    'name'        => 'get_article',
                    'description' => 'Retrieve the full content of a specific knowledge base article by its numeric ID. Use this after search_knowledge_base to read a complete article.',
                    'inputSchema' => [
                        'type'       => 'object',
                        'properties' => [
                            'id' => [
                                'type'        => 'integer',
                                'description' => 'The numeric article ID returned by search_knowledge_base.',
                            ],
                        ],
                        'required'             => ['id'],
                        'additionalProperties' => false,
                    ],
                ],
                [
                    'name'        => 'create_article',
                    'description' => 'Create a new article in the Wise Support Hub knowledge base. Use this to add SOPs, error guides, troubleshooting articles, or support request templates. Always confirm details with the user before calling this tool.',
                    'inputSchema' => [
                        'type'       => 'object',
                        'properties' => [
                            'type'        => ['type' => 'string', 'enum' => ['Article', 'Error', 'SOP', 'Request'], 'description' => 'Article type.'],
                            'title'       => ['type' => 'string', 'description' => 'Clear, descriptive title for the article.'],
                            'description' => ['type' => 'string', 'description' => 'Main content — overview, context, or problem description.'],
                            'sop_steps'   => ['type' => 'string', 'description' => 'Step-by-step procedure (primarily for SOP type).'],
                            'resolution'  => ['type' => 'string', 'description' => 'Resolution steps (primarily for Error type).'],
                            'root_cause'  => ['type' => 'string', 'description' => 'Root cause explanation.'],
                            'error_code'  => ['type' => 'string', 'description' => 'Error code if applicable.'],
                            'error_message' => ['type' => 'string', 'description' => 'Error message text if applicable.'],
                            'severity'    => ['type' => 'string', 'enum' => ['Critical', 'High', 'Medium', 'Low'], 'description' => 'Severity level for Error type.'],
                            'keywords'    => ['type' => 'string', 'description' => 'Comma-separated search keywords.'],
                            'escalation_path' => ['type' => 'string', 'description' => 'Who to contact if unresolved.'],
                            'validation_steps' => ['type' => 'string', 'description' => 'Steps to confirm the issue is resolved.'],
                            'status'      => ['type' => 'string', 'enum' => ['Draft', 'Published'], 'description' => 'Publication status. Defaults to Draft.'],
                        ],
                        'required'             => ['type', 'title', 'description'],
                        'additionalProperties' => false,
                    ],
                ],
                [
                    'name'        => 'update_article',
                    'description' => 'Update an existing article in the Wise Support Hub. Only the fields you provide will be changed — omitted fields stay as-is. Always confirm changes with the user before calling this tool.',
                    'inputSchema' => [
                        'type'       => 'object',
                        'properties' => [
                            'id'          => ['type' => 'integer', 'description' => 'The ID of the article to update.'],
                            'title'       => ['type' => 'string', 'description' => 'New title.'],
                            'description' => ['type' => 'string', 'description' => 'New main content.'],
                            'sop_steps'   => ['type' => 'string', 'description' => 'Updated SOP steps.'],
                            'resolution'  => ['type' => 'string', 'description' => 'Updated resolution steps.'],
                            'root_cause'  => ['type' => 'string', 'description' => 'Updated root cause.'],
                            'error_code'  => ['type' => 'string', 'description' => 'Updated error code.'],
                            'error_message' => ['type' => 'string', 'description' => 'Updated error message.'],
                            'severity'    => ['type' => 'string', 'enum' => ['Critical', 'High', 'Medium', 'Low'], 'description' => 'Updated severity.'],
                            'keywords'    => ['type' => 'string', 'description' => 'Updated keywords.'],
                            'escalation_path' => ['type' => 'string', 'description' => 'Updated escalation path.'],
                            'validation_steps' => ['type' => 'string', 'description' => 'Updated validation steps.'],
                            'status'      => ['type' => 'string', 'enum' => ['Draft', 'Published', 'Under Review', 'Archived'], 'description' => 'Updated status.'],
                            'change_summary' => ['type' => 'string', 'description' => 'Brief summary of what changed (stored in version history).'],
                        ],
                        'required'             => ['id'],
                        'additionalProperties' => false,
                    ],
                ],
            ],
        ];
    }

    private static function mToolsCall(array $params): array
    {
        $name      = $params['name']      ?? '';
        $arguments = $params['arguments'] ?? [];

        return match ($name) {
            'search_knowledge_base' => self::toolSearch($arguments),
            'get_article'           => self::toolGetArticle($arguments),
            'create_article'        => self::toolCreateArticle($arguments),
            'update_article'        => self::toolUpdateArticle($arguments),
            default => throw new McpRpcException(-32602, "Unknown tool: {$name}"),
        };
    }

    // ─── Tool implementations ────────────────────────────────────────────────

    private static function toolSearch(array $args): array
    {
        $query = trim($args['query'] ?? '');
        if ($query === '') {
            throw new McpRpcException(-32602, 'The "query" argument is required and cannot be empty.');
        }

        $filters = [];
        $typeArg = $args['type'] ?? null;
        if ($typeArg && in_array($typeArg, ['Article', 'Error', 'SOP', 'Request'], true)) {
            $filters['type'] = $typeArg;
        }

        $rows = Article::search($query, false, $filters);
        $rows = array_slice($rows, 0, 20);

        if (empty($rows)) {
            return self::textResult(
                "No knowledge base articles found matching \"{$query}\".\n" .
                'Try different keywords, an error code, or a system name.'
            );
        }

        $lines = ['Found ' . count($rows) . ' result(s) for "' . $query . '":', ''];

        foreach ($rows as $i => $row) {
            $num = $i + 1;
            $lines[] = "{$num}. [{$row['type']}] {$row['title']} (ID: {$row['id']})";

            if (!empty($row['system_name']))  $lines[] = "   System:     {$row['system_name']}";
            if (!empty($row['carrier_name'])) $lines[] = "   Carrier:    {$row['carrier_name']}";
            if (!empty($row['error_code']))   $lines[] = "   Error Code: {$row['error_code']}";
            if (!empty($row['severity']))     $lines[] = "   Severity:   {$row['severity']}";

            // Short content preview from the first non-empty rich field
            $raw = $row['description'] ?? $row['resolution'] ?? $row['sop_steps'] ?? '';
            $preview = mb_substr(trim(strip_tags(html_entity_decode($raw, ENT_QUOTES | ENT_HTML5, 'UTF-8'))), 0, 200);
            if ($preview !== '') {
                $lines[] = '   Preview: ' . $preview . (mb_strlen($preview) >= 200 ? '…' : '');
            }

            $lines[] = "   → Call get_article(id: {$row['id']}) to read the full article.";
            $lines[] = '';
        }

        return self::textResult(implode("\n", $lines));
    }

    private static function toolGetArticle(array $args): array
    {
        $id = (int) ($args['id'] ?? 0);
        if ($id <= 0) {
            throw new McpRpcException(-32602, 'The "id" argument must be a positive integer.');
        }

        // Fetch raw row first (needed for image extraction and Draft support)
        $row = Article::find($id, publishedOnly: false);
        if (!$row) {
            return ['content' => [['type' => 'text', 'text' => "Article ID {$id} was not found."]], 'isError' => true];
        }

        $article = Article::apiFind($id); // formatted version (may be null if Draft)

        $sep   = str_repeat('─', 60);
        $lines = [
            "[{$row['type']}] {$row['title']}" . ($row['status'] !== 'Published' ? " [Status: {$row['status']}]" : ''),
            $sep,
        ];

        if (!empty($row['system_name']))  $lines[] = "System:     {$row['system_name']}";
        if (!empty($row['module_name']))  $lines[] = "Module:     {$row['module_name']}";
        if (!empty($row['carrier_name'])) $lines[] = "Carrier:    {$row['carrier_name']}";
        if (!empty($row['error_code']))   $lines[] = "Error Code: {$row['error_code']}";
        if (!empty($row['severity']))     $lines[] = "Severity:   {$row['severity']}";
        if ($article && $article['tags']) $lines[] = 'Tags:       ' . implode(', ', $article['tags']);

        $lines[] = "Updated:    {$row['updated_at']}";
        $lines[] = "URL:        " . ($article['url'] ?? self::buildArticleUrl($id));

        // Include original document download link if one was attached on import
        $docStmt = Database::connection()->prepare(
            'SELECT source_document_name FROM knowledge_articles WHERE id = ? AND source_document_name IS NOT NULL'
        );
        $docStmt->execute([$id]);
        $docName = $docStmt->fetchColumn();
        if ($docName) {
            $base = rtrim(getenv('APP_URL') ?: '', '/') ?: ('https://' . ($_SERVER['HTTP_HOST'] ?? 'wise-support-hub.onrender.com'));
            $lines[] = "Download:   {$base}/articles/{$id}/download  ({$docName})";
        }

        $lines[] = '';

        // Add text content
        if ($article) {
            $lines[] = $article['content'];
        } else {
            foreach (['description', 'root_cause', 'resolution', 'sop_steps', 'validation_steps', 'escalation_path'] as $f) {
                if (!empty($row[$f])) {
                    $lines[] = strip_tags(html_entity_decode($row[$f], ENT_QUOTES | ENT_HTML5, 'UTF-8'));
                    $lines[] = '';
                }
            }
        }

        // Extract and list images from all rich-text fields
        $imageUrls  = [];
        $hasEmbedded = false;
        $base        = rtrim(getenv('APP_URL') ?: '', '/');

        foreach (['description', 'root_cause', 'resolution', 'sop_steps', 'validation_steps', 'escalation_path'] as $f) {
            if (empty($row[$f])) continue;
            preg_match_all('/src\s*=\s*"([^"]+)"/i', $row[$f], $matches);
            foreach ($matches[1] as $src) {
                if (preg_match('#^data:image/#i', $src)) {
                    $hasEmbedded = true;                              // base64 — too large to send
                } elseif (preg_match('#^/uploads/#i', $src)) {
                    $imageUrls[] = $base . $src;                      // relative → full URL
                } elseif (preg_match('#^https?://#i', $src)) {
                    $imageUrls[] = $src;                              // already absolute
                }
            }
        }

        // Extract base64 images from all rich-text fields and include as MCP image content
        // so multimodal AI models (GPT-4V, Gemini, Claude Vision) can see them.
        $imageContent = [];
        $hasEmbedded  = false;

        foreach (['description', 'root_cause', 'resolution', 'sop_steps', 'validation_steps', 'escalation_path'] as $f) {
            if (empty($row[$f])) continue;
            preg_match_all('#src="data:(image/[^;]+);base64,([A-Za-z0-9+/=]+)"#', $row[$f], $imgMatches);
            foreach ($imgMatches[1] as $idx => $mime) {
                $hasEmbedded = true;
                // Limit to first 5 images and skip if base64 is >1MB to avoid overwhelming context
                if (count($imageContent) < 5 && strlen($imgMatches[2][$idx]) < 1_400_000) {
                    $imageContent[] = [
                        'type'     => 'image',
                        'data'     => $imgMatches[2][$idx],
                        'mimeType' => $mime,
                    ];
                }
            }
        }

        if ($imageUrls || $hasEmbedded) {
            $lines[] = str_repeat('─', 60);
            $lines[] = 'IMAGES IN THIS ARTICLE:';
            foreach (array_unique($imageUrls) as $url) {
                $lines[] = "  • " . $url;
            }
            if ($hasEmbedded) {
                $remaining = max(0, (int) preg_match_all('#src="data:image/#', implode('', array_filter([$row['description'] ?? '', $row['sop_steps'] ?? '']))) - count($imageContent));
                if ($remaining > 0) {
                    $lines[] = "  • {$remaining} additional image(s) omitted (too large for AI context — view at article URL above)";
                }
            }
        }

        $content = [['type' => 'text', 'text' => implode("\n", $lines)]];
        foreach ($imageContent as $imgItem) {
            $content[] = $imgItem;
        }

        return ['content' => $content];
    }

    /** Build a canonical article URL from APP_URL or request host. */
    private static function buildArticleUrl(int $id): string
    {
        $base = rtrim(getenv('APP_URL') ?: '', '/');
        if (!$base) {
            $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
            $base   = $scheme . '://' . ($_SERVER['HTTP_HOST'] ?? 'localhost');
        }
        return $base . '/articles/' . $id;
    }

    private static function toolCreateArticle(array $args): array
    {
        $type  = $args['type']  ?? '';
        $title = trim($args['title'] ?? '');
        $desc  = trim($args['description'] ?? '');

        if (!in_array($type, ['Article', 'Error', 'SOP', 'Request'], true)) {
            throw new McpRpcException(-32602, 'The "type" field must be Article, Error, SOP, or Request.');
        }
        if ($title === '') {
            throw new McpRpcException(-32602, 'The "title" field is required.');
        }
        if ($desc === '') {
            throw new McpRpcException(-32602, 'The "description" field is required.');
        }

        $actorId = self::resolveAuthorId();

        $data = [
            'type'             => $type,
            'title'            => $title,
            'description'      => $desc,
            'sop_steps'        => $args['sop_steps']        ?? null,
            'resolution'       => $args['resolution']       ?? null,
            'root_cause'       => $args['root_cause']       ?? null,
            'error_code'       => $args['error_code']       ?? null,
            'error_message'    => $args['error_message']    ?? null,
            'severity'         => $args['severity']         ?? null,
            'keywords'         => $args['keywords']         ?? null,
            'escalation_path'  => $args['escalation_path']  ?? null,
            'validation_steps' => $args['validation_steps'] ?? null,
            'status'           => $args['status']           ?? 'Draft',
            'system_id'        => null,
            'module_id'        => null,
            'category_id'      => null,
            'carrier_id'       => null,
            'owner_id'         => $actorId,
            'is_sample'        => 0,
            'tags'             => '',
            'related_ids'      => [],
            'change_summary'   => 'Created via MCP (ItemGPT)',
        ];

        $id      = Article::save($data, null, $actorId);
        $article = Article::apiFind($id);

        $lines = [
            '✓ Article created successfully.',
            '',
            "ID:     {$id}",
            "Type:   {$type}",
            "Title:  {$title}",
            "Status: " . ($args['status'] ?? 'Draft'),
            "URL:    " . ($article['url'] ?? ''),
            '',
            'The article is saved as a Draft. Log into the Support Hub to review and publish it.',
        ];

        return self::textResult(implode("\n", $lines));
    }

    private static function toolUpdateArticle(array $args): array
    {
        $id = (int) ($args['id'] ?? 0);
        if ($id <= 0) {
            throw new McpRpcException(-32602, 'The "id" argument must be a positive integer.');
        }

        $existing = Article::find($id);
        if (!$existing) {
            return ['content' => [['type' => 'text', 'text' => "Article ID {$id} was not found."]], 'isError' => true];
        }

        $actorId = self::resolveAuthorId();

        $updatable = ['title', 'description', 'sop_steps', 'resolution', 'root_cause',
                      'error_code', 'error_message', 'severity', 'keywords',
                      'escalation_path', 'validation_steps', 'status'];

        $data = [];
        foreach ($updatable as $field) {
            $data[$field] = array_key_exists($field, $args) ? $args[$field] : ($existing[$field] ?? null);
        }

        foreach (['type', 'system_id', 'module_id', 'category_id', 'carrier_id', 'owner_id', 'is_sample'] as $f) {
            $data[$f] = $existing[$f] ?? null;
        }
        $data['tags']           = implode(',', array_column($existing['tags'] ?? [], 'name'));
        $data['related_ids']    = [];
        $data['change_summary'] = trim($args['change_summary'] ?? 'Updated via MCP (ItemGPT)');

        Article::save($data, $id, $actorId);
        $article = Article::apiFind($id);

        $lines = [
            '✓ Article updated successfully.',
            '',
            "ID:     {$id}",
            "Title:  " . ($data['title'] ?? $existing['title']),
            "Status: " . ($data['status'] ?? $existing['status']),
            "URL:    " . ($article['url'] ?? ''),
        ];

        return self::textResult(implode("\n", $lines));
    }

    /**
     * Returns the user ID to attribute MCP-created/updated articles to.
     * Uses MCP_AUTHOR_ID env var if set; otherwise finds the first active Admin.
     */
    private static function resolveAuthorId(): int
    {
        $envId = (int) (getenv('MCP_AUTHOR_ID') ?: 0);
        if ($envId > 0) return $envId;

        $pdo  = Database::connection();
        $stmt = $pdo->prepare(
            "SELECT u.id FROM users u JOIN roles r ON r.id = u.role_id
             WHERE r.name = 'Admin' AND u.is_active = 1 ORDER BY u.id LIMIT 1"
        );
        $stmt->execute();
        $id = (int) $stmt->fetchColumn();

        if (!$id) throw new McpRpcException(-32603, 'No active admin user found. Set MCP_AUTHOR_ID env var.');
        return $id;
    }

    // ─── Token authentication ─────────────────────────────────────────────────

    /**
     * Validates Authorization: Bearer <token>.
     *
     * Checks in this order:
     *   1. MCP_API_TOKEN env var – simple string compare, no DB needed.
     *   2. api_tokens table – SHA-256 hash lookup (same as ApiController).
     *
     * Terminates with HTTP 401 if the token is missing or invalid.
     */
    private static function requireToken(): void
    {
        // Apache may deliver the header via $_SERVER or as an env var depending
        // on the host configuration — check both.
        $header = $_SERVER['HTTP_AUTHORIZATION']
               ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION']
               ?? getenv('HTTP_AUTHORIZATION')
               ?: '';

        $envToken = trim(getenv('MCP_API_TOKEN') ?: '');

        // Strip the "Bearer " prefix if present; accept a bare token too.
        if (str_starts_with($header, 'Bearer ')) {
            $rawToken = trim(substr($header, 7));
        } elseif ($header !== '') {
            $rawToken = trim($header); // bare token without prefix
        } else {
            http_response_code(401);
            self::output(self::makeError(null, -32600, 'Missing Authorization header. Expected: Bearer <token>.'));
        }

        // ── 1. Env-var token (no DB required) ────────────────────────────────
        if ($envToken !== '' && hash_equals($envToken, $rawToken)) {
            return;
        }

        // ── 2. Database token lookup ──────────────────────────────────────────
        $tokenHash = hash('sha256', $rawToken);
        $pdo       = Database::connection();

        $stmt = $pdo->prepare("SELECT id FROM api_tokens WHERE token_hash = ? AND is_active = 1 LIMIT 1");
        $stmt->execute([$tokenHash]);
        $tokenId = $stmt->fetchColumn();

        if (!$tokenId) {
            http_response_code(401);
            self::output(self::makeError(null, -32600, 'Invalid or inactive API token.'));
        }

        try {
            $pdo->prepare("UPDATE api_tokens SET last_used_at = CURRENT_TIMESTAMP WHERE id = ?")
                ->execute([$tokenId]);
        } catch (Throwable) {}
    }

    // ─── Helpers ─────────────────────────────────────────────────────────────

    private static function boot(): void
    {
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-store, no-cache, must-revalidate');
        header('X-Content-Type-Options: nosniff');
        header('X-Robots-Tag: noindex');

        $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

        // Preflight – allow CORS for server-side AI clients if needed
        if ($method === 'OPTIONS') {
            http_response_code(204);
            exit;
        }

        if ($method !== 'POST') {
            http_response_code(405);
            self::output(['error' => 'Method not allowed. The MCP endpoint only accepts POST.']);
        }
    }

    /** Encode $data as JSON and exit. @return never */
    private static function output(mixed $data): never
    {
        echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
        exit;
    }

    private static function makeError(mixed $id, int $code, string $message): array
    {
        return ['jsonrpc' => '2.0', 'id' => $id, 'error' => ['code' => $code, 'message' => $message]];
    }

    private static function textResult(string $text): array
    {
        return ['content' => [['type' => 'text', 'text' => $text]]];
    }
}

/** Lightweight exception carrying a JSON-RPC error code. */
final class McpRpcException extends RuntimeException {}
