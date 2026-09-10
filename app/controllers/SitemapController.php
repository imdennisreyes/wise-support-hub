<?php

declare(strict_types=1);

/**
 * Generates a standards-compliant XML sitemap for all published articles.
 *
 * Endpoint: GET /sitemap.xml
 *
 * The sitemap is served with correct Content-Type and a short cache lifetime.
 * Only published, non-deleted articles are included.
 */
final class SitemapController
{
    public static function generate(): void
    {
        $rows = Article::sitemapRows();

        $base = rtrim(getenv('APP_URL') ?: '', '/');
        if (!$base) {
            $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
            $base   = $scheme . '://' . ($_SERVER['HTTP_HOST'] ?? 'localhost');
        }

        header('Content-Type: application/xml; charset=utf-8');
        // Cache for 1 hour on the client; proxy/CDN can cache for 4 hours
        header('Cache-Control: public, max-age=3600, s-maxage=14400');
        header('X-Robots-Tag: noindex'); // the sitemap itself doesn't need to be indexed

        echo '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
        echo '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">' . "\n";

        // Include the homepage
        echo self::urlEntry($base . '/', null);

        foreach ($rows as $row) {
            $url      = $base . '/articles/' . $row['id'];
            $lastmod  = self::toW3cDate($row['updated_at']);
            echo self::urlEntry($url, $lastmod);
        }

        echo '</urlset>' . "\n";
        exit;
    }

    // ─── Helpers ─────────────────────────────────────────────────────────────

    private static function urlEntry(string $loc, ?string $lastmod): string
    {
        $entry  = "  <url>\n";
        $entry .= '    <loc>' . htmlspecialchars($loc, ENT_XML1, 'UTF-8') . "</loc>\n";
        if ($lastmod !== null) {
            $entry .= '    <lastmod>' . $lastmod . "</lastmod>\n";
        }
        $entry .= "  </url>\n";
        return $entry;
    }

    /**
     * Converts a MySQL/SQLite CURRENT_TIMESTAMP string to W3C date (YYYY-MM-DD).
     * Returns null if the value cannot be parsed.
     */
    private static function toW3cDate(?string $datetime): ?string
    {
        if (!$datetime) return null;
        $ts = strtotime($datetime);
        return $ts !== false ? date('Y-m-d', $ts) : null;
    }
}
