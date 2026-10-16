<?php
declare(strict_types=1);

namespace AccessNewswire\AccesswireUi\Model;

use Magento\Checkout\Model\Session as CheckoutSession;

/**
 * Where to send the customer back to in accesswire-ui once they've placed
 * their order. Held in the checkout session, which (unlike the quote) is
 * still there on the success page.
 */
class ReturnDestination
{
    public const DEFAULT_TEXT = 'Back to dashboard';

    private const MAX_TEXT_LENGTH = 50;
    private const SESSION_URL = 'accesswire_return_url';
    private const SESSION_TEXT = 'accesswire_return_text';

    public function __construct(
        private readonly CheckoutSession $checkoutSession,
        private readonly AllowedOrigins $allowedOrigins
    ) {
    }

    /**
     * Replaces any previous destination - an invalid or missing URL clears
     * it, so a stale one is never used.
     */
    public function set(string $url, string $text): void
    {
        $url = $this->allowedOrigins->normalizeUrl($url);
        $text = mb_substr(trim((string) preg_replace('/[\x00-\x1f\x7f]+/u', ' ', $text)), 0, self::MAX_TEXT_LENGTH);

        $this->checkoutSession->setData(self::SESSION_URL, $url);
        $this->checkoutSession->setData(self::SESSION_TEXT, $url !== null && $text !== '' ? $text : null);
    }

    /**
     * The URL with `empty_cart=1` added, telling accesswire-ui to drop its
     * now-ordered cart.
     */
    public function getUrlAfterOrder(): ?string
    {
        $url = $this->checkoutSession->getData(self::SESSION_URL);
        if (!$url) {
            return null;
        }

        [$url, $fragment] = array_pad(explode('#', $url, 2), 2, null);
        return $url
            . (str_contains($url, '?') ? '&' : '?') . 'empty_cart=1'
            . ($fragment !== null ? '#' . $fragment : '');
    }

    public function getText(): string
    {
        return $this->checkoutSession->getData(self::SESSION_TEXT) ?: (string) __(self::DEFAULT_TEXT);
    }
}
