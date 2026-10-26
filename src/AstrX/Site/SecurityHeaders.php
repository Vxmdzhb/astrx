<?php
declare(strict_types=1);

namespace AstrX\Site;

use AstrX\Config\Config;

/**
 * The baseline security headers every HTML/JSON response this site produces
 * carries (CSP, Referrer-Policy, nosniff, X-Frame-Options, Onion-Location).
 */
final class SecurityHeaders
{
    public function __construct(private readonly Config $config) {}

    /**
     * Send the baseline security headers.
     *
     * ContentManager calls this once at the start of its response phase;
     * PanicLockdown and ErrorResponder call it for the responses they produce
     * themselves. A branch that needs a different value replaces that one header.
     *
     * The site runs with JavaScript off, so a strict Content-Security-Policy
     * (default-src 'none') costs nothing and neutralises injected script or
     * frames. Operators can relax it per deployment through
     * ContentManager.content_security_policy; the strict default applies when
     * that key is empty.
     *
     * Does nothing if headers were already sent (a controller streamed bytes).
     */
    public function emit(SiteFlags $flags): void
    {
        if (headers_sent()) {
            return;
        }

        $defaultCsp = "default-src 'none'; img-src 'self' data:; style-src 'self' 'unsafe-inline'; "
                    . "frame-src 'self'; form-action 'self'; base-uri 'none'; frame-ancestors 'none'";

        $csp = $this->config->getConfigString(
            'ContentManager',
            'content_security_policy',
            $defaultCsp,
        );
        if ($csp === '') {
            $csp = $defaultCsp;
        }

        header('Content-Security-Policy: ' . $csp);
        header('Referrer-Policy: no-referrer');
        header('X-Content-Type-Options: nosniff');
        header('X-Frame-Options: DENY');
        // Advertise the canonical .onion on the PRIMARY (no-JS) HTML
        // response. Tor Browser reads Onion-Location from the ordinary page, and
        // AstrX's audience is no-JS — so this path (not only the JS-fragment copy)
        // is where the anti-phishing auto-upgrade must be emitted. Validated to a
        // real .onion URL in SiteFlags::onionPrimary(); '' (unset/invalid) emits nothing.
        $onion = $flags->onionPrimary();
        if ($onion !== '') { header('Onion-Location: ' . $onion); }
    }
}
