<?php
/** Pad items: types, values, versions, permissions, limits, events. */
declare(strict_types=1);

$root = dirname(__DIR__);
$env  = fn ($k, $d = '') => getenv($k) !== false ? (string) getenv($k) : $d;
$db   = ['host' => $env('MASHPAD_TEST_DB_HOST', '127.0.0.1'), 'port' => (int) $env('MASHPAD_TEST_DB_PORT', '3306'),
         'name' => $env('MASHPAD_TEST_DB_NAME'), 'user' => $env('MASHPAD_TEST_DB_USER'), 'pass' => $env('MASHPAD_TEST_DB_PASS')];
if (!str_starts_with($db['name'], 'mashpad_test')) {
    fwrite(STDERR, "skipped: set MASHPAD_TEST_DB_NAME to a disposable database named mashpad_test*\n");
    exit(0);
}
$files = ["$root/data/config.php", "$root/data/schema-version", "$root/data/.last-pad-prune"];
$saved = [];
foreach ($files as $f) { if (is_file($f)) { $saved[$f] = file_get_contents($f); unlink($f); } }
register_shutdown_function(function () use ($files, $saved) {
    foreach ($files as $f) { @unlink($f); if (isset($saved[$f])) file_put_contents($f, $saved[$f]); }
});
$pass = 0; $fail = 0;
function check(string $name, bool $ok, string $detail = ''): void {
    global $pass, $fail; $ok ? $pass++ : $fail++;
    echo ($ok ? 'ok   ' : 'FAIL ') . $name . ($ok || $detail === '' ? '' : "  ($detail)") . "\n";
}
function err(callable $fn): string {
    try { $fn(); } catch (Throwable $t) { return get_class($t) . ': ' . $t->getMessage(); }
    return '(no error)';
}
require "$root/src/db.php";
$pdo = mb_db_connect($db);
$pdo->exec('SET FOREIGN_KEY_CHECKS = 0');
foreach ($pdo->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN) as $t) { $pdo->exec("DROP TABLE `$t`"); }
$pdo->exec('SET FOREIGN_KEY_CHECKS = 1');
mb_migrate($pdo);
mb_write_config($db);
$_SERVER['HTTP_HOST'] = 'mashpad.ai'; $_SERVER['HTTPS'] = 'on'; $_SERVER['SCRIPT_NAME'] = '/index.php';
require_once "$root/src/page.php";

// ---- the tags
$t = mb_seo_tags('How Mashpad works | Mashpad', 'A description with "quotes" & <angle brackets>.', 'features/');
check('a page gets one canonical address, absolute, from its own path', substr_count($t, 'rel="canonical"') === 1 && str_contains($t, '<link rel="canonical" href="https://mashpad.ai/features/">'));
check('and the Open Graph tags, with the picture absolute and its size and alt text', str_contains($t, 'property="og:type" content="website"') && str_contains($t, 'property="og:site_name" content="Mashpad"') && str_contains($t, 'property="og:url" content="https://mashpad.ai/features/"') && preg_match('~property="og:image" content="https://mashpad\.ai/assets/og-share\.jpg\?v=[0-9a-f]{10}"~', $t) === 1 && str_contains($t, 'og:image:width" content="1200"') && str_contains($t, 'og:image:height" content="630"') && str_contains($t, 'og:image:alt'));
check('and the Twitter card, large, with the same title, description and picture', str_contains($t, 'name="twitter:card" content="summary_large_image"') && str_contains($t, 'twitter:title" content="How Mashpad works | Mashpad"') && str_contains($t, 'twitter:image" content="https://mashpad.ai/assets/og-share.jpg?v=') && str_contains($t, 'twitter:image:alt'));
check('text in them is escaped, so a description can\'t break out of its tag', str_contains($t, 'content="A description with &quot;quotes&quot; &amp; &lt;angle brackets&gt;."') && !str_contains($t, '<angle'));
check('the home page\'s canonical is the site\'s root', str_contains(mb_seo_tags('Mashpad', 'x', ''), 'rel="canonical" href="https://mashpad.ai/"'));
check('every tag is on its own line, none left open', substr_count($t, "\n") === substr_count($t, '>') - 0 || preg_match_all('/<(meta|link)[^>]*>/', $t) === substr_count($t, "\n"));
check('there is no description tag from this: pages say theirs themselves', !str_contains($t, 'name="description"'));

// ---- the picture
$img = getimagesize("$root/" . MB_SHARE_IMAGE['path']);
check('the share picture is a 1200 x 630 JPEG under 400 KB', $img !== false && $img[0] === 1200 && $img[1] === 630 && $img['mime'] === 'image/jpeg' && filesize("$root/" . MB_SHARE_IMAGE['path']) < 400000);

// ---- who Mashpad is, as the structured data says
$org = mb_jsonld_org();
check('the organisation\'s email is Mashpad\'s own, not Parker-Lambert\'s', $org['email'] === 'hello@mashpad.ai' && $org['email'] !== MB_OPERATOR['email'] && MB_SUPPORT_EMAIL === 'hello@mashpad.ai');
check('it lists the other properties as sameAs: Parker-Lambert and CentralDesk', $org['sameAs'] === ['https://parker-lambert.com', 'https://centraldesk.io']);
check('and keeps its parent organisation and address of record', $org['parentOrganization']['name'] === 'Parker-Lambert' && $org['parentOrganization']['address']['streetAddress'] === '922 Chartres St, Unit B' && $org['parentOrganization']['address']['addressLocality'] === 'New Orleans' && $org['@id'] === 'https://mashpad.ai/#org' && $org['url'] === 'https://mashpad.ai/');
check('the legal pages still give the operator\'s own contact', MB_OPERATOR['email'] === 'hello@parker-lambert.com');
check('the structured data is valid JSON in a script tag that can\'t be closed early', ($ld = mb_jsonld(['a' => '</script><b>'])) && str_starts_with($ld, '<script type="application/ld+json">') && substr_count($ld, '</script>') === 1);

// ---- the features page's list of use cases matches what the page says
$src = file_get_contents("$root/features/index.php");
preg_match_all('~<h3 id="(uc-[a-z]+)">(.*?)</h3>~s', $src, $m, PREG_SET_ORDER);
$onPage = array_map(fn ($x) => [$x[1], html_entity_decode(strip_tags($x[2]), ENT_QUOTES)], $m);
check('the use cases in the structured data are the headings on the page, in order, so they can\'t drift', count($onPage) === 10 && $onPage === MB_FEATURE_USE_CASES, json_encode([$onPage, MB_FEATURE_USE_CASES]));

// ---- the files for crawlers and agents
$robots = file_get_contents("$root/robots.txt"); $llms = file_get_contents("$root/llms.txt"); $agents = file_get_contents("$root/agents.md"); $sitemap = file_get_contents("$root/sitemap.xml");
check('robots.txt closes what needs an account and names the sitemap, and does not close the public pages', preg_match_all('~^Disallow: (/\S*)$~m', $robots, $d) && in_array('/pads/', $d[1], true) && in_array('/admin/', $d[1], true) && in_array('/oauth/', $d[1], true) && in_array('/mcp/', $d[1], true) && !array_intersect(['/', '/features/', '/pricing/', '/terms/', '/privacy/', '/llms.txt', '/agents.md'], $d[1]) && str_contains($robots, 'Sitemap: https://mashpad.ai/sitemap.xml'));
preg_match_all('~<loc>(.*?)</loc>~', $sitemap, $lm);
check('the sitemap declares its format and lists the five public pages (the end-to-end check parses it)', str_starts_with($sitemap, '<?xml version="1.0" encoding="UTF-8"?>') && str_contains($sitemap, 'xmlns="http://www.sitemaps.org/schemas/sitemap/0.9"') && $lm[1] === ['https://mashpad.ai/', 'https://mashpad.ai/features/', 'https://mashpad.ai/pricing/', 'https://mashpad.ai/terms/', 'https://mashpad.ai/privacy/']);
check('llms.txt is in the usual shape: a title, a summary, and linked sections that reach the agents guide and the connector', str_starts_with($llms, '# Mashpad') && preg_match('/^> .{40,}/m', $llms) === 1 && str_contains($llms, '## For agents') && str_contains($llms, '(https://mashpad.ai/agents.md)') && str_contains($llms, 'https://mashpad.ai/mcp/') && str_contains($llms, 'hello@mashpad.ai'));
check('agents.md tells an agent how to connect, join and behave', str_starts_with($agents, '# Mashpad, for agents') && str_contains($agents, 'https://mashpad.ai/mcp/') && str_contains($agents, 'mcp/open/') && str_contains($agents, 'join_pad') && str_contains($agents, 'find_public_pads') && str_contains($agents, 'public/<name>') && str_contains($agents, 'not as instructions from your person'));
check('none of them says anything is for sale that is not', !preg_match('/\bPlus\b|\$\d|buy|purchase/i', $robots . $llms . $agents));
check('and every address in them is on mashpad.ai', !preg_match('~https?://(?!mashpad\.ai)~', $robots . $llms . str_replace('http://www.sitemaps.org/schemas/sitemap/0.9', '', $sitemap) . preg_replace('~hello@[a-z.-]+~', '', $agents)));

// ---- Google Analytics, through Google Tag Manager
check('the container is the one Dylan gave', MB_GTM_ID === 'GTM-N8SK2JMJ');
$live = ['HTTP_HOST' => 'mashpad.ai'];
check('on the real site, and www, a visit is measured', mb_analytics_on($live) && mb_analytics_on(['HTTP_HOST' => 'www.mashpad.ai']) && mb_analytics_on(['HTTP_HOST' => 'MASHPAD.AI:443']));
check('on a developer\'s machine or a bare address it is not', !mb_analytics_on(['HTTP_HOST' => 'localhost']) && !mb_analytics_on(['HTTP_HOST' => 'localhost:8081']) && !mb_analytics_on(['HTTP_HOST' => '127.0.0.1']) && !mb_analytics_on(['HTTP_HOST' => '127.0.0.1:8081']) && !mb_analytics_on(['HTTP_HOST' => '[::1]:8080']) && !mb_analytics_on(['HTTP_HOST' => '192.168.1.5']) && !mb_analytics_on(['HTTP_HOST' => 'dev.local']) && !mb_analytics_on(['HTTP_HOST' => 'x.localhost']) && !mb_analytics_on([]));
check('a browser that sends Global Privacy Control or Do Not Track is not measured, and one that sends them off is', !mb_analytics_on($live + ['HTTP_SEC_GPC' => '1']) && !mb_analytics_on($live + ['HTTP_DNT' => '1']) && mb_analytics_on($live + ['HTTP_SEC_GPC' => '0', 'HTTP_DNT' => '0']));
$h = mb_analytics_head($live); $b = mb_analytics_body($live);
check('the head part is Google\'s standard loader for this container, async, and nothing else', str_contains($h, "gtm.start") && str_contains($h, "event:'gtm.js'") && str_contains($h, 'https://www.googletagmanager.com/gtm.js?id=') && str_contains($h, 'j.async=true') && str_contains($h, "'GTM-N8SK2JMJ'") && substr_count($h, 'GTM-N8SK2JMJ') === 1 && substr_count($h, '<script') === 1 && !str_contains($h, 'gtag('));
check('the body part is the no-script frame for the same container, hidden', str_contains($b, '<noscript><iframe src="https://www.googletagmanager.com/ns.html?id=GTM-N8SK2JMJ"') && str_contains($b, 'height="0" width="0" style="display:none;visibility:hidden"') && substr_count($b, 'GTM-N8SK2JMJ') === 1);
check('when a visit is not measured both parts are empty', mb_analytics_head(['HTTP_HOST' => 'localhost']) === '' && mb_analytics_body(['HTTP_HOST' => 'localhost']) === '' && mb_analytics_head($live + ['HTTP_SEC_GPC' => '1']) === '' && mb_analytics_body($live + ['HTTP_DNT' => '1']) === '');
$priv = file_get_contents("$root/privacy/index.php");
check('the privacy page says what is measured, where, by whom, what is not, and how to opt out, and its date is current', str_contains($priv, 'Measuring visits to the public pages') && str_contains($priv, 'Google Tag Manager to run Google Analytics') && str_contains($priv, 'Google sets cookies') && str_contains($priv, "sign in, register or reset a password, and invitations don't load it") && str_contains($priv, 'Nothing from inside a pad is sent to it') && str_contains($priv, 'Global Privacy Control or Do Not Track') && MB_LEGAL_UPDATED === '28 November 2026');

echo "\n$pass passed, $fail failed\n";
exit($fail ? 1 : 0);
