<?php

/**
 * ConfluenceSyncService
 *
 * Fetches pages from a Confluence space, converts them to knowledge_articles,
 * and optionally translates non-English content via Google Translate (free tier).
 *
 * Credentials are read from environment variables first, then fall back to the
 * hardcoded values used in the original CLI importer.
 */
final class ConfluenceSyncService
{
    // ── Confluence config ─────────────────────────────────────────────────────
    private string $base;
    private string $space;
    private string $user;
    private string $pass;

    // ── Pages whose titles indicate they are index/home pages (not real SOPs) ─
    private const SKIP_TITLES = [
        'WMS 3.0 SOPs Home',
        'WMS 3.0 End-to-End Flow Overview',
        'Standard Operating Procedure: IT WMS WISE 3.0',
    ];

    // ── Runtime counters ──────────────────────────────────────────────────────
    private int   $imported  = 0;
    private int   $skipped   = 0;
    private int   $updated   = 0;
    private array $errors    = [];
    private array $log       = [];   // per-page status lines for the UI

    public function __construct()
    {
        $this->base  = rtrim(getenv('CONFLUENCE_BASE')  ?: 'https://wiki.logisticsteam.com', '/');
        $this->space = getenv('CONFLUENCE_SPACE')        ?: 'WD';
        $this->user  = getenv('CONFLUENCE_USER')         ?: '';
        $this->pass  = getenv('CONFLUENCE_PASS')         ?: '';

        if ($this->user === '' || $this->pass === '') {
            throw new RuntimeException(
                'Confluence credentials are not configured. ' .
                'Set the CONFLUENCE_USER and CONFLUENCE_PASS environment variables.'
            );
        }
    }

    // ── Public entry point ────────────────────────────────────────────────────

    /**
     * Run the full sync and return a summary array.
     *
     * @param bool $translate  When true, attempt to translate non-English text to English.
     * @param int  $actorId    The admin user id performing the sync.
     */
    public function sync(bool $translate, int $actorId): array
    {
        $pdo     = Database::connection();
        $adminId = $this->resolveAdminId($pdo, $actorId);
        $wmsId   = Taxonomy::findOrCreate('systems', 'Warehouse Management System');

        $this->log[] = ['status' => 'info', 'message' => 'Checking connectivity to ' . $this->base . '…'];
        $this->testConnection();
        $this->log[] = ['status' => 'ok', 'message' => 'Connected to Confluence.'];

        $this->log[] = ['status' => 'info', 'message' => 'Fetching page list from Confluence space: ' . $this->space];
        $pages = $this->fetchAllPages();
        $this->log[] = ['status' => 'info', 'message' => 'Found ' . count($pages) . ' page(s).'];

        foreach ($pages as $page) {
            $rawTitle = $page['title'] ?? '';
            $pageId   = (string) ($page['id'] ?? '');

            // ── Skip index/home pages ─────────────────────────────────────
            if ($this->shouldSkip($rawTitle)) {
                $this->log[]   = ['status' => 'skip', 'message' => $rawTitle];
                $this->skipped++;
                continue;
            }

            try {
                $this->processPage($pdo, $pageId, $rawTitle, $wmsId, $adminId, $translate);
            } catch (Throwable $e) {
                $msg = "{$rawTitle}: " . $e->getMessage();
                $this->log[]  = ['status' => 'error', 'message' => $msg];
                $this->errors[] = $msg;
            }

            // Polite rate-limiting – avoid hammering the wiki
            usleep(150_000);
        }

        return [
            'imported' => $this->imported,
            'updated'  => $this->updated,
            'skipped'  => $this->skipped,
            'errors'   => $this->errors,
            'log'      => $this->log,
        ];
    }

    // ── Per-page processing ───────────────────────────────────────────────────

    private function processPage(
        PDO    $pdo,
        string $pageId,
        string $rawTitle,
        int    $wmsId,
        int    $adminId,
        bool   $translate
    ): void {
        $detail  = $this->cfGet("/rest/api/content/{$pageId}?expand=body.view,version");
        $html    = $detail['body']['view']['value'] ?? '';
        $title   = $this->cleanTitle($rawTitle);
        $fullTxt = $this->toText($html);

        // ── Extract structured fields ─────────────────────────────────────
        $description = $this->extractSection($html, 'Purpose', 'Overview', 'Description', 'Introduction', 'Background', 'Summary');
        $rootCause   = $this->extractSection($html, 'Prerequisites', 'Requirements', 'Pre-requisites', 'Before You Begin', 'Scope');
        $sopSteps    = $this->extractSteps($html);
        $validation  = $this->extractSection($html, 'Validation', 'Verification', 'Expected Result', 'Expected Outcome', 'Post-Steps', 'Result');
        $escalation  = $this->extractSection($html, 'Escalation', 'Troubleshooting', 'Notes', 'Important', 'Remarks');

        if (empty($description)) $description = mb_substr($fullTxt, 0, 1000);
        if (empty($sopSteps))    $sopSteps    = $fullTxt;

        // ── Optional translation ──────────────────────────────────────────
        if ($translate) {
            $title       = $this->translate($title);
            $description = $this->translate($description);
            $rootCause   = $rootCause   ? $this->translate($rootCause)   : null;
            $sopSteps    = $sopSteps    ? $this->translate($sopSteps)    : null;
            $validation  = $validation  ? $this->translate($validation)  : null;
            $escalation  = $escalation  ? $this->translate($escalation)  : null;
        }

        // ── Category + check for duplicates ───────────────────────────────
        $catName = $this->guessCategory($title, $fullTxt);
        $catId   = Taxonomy::findOrCreate('categories', $catName);

        $existingId = $this->findExistingByTitle($pdo, $title);

        $data = [
            'type'             => 'SOP',
            'title'            => $title,
            'system_id'        => $wmsId,
            'category_id'      => $catId,
            'description'      => $description,
            'root_cause'       => $rootCause ?: null,
            'sop_steps'        => $sopSteps  ?: null,
            'validation_steps' => $validation ?: null,
            'escalation_path'  => $escalation ?: null,
            'keywords'         => 'WMS 3.0 SOP warehouse ' . strtolower($catName),
            'status'           => $existingId ? null : 'Draft',   // keep existing status on update
            'owner_id'         => $adminId,
            'is_sample'        => 0,
            'tags'             => 'wms-3.0',
            'change_summary'   => $existingId
                ? 'Updated from Confluence WMS30SOP (sync)'
                : 'Imported from Confluence WMS30SOP (sync)',
        ];

        // Preserve status on update
        if ($existingId) {
            $row = $pdo->prepare('SELECT status FROM knowledge_articles WHERE id = ?');
            $row->execute([$existingId]);
            $data['status'] = $row->fetchColumn() ?: 'Draft';
        }

        $savedId = Article::save($data, $existingId, $adminId);

        if ($existingId) {
            $this->log[] = ['status' => 'update', 'message' => "{$title} → #" . $savedId . " [{$catName}] (updated)"];
            $this->updated++;
        } else {
            $this->log[] = ['status' => 'ok', 'message' => "{$title} → #" . $savedId . " [{$catName}] (new)"];
            $this->imported++;
        }
    }

    // ── Confluence API ────────────────────────────────────────────────────────

    /**
     * Quick connectivity check against the Confluence host.
     * Throws a descriptive RuntimeException if the host is unreachable or
     * the session cookies appear to be expired.
     */
    private function testConnection(): void
    {
        $testUrl = $this->base . '/rest/api/space/' . urlencode($this->space);
        $ch = curl_init($testUrl);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_TIMEOUT        => 15,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_USERPWD        => $this->user . ':' . $this->pass,
            CURLOPT_HTTPHEADER     => [
                'Accept: application/json',
                'User-Agent: Mozilla/5.0 SupportHub/1.0',
            ],
        ]);
        $body   = curl_exec($ch);
        $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err    = curl_error($ch);
        curl_close($ch);

        if ($err) {
            throw new RuntimeException(
                "Cannot reach Confluence at {$this->base} — {$err}. " .
                "Check that the server is accessible from this host and that CONFLUENCE_BASE is set correctly."
            );
        }

        if ($status === 401 || $status === 403) {
            throw new RuntimeException(
                "Confluence rejected the request with HTTP {$status}. " .
                "Check that CONFLUENCE_USER and CONFLUENCE_PASS are correct."
            );
        }

        if ($status !== 200) {
            throw new RuntimeException(
                "Confluence returned HTTP {$status} during connectivity check. " .
                "Verify CONFLUENCE_BASE ('{$this->base}') and CONFLUENCE_SPACE ('{$this->space}')."
            );
        }
    }

    private function cfGet(string $path): array
    {
        $ch = curl_init($this->base . $path);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_TIMEOUT        => 30,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_USERPWD        => $this->user . ':' . $this->pass,
            CURLOPT_HTTPHEADER     => [
                'Accept: application/json',
                'User-Agent: Mozilla/5.0 SupportHub/1.0',
            ],
        ]);
        $body   = curl_exec($ch);
        $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err    = curl_error($ch);
        curl_close($ch);

        if ($err)            throw new RuntimeException("cURL error: {$err}");
        if ($status !== 200) throw new RuntimeException("Confluence returned HTTP {$status} for {$path}");

        $data = json_decode($body, true);
        if (!is_array($data)) throw new RuntimeException("Invalid JSON from Confluence for {$path}");

        return $data;
    }

    private function fetchAllPages(): array
    {
        $all = [];
        $start = 0;
        do {
            $d   = $this->cfGet("/rest/api/content?spaceKey={$this->space}&type=page&limit=50&start={$start}&expand=title,ancestors");
            $res = $d['results'] ?? [];
            $all   = array_merge($all, $res);
            $start += 50;
        } while (count($res) === 50);

        return $all;
    }

    // ── Duplicate detection ───────────────────────────────────────────────────

    private function findExistingByTitle(PDO $pdo, string $title): ?int
    {
        $stmt = $pdo->prepare(
            'SELECT id FROM knowledge_articles WHERE title = ? AND deleted_at IS NULL LIMIT 1'
        );
        $stmt->execute([$title]);
        $id = $stmt->fetchColumn();
        return $id ? (int) $id : null;
    }

    // ── Content helpers ───────────────────────────────────────────────────────

    private function toText(string $html): string
    {
        $html = preg_replace('#<br\s*/?>|</p>|</li>|</h[1-6]>#i', "\n", $html);
        $html = preg_replace('#<li[^>]*>#i', '• ', $html);
        $html = preg_replace('#</?(ol|ul|table|tr|thead|tbody|div)[^>]*>#i', "\n", $html);
        $html = preg_replace('#<td[^>]*>|<th[^>]*>#i', "\t", $html);
        $html = strip_tags($html);
        $html = html_entity_decode($html, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        return trim(preg_replace('/\n{3,}/', "\n\n", $html));
    }

    private function extractSection(string $html, string ...$headings): string
    {
        foreach ($headings as $h) {
            if (preg_match(
                '#<h[1-6][^>]*>[^<]*' . preg_quote($h, '#') . '[^<]*</h[1-6]>(.*?)(?=<h[1-6]|$)#is',
                $html,
                $m
            )) {
                $t = $this->toText($m[1]);
                if (trim($t) !== '') return $t;
            }
        }
        return '';
    }

    private function extractSteps(string $html): string
    {
        $section = $html;
        if (preg_match(
            '#<h[1-6][^>]*>(?:Steps?|Procedure|Process|How to|Instructions?|SOP Steps?)[^<]*</h[1-6]>(.*?)(?=<h[1-6]|$)#is',
            $html,
            $m
        )) {
            $section = $m[1];
        }

        if (preg_match_all('#<li[^>]*>(.*?)</li>#is', $section, $matches)) {
            $steps = [];
            $n = 1;
            foreach ($matches[1] as $item) {
                $t = trim($this->toText($item));
                if ($t !== '') {
                    $steps[] = $n . '. ' . $t;
                    $n++;
                }
            }
            if ($steps) return implode("\n", $steps);
        }

        return $this->toText($html);
    }

    private function cleanTitle(string $title): string
    {
        $title = html_entity_decode($title, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $title = preg_replace('/\s*[\x{2013}\x{2014}\-]+\s*WMS\s*3\.0\s*$/u', '', $title);
        $title = preg_replace('/\s*WMS\s*3\.0\s*$/i', '', $title);
        return trim($title);
    }

    private function guessCategory(string $title, string $text): string
    {
        $t = strtolower($title . ' ' . $text);
        if (preg_match('/inbound|receive|receipt|putaway|check.?in|appointment|drop.?off|live delivery|rn creation|manual et/', $t)) return 'Inbound';
        if (preg_match('/outbound|pick|pack|load|shipment|parcel|dn creation|staging|dropship/', $t))                             return 'Outbound';
        if (preg_match('/inventory|cycle count|replenish|adjustment|transfer|physical inventory/', $t))                           return 'Inventory Control';
        if (preg_match('/report|velocity|shelf life|receiving report|empty location|fefo/', $t))                                  return 'Reports';
        if (preg_match('/config|foundation|contract|billing|rate sheet|settings|dictionary/', $t))                               return 'Configuration';
        return 'Operations';
    }

    private function shouldSkip(string $title): bool
    {
        foreach (self::SKIP_TITLES as $s) {
            if (stripos($title, $s) === 0) return true;
        }
        return false;
    }

    // ── Translation (Google Translate – no-key free endpoint) ────────────────

    /**
     * Translate $text to English using the free Google Translate web endpoint.
     * Returns the original text if the request fails or text is already English.
     *
     * NOTE: This uses the unofficial translate.googleapis.com endpoint which
     * requires no API key but is rate-limited. For production use, swap in
     * the official Cloud Translation API with a key stored in GOOGLE_TRANSLATE_KEY.
     */
    private function translate(string $text): string
    {
        if (trim($text) === '') return $text;

        // Detect language first – skip translation if already English
        if ($this->isEnglish($text)) return $text;

        // Google Translate free endpoint – chunks must be ≤ 5 000 chars
        $chunks  = $this->chunkText($text, 4800);
        $results = [];

        foreach ($chunks as $chunk) {
            $translated = $this->googleTranslate($chunk);
            $results[]  = $translated !== null ? $translated : $chunk;
        }

        return implode("\n", $results);
    }

    private function isEnglish(string $text): bool
    {
        // Cheap heuristic: if > 70 % of words are ASCII the text is likely Latin/English
        $words      = preg_split('/\s+/u', trim($text));
        $total      = count($words);
        if ($total === 0) return true;

        $asciiCount = 0;
        foreach ($words as $w) {
            if (mb_detect_encoding($w, 'ASCII', true) !== false) $asciiCount++;
        }
        return ($asciiCount / $total) > 0.70;
    }

    private function chunkText(string $text, int $maxBytes): array
    {
        if (mb_strlen($text) <= $maxBytes) return [$text];

        $chunks = [];
        $lines  = explode("\n", $text);
        $current = '';

        foreach ($lines as $line) {
            if (mb_strlen($current) + mb_strlen($line) + 1 > $maxBytes) {
                if ($current !== '') $chunks[] = $current;
                $current = $line;
            } else {
                $current .= ($current === '' ? '' : "\n") . $line;
            }
        }
        if ($current !== '') $chunks[] = $current;

        return $chunks ?: [$text];
    }

    /**
     * Call the free Google Translate endpoint.
     * Returns translated string or null on failure.
     */
    private function googleTranslate(string $text): ?string
    {
        // Check if an official API key is available
        $apiKey = getenv('GOOGLE_TRANSLATE_KEY');

        if ($apiKey) {
            return $this->googleTranslateOfficial($text, $apiKey);
        }

        // Fall back to unofficial free endpoint
        $url = 'https://translate.googleapis.com/translate_a/single?'
             . http_build_query([
                 'client' => 'gtx',
                 'sl'     => 'auto',
                 'tl'     => 'en',
                 'dt'     => 't',
                 'q'      => $text,
             ]);

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 20,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_HTTPHEADER     => ['User-Agent: Mozilla/5.0'],
        ]);
        $body   = curl_exec($ch);
        $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($status !== 200 || !$body) return null;

        $data = json_decode($body, true);
        if (!isset($data[0]) || !is_array($data[0])) return null;

        $translated = '';
        foreach ($data[0] as $segment) {
            if (isset($segment[0])) $translated .= $segment[0];
        }

        return $translated !== '' ? $translated : null;
    }

    private function googleTranslateOfficial(string $text, string $apiKey): ?string
    {
        $url = 'https://translation.googleapis.com/language/translate/v2?key=' . urlencode($apiKey);
        $payload = json_encode(['q' => $text, 'target' => 'en', 'format' => 'text']);

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $payload,
            CURLOPT_TIMEOUT        => 20,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_HTTPHEADER     => ['Content-Type: application/json'],
        ]);
        $body   = curl_exec($ch);
        $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($status !== 200 || !$body) return null;

        $data = json_decode($body, true);
        return $data['data']['translations'][0]['translatedText'] ?? null;
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    private function resolveAdminId(PDO $pdo, int $actorId): int
    {
        // Use the logged-in user's id if it resolves to a valid DB row,
        // otherwise fall back to the first admin in the users table.
        $stmt = $pdo->prepare('SELECT id FROM users WHERE id = ? AND is_active = 1 LIMIT 1');
        $stmt->execute([$actorId]);
        if ($stmt->fetchColumn()) return $actorId;

        return (int) ($pdo->query("SELECT id FROM users WHERE role_id = 1 LIMIT 1")->fetchColumn() ?: 1);
    }
}
