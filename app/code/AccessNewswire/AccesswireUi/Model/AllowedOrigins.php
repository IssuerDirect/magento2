<?php
declare(strict_types=1);

namespace AccessNewswire\AccesswireUi\Model;

/**
 * The origins accesswire-ui is served from (configured in etc/di.xml).
 */
class AllowedOrigins
{
    /**
     * @param string[] $origins
     */
    public function __construct(
        private readonly array $origins = []
    ) {
    }

    public function isAllowed(string $origin): bool
    {
        return in_array($origin, $this->origins, true);
    }

    /**
     * Returns $url rebuilt from its parsed parts if it points at an allowed
     * origin, otherwise null.
     *
     * The rebuild matters: PHP's parse_url and browsers disagree on some
     * malformed URLs (e.g. `https://evil.example\@app.accessnewswire.com`,
     * which a browser sends to evil.example), so the URL that's validated is
     * the URL that's used - and anything ambiguous is rejected outright.
     */
    public function normalizeUrl(string $url): ?string
    {
        if (preg_match('/[\\\\\s\x00-\x1f\x7f]/', $url)) {
            return null;
        }

        $parts = parse_url($url);
        if ($parts === false
            || !in_array($parts['scheme'] ?? '', ['http', 'https'], true)
            || !isset($parts['host'])
            || isset($parts['user'])
            || isset($parts['pass'])
        ) {
            return null;
        }

        $origin = $parts['scheme'] . '://' . $parts['host'] . (isset($parts['port']) ? ':' . $parts['port'] : '');
        if (!$this->isAllowed($origin)) {
            return null;
        }

        return $origin
            . ($parts['path'] ?? '/')
            . (isset($parts['query']) ? '?' . $parts['query'] : '')
            . (isset($parts['fragment']) ? '#' . $parts['fragment'] : '');
    }
}
