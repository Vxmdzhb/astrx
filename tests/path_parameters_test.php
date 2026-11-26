<?php
declare(strict_types=1);

/**
 * Page parameters as path segments: /en/admin-users/edit/5.
 *
 * Routing\PathParameters (which values can be segments), UrlGenerator
 * (writing them, both URL modes), and Site\PathParameterIntake (reading them
 * back, and redirecting a query string to the path form).
 *
 * Standalone: no bootstrap, no database.
 */

use AstrX\Config\Config;
use AstrX\Http\FileBag;
use AstrX\Http\HeaderBag;
use AstrX\Http\ParameterBag;
use AstrX\Http\Request;
use AstrX\Http\RequestMethod;
use AstrX\Http\Uri;
use AstrX\Result\DiagnosticsCollector;
use AstrX\Routing\CurrentUrl;
use AstrX\Routing\PathParameters;
use AstrX\Routing\UrlGenerator;
use AstrX\Site\Controller\MainController;
use AstrX\Site\PathParameterIntake;

require dirname(__DIR__) . '/src/autoload.php';

$PASS = 0;
$FAIL = 0;
function check(string $label, bool $ok, string $detail = ''): void
{
    global $PASS, $FAIL;
    if ($ok) {
        $PASS++;
        echo "  ok   - {$label}\n";
    } else {
        $FAIL++;
        echo "  FAIL - {$label}" . ($detail !== '' ? "\n         {$detail}" : '') . "\n";
    }
}

function config(bool $rewrite): Config
{
    $file = tempnam(sys_get_temp_dir(), 'astrx_routing_') . '.php';
    file_put_contents($file, '<?php return ' . var_export(['Routing' => ['url_rewrite' => $rewrite]], true) . ';');
    $config = new Config(new DiagnosticsCollector(), $file);
    unlink($file);
    return $config;
}

function urls(bool $rewrite): UrlGenerator
{
    $current = new CurrentUrl();
    $current->set('lang', 'en');
    return new UrlGenerator(config($rewrite), $current);
}

/** @param array<string, string> $query */
function request(array $query = [], RequestMethod $method = RequestMethod::Get): Request
{
    return new Request(
        $method,
        new Uri('http', 'localhost', null, '/', '', ''),
        new HeaderBag([]),
        new ParameterBag($query),
        new ParameterBag([]),
        new ParameterBag([]),
        new ParameterBag([]),
        new ParameterBag([]),
        new FileBag([]),
    );
}

/** @param list<string> $tail */
function here(string $page, array $tail): CurrentUrl
{
    $current = new CurrentUrl();
    $current->set('lang', 'en');
    $current->set('page', $page);
    $current->setTail($tail);
    return $current;
}

echo "1. Which values become segments\n";
[$pairs, $rest] = PathParameters::split([
    'edit' => 5, 'q' => 'two words', 'flag' => true, 'off' => false,
    'path' => 'a/b', 'empty' => '', 'dot' => '.env', 'php' => 'x.php', 'lang' => 'en', '_prg' => 'abc', 'bad-name' => 'x',
], ['lang']);
check('ids, text, booleans become pairs', $pairs === ['edit', '5', 'q', 'two words', 'flag', '1', 'off', '0'], json_encode($pairs) ?: '');
check('"/", empty, ".x", "*.php", router names, tokens and odd names stay in the query',
    array_keys($rest) === ['path', 'empty', 'dot', 'php', 'lang', '_prg', 'bad-name'], json_encode(array_keys($rest)) ?: '');
check('parse reads pairs, later ones win, skips odd tails and reserved names',
    PathParameters::parse(['edit', '5', 'edit', '6', 'lang', 'it', '3', 'x', 'q'], ['lang']) === ['edit' => '6']);

echo "\n2. Writing URLs\n";
$rw = urls(true);
$qm = urls(false);
check('rewrite: toPage puts parameters in the path',
    $rw->toPage('admin-users', ['edit' => 5]) === '/en/admin-users/edit/5', $rw->toPage('admin-users', ['edit' => 5]));
check('rewrite: values are encoded, unsafe ones stay in the query',
    $rw->toPage('webmail', ['folder' => 'Sent Items', 'sub' => 'A/B']) === '/en/webmail/folder/Sent%20Items?sub=A%2FB',
    $rw->toPage('webmail', ['folder' => 'Sent Items', 'sub' => 'A/B']));
check('query mode: unchanged',
    $qm->toPage('admin-users', ['edit' => 5]) === 'index.php?lang=en&page=admin-users&edit=5', $qm->toPage('admin-users', ['edit' => 5]));
check('withParams adds pairs before an existing query string',
    $rw->withParams('/en/webmail?sub=x', ['uid' => 7]) === '/en/webmail/uid/7?sub=x');
check('withParams in query mode appends to the query',
    $qm->withParams('index.php?lang=en&page=webmail', ['uid' => 7]) === 'index.php?lang=en&page=webmail&uid=7');
check('paramPrefix ends where a template appends the value',
    $rw->paramPrefix('/en/admin-pages', 'edit') === '/en/admin-pages/edit/'
    && $qm->paramPrefix('index.php?lang=en&page=admin-pages', 'edit') === 'index.php?lang=en&page=admin-pages&edit=');
check('toPagePath keeps a positional page\'s parameters in the query',
    $rw->toPagePath('board', [], ['style' => 'futaba']) === '/en/board?style=futaba'
    && $rw->toPagePath('board', ['b', 'thread', '4'], ['quote' => 9]) === '/en/board/b/thread/4?quote=9');
check('…unless the controller reads pairs after its positions',
    $rw->toPagePath('board', ['b', 'thread', '4'], ['quote' => 9], pathParams: true) === '/en/board/b/thread/4/quote/9');

echo "\n3. Reading them back\n";
$intake = new PathParameterIntake(config(true));
$req = request(['lang' => 'en', 'page' => 'admin-users']);
$redirect = $intake->apply($req, here('admin-users', ['edit', '5', 'pn', '2']), null, '/en/admin-users/edit/5/pn/2');
check('pairs become query parameters', $redirect === null && $req->query()->get('edit') === '5' && $req->query()->get('pn') === '2');

$req = request(['lang' => 'en', 'page' => 'x', 'edit' => '9']);
$intake->apply($req, here('x', ['edit', '5']), null, '/en/x/edit/5?edit=9&_prg=t');
check('a real query parameter wins over the same pair', $req->query()->get('edit') === '9');

$req = request(['lang' => 'en', 'page' => 'main']);
$intake->apply($req, here('main', ['edit', '5']), MainController::class, '/en/main/edit/5');
check('a positional controller\'s segments are left alone', !$req->query()->has('edit'));

echo "\n4. Redirecting a query string to the path\n";
check('a GET form result moves into the path, empties dropped',
    $intake->apply(request(), here('search', []), null, '/en/search?source=site&q=hello&type=') === '/en/search/source/site/q/hello');
check('a value that cannot be a segment stays in the query',
    $intake->apply(request(), here('search', []), null, '/en/search?q=a/b&source=site') === '/en/search/source/site?q=a%2Fb');
check('submitting again replaces the pair instead of repeating it',
    $intake->apply(request(), here('admin-comments', ['flagged', '1']), null, '/en/admin-comments/flagged/1?flagged=0')
    === '/en/admin-comments/flagged/0');
check('a form submission\'s settling GET is not redirected',
    $intake->apply(request(), here('x', []), null, '/en/x?_prg=abc&edit=1') === null);
check('a POST is not redirected',
    $intake->apply(request([], RequestMethod::Post), here('x', []), null, '/en/x?edit=1') === null);
check('a positional page is not redirected',
    $intake->apply(request(), here('main', []), MainController::class, '/en/main?show=10') === null);
check('a URL without a page is not redirected',
    $intake->apply(request(), here('WORDING_MAIN', []), null, '/en/?q=x') === null);
check('query mode never redirects',
    (new PathParameterIntake(config(false)))->apply(request(), here('x', []), null, '/index.php?page=x&edit=1') === null);

echo "\n" . str_repeat('=', 40) . "\n{$PASS} passed, {$FAIL} failed\n";
exit($FAIL === 0 ? 0 : 1);
