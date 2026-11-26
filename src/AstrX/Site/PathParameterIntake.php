<?php
declare(strict_types=1);

namespace AstrX\Site;

use AstrX\Config\Config;
use AstrX\Http\Request;
use AstrX\Http\RequestMethod;
use AstrX\Kernel\ReadsPathSegments;
use AstrX\Routing\CurrentUrl;
use AstrX\Routing\PathParameters;

/**
 * Page parameters written in the path (/en/admin-users/edit/5): read them
 * into the request's query bag before the controller runs, and send a request
 * that still carries them as a query string (a GET form, an old bookmark) to
 * the path form once.
 *
 * Rewrite mode only, and never for a controller that reads its path as
 * positions (ReadsPathSegments): that page's segments are not pairs, and its
 * parameters stay in the query string.
 */
final class PathParameterIntake
{
    public function __construct(private readonly Config $config) {}

    /**
     * @param class-string|null $controllerClass the page's controller, if any
     * @return string|null where to redirect the request to, or null to go on
     */
    public function apply(Request $request, CurrentUrl $current, ?string $controllerClass, string $requestUri): ?string
    {
        if (!$this->config->getConfigBool('Routing', 'url_rewrite', true)) {
            return null;
        }
        if ($controllerClass !== null && is_subclass_of($controllerClass, ReadsPathSegments::class)) {
            return null;
        }
        $reserved = $this->reserved();

        $redirect = $this->canonicalUrl($request, $current, $requestUri, $reserved);
        if ($redirect !== null) {
            return $redirect;
        }

        // A name given both ways keeps its query-string value.
        foreach (PathParameters::parse($current->tail(), $reserved) as $name => $value) {
            if (!$request->query()->has($name)) {
                $request->query()->set($name, $value);
            }
        }
        return null;
    }

    /**
     * The same URL with its query parameters moved into the path, or null when
     * there is nothing to move. Only plain GET page views move: an API call, a
     * form submission's settling GET (its one-time token is read before the
     * page) and anything that is not a GET stay as they are. Empty values are
     * dropped (an empty search box, an unset filter), as they mean "not given".
     *
     * @param list<string> $reserved
     */
    private function canonicalUrl(Request $request, CurrentUrl $current, string $requestUri, array $reserved): ?string
    {
        $method = $request->method();
        if (($method !== RequestMethod::Get && $method !== RequestMethod::Head) || $request->isApi()) {
            return null;
        }
        $queryString = (string) parse_url($requestUri, PHP_URL_QUERY);
        if ($queryString === '') {
            return null;
        }
        parse_str($queryString, $query);
        foreach (PathParameters::QUERY_ONLY as $token) {
            if (isset($query[$token])) {
                return null;
            }
        }
        $query = array_filter($query, static fn (mixed $v): bool => $v !== '');
        [$pairs, $rest] = PathParameters::split($query, $reserved);
        if ($pairs === []) {
            return null;
        }
        $path = rtrim((string) parse_url($requestUri, PHP_URL_PATH), '/');
        // Pairs follow the page. A URL that names no page (the site root, or
        // just the locale) has nowhere to put them: leave it alone.
        $pageToken = $current->get($reserved[2], '');
        $segments  = explode('/', $path);
        if (!is_string($pageToken) || $pageToken === '' || !in_array($pageToken, array_map('rawurldecode', $segments), true)) {
            return null;
        }
        // The pairs the path already has, with the new values replacing old
        // ones: a GET form submitted again from its own result page does not
        // pile up /q/a/q/b.
        $tail   = $current->tail();
        $merged = PathParameters::parse($tail, $reserved);
        for ($i = 0, $n = count($pairs); $i + 1 < $n; $i += 2) {
            unset($merged[$pairs[$i]]);
            $merged[$pairs[$i]] = $pairs[$i + 1];
        }
        $head = implode('/', array_slice($segments, 0, count($segments) - count($tail)));
        foreach ($merged as $name => $value) {
            $head .= '/' . rawurlencode($name) . '/' . rawurlencode($value);
        }
        return $rest !== [] ? $head . '?' . http_build_query($rest) : $head;
    }

    /** @return list<string> the router's own query names */
    private function reserved(): array
    {
        return [
            $this->config->getConfigString('Routing', 'locale_key', 'lang'),
            $this->config->getConfigString('Routing', 'session_key', 'sid'),
            $this->config->getConfigString('Routing', 'page_key', 'page'),
        ];
    }
}
