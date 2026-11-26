<?php
declare(strict_types=1);

namespace AstrX\Site;

/**
 * Keeps the diagnostics of a request that ends in a redirect, for the page
 * the browser lands on next.
 *
 * Forms are processed on one request (the PRG settling GET) and their outcome
 * is shown on the next, after a redirect. Diagnostics only live in the
 * request's collector, so everything a controller reported before
 * Response::redirect() used to vanish: "Profile updated but password change
 * failed — see diagnostics", and nothing to see. At shutdown, when the
 * response is a redirect, the rendered messages (already filtered by level
 * and by what this user may see) go into the session; the next page's message
 * bar shows them after the flash messages.
 */
final class RedirectDiagnostics
{
    private const string SESSION_KEY = '_astrx_redirect_messages';

    /** Enough for any one form; a redirect loop cannot grow the session. */
    private const int LIMIT = 50;

    /** Keep $ctx's diagnostics if this request ends in a redirect. */
    public static function keepOnRedirect(DefaultTemplateContext $ctx): void
    {
        register_shutdown_function(static function () use ($ctx): void {
            if (session_status() !== PHP_SESSION_ACTIVE || !self::redirecting()) {
                return;
            }
            self::keep($ctx->diagnosticMessages());
        });
    }

    /**
     * @param list<array{message:string,level:string,level_label:string,css_class:string}> $messages
     */
    public static function keep(array $messages): void
    {
        if ($messages === []) {
            return;
        }
        $kept = self::stored();
        foreach ($messages as $message) {
            if (!in_array($message, $kept, true)) {
                $kept[] = $message;
            }
        }
        $_SESSION[self::SESSION_KEY] = array_slice($kept, -self::LIMIT);
    }

    /**
     * The messages kept by the previous request, removed from the session.
     *
     * @return list<array{message:string,level:string,level_label:string,css_class:string}>
     */
    public static function pull(): array
    {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            return [];
        }
        $kept = self::stored();
        unset($_SESSION[self::SESSION_KEY]);
        return $kept;
    }

    /** @return list<array{message:string,level:string,level_label:string,css_class:string}> */
    private static function stored(): array
    {
        $raw = $_SESSION[self::SESSION_KEY] ?? [];
        if (!is_array($raw)) {
            return [];
        }
        $out = [];
        foreach ($raw as $entry) {
            if (!is_array($entry)) {
                continue;
            }
            $message = $entry['message'] ?? null;
            $level = $entry['level'] ?? null;
            $label = $entry['level_label'] ?? null;
            $class = $entry['css_class'] ?? null;
            if (is_string($message) && is_string($level) && is_string($label) && is_string($class)) {
                $out[] = ['message' => $message, 'level' => $level, 'level_label' => $label, 'css_class' => $class];
            }
        }
        return $out;
    }

    private static function redirecting(): bool
    {
        $status = http_response_code();
        if (!is_int($status) || $status < 300 || $status > 399) {
            return false;
        }
        foreach (headers_list() as $header) {
            if (stripos($header, 'location:') === 0) {
                return true;
            }
        }
        return false;
    }
}
