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
                'Use search_knowledge_base to find relevant SOPs, error guides, and troubleshooting articles.',
                'Use get_article to read the full content of a specific article.',
                'Always cite the article title, type, and URL in your response.',
                'Only answer based on what is in the knowledge base — do not guess.',
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

        $article = Article::apiFind($id);

        if (!$article) {
            return [
                'content'  => [['type' => 'text', 'text' => "Article ID {$id} was not found or is not published."]],
                'isError'  => true,
            ];
        }

        $sep   = str_repeat('─', 60);
        $lines = [
            "[{$article['type']}] {$article['title']}",
            $sep,
        ];

        if ($article['system'])     $lines[] = "System:     {$article['system']}";
        if ($article['module'])     $lines[] = "Module:     {$article['module']}";
        if ($article['carrier'])    $lines[] = "Carrier:    {$article['carrier']}";
        if ($article['error_code']) $lines[] = "Error Code: {$article['error_code']}";
        if ($article['severity'])   $lines[] = "Severity:   {$article['severity']}";
        if ($article['tags'])       $lines[] = 'Tags:       ' . implode(', ', $article['tags']);

        $lines[] = "Updated:    {$article['updated_at']}";
        $lines[] = "URL:        {$article['url']}";
        $lines[] = '';
        $lines[] = $article['content'];

        return self::textResult(implode("\n", $lines));
    }

    // ─── Token authentication ─────────────────────────────────────────────────

    /**
     * Validates Authorization: Bearer <token>.
     * Shares the same api_tokens table as ApiController.
     * Terminates with HTTP 401 if the token is missing or invalid.
     */
    private static function requireToken(): void
    {
        $header = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
        if (!str_starts_with($header, 'Bearer ')) {
            http_response_code(401);
            self::output(self::makeError(null, -32600, 'Missing Authorization header. Expected: Bearer <token>.'));
        }

        $rawToken  = substr($header, 7);
        $tokenHash = hash('sha256', $rawToken);
        $pdo       = Database::connection();

        $stmt = $pdo->prepare("SELECT id FROM api_tokens WHERE token_hash = ? AND is_active = 1 LIMIT 1");
        $stmt->execute([$tokenHash]);
        $tokenId = $stmt->fetchColumn();

        if (!$tokenId) {
            http_response_code(401);
            self::output(self::makeError(null, -32600, 'Invalid or inactive API token.'));
        }

        // Non-critical: update last_used_at
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
