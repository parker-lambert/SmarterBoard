<?php
/**
 * Shared head and foot for the account and pad-management pages.
 * Every link is built from the site root (mb_base_path()), so a page works at any
 * depth: signin/ is one folder down, manage/<owner>/<pad> is three.
 */
declare(strict_types=1);

require_once __DIR__ . '/auth.php';

function mb_e($s): string
{
    return htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');
}

function mb_asset(string $path): string
{
    $file = __DIR__ . '/../assets/' . $path;
    return mb_base_path() . 'assets/' . $path . (is_file($file) ? '?v=' . substr(hash_file('sha256', $file), 0, 12) : '');
}

/** Whether the visitor is signed in. A page with no database shows the signed-out header. */
function mb_public_signed_in(): bool
{
    try {
        return mb_db() && mb_current_user() !== null;
    } catch (Throwable $t) {
        return false;
    }
}

/**
 * The header every public page shares (home, How it works, Pricing, Sign in, Create an account, Terms, Privacy and the
 * 404): the logo, then the same links in the same order whichever page you are on. Signed out: How it works, Pricing, Sign in
 * and Create an account. Signed in: How it works, Pricing and Your pads. $current marks the page you are on. On the Sign in and
 * Create an account pages the other of the two is a quiet link, so the form's own button stays the strongest thing there.
 * Narrow screens fold the links into a labelled Menu button (assets/public.js); without scripts they simply wrap.
 */
function mb_public_header(string $current = '', ?bool $signedIn = null, string $class = ''): string
{
    $root     = mb_e(mb_base_path());
    $signedIn = $signedIn ?? mb_public_signed_in();
    $here     = fn (string $key) => $current === $key ? ' aria-current="page"' : '';
    $quiet    = in_array($current, ['signin', 'register'], true);
    $html  = '<header class="site-header' . ($class !== '' ? ' ' . mb_e($class) : '') . '">'
           . '<a class="brand" href="' . $root . '" aria-label="Mashpad home">Mashpad</a>'
           . '<button type="button" class="menu-button" aria-expanded="false" aria-controls="site-nav" hidden>Menu</button>'
           . '<nav class="site-nav" id="site-nav" aria-label="Main navigation">'
           . '<a href="' . $root . 'features/"' . $here('features') . '>How it works</a>'
           . '<a href="' . $root . 'pricing/"' . $here('pricing') . '>Pricing</a>';
    if ($signedIn) {
        $html .= '<a href="' . $root . 'pads/"' . $here('pads') . '>Your pads</a>';
    } else {
        $html .= '<a href="' . $root . 'signin/"' . $here('signin') . '>Sign in</a>'
               . '<a href="' . $root . 'register/"' . $here('register') . ($quiet ? '' : ' class="nav-cta"') . '>Create an account</a>';
    }
    return $html . '</nav></header>';
}

/** The links every footer carries, in the same order everywhere. */
function mb_footer_links(string $root): string
{
    return mb_legal_links($root) . '<a href="' . mb_e($root) . '">Mashpad home</a>';
}

/** The footer every public page shares. */
function mb_public_footer(): string
{
    return '<footer class="site-footer"><span>Human ideas. Machine help.</span><span class="footer-links">' . mb_footer_links(mb_base_path()) . '</span></footer>';
}

/** The stylesheet and script the public header and footer need. */
function mb_public_assets(): string
{
    return '<link rel="stylesheet" href="' . mb_e(mb_asset('public.css')) . '">' . "\n" . '<script src="' . mb_e(mb_asset('public.js')) . '" defer></script>' . "\n";
}

/**
 * $variant: '' the signed-in account pages with the Mashpad sidebar; 'manage' and 'admin' the same with their own links;
 * 'public' the shared public header and footer instead (then $padContext may say 'current', the page's key in the header,
 * and 'layout', one of 'form' (a centered form column), 'prose' (centered reading width), 'wide' (the whole container)).
 */
function mb_page_start(string $title, string $variant = '', ?array $padContext = null): void
{
    $GLOBALS['mb_page_public'] = $variant === 'public';
    header('Cache-Control: no-store');
    header('Referrer-Policy: same-origin');
    header('X-Frame-Options: DENY');
    $root = mb_e(mb_base_path());
    ?><!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= mb_e($title) ?> | Mashpad</title>
<?php if ($variant === 'public' && !empty($padContext['description']) && isset($padContext['path'])): ?>
<meta name="description" content="<?= mb_e($padContext['description']) ?>">
<?= mb_seo_tags($title . ' | Mashpad', $padContext['description'], $padContext['path']) ?>
<?= mb_analytics_head() ?>
<?php endif; ?>
<link rel="icon" href="<?= mb_e(mb_asset('smarterboard-mark.svg')) ?>" type="image/svg+xml">
<link rel="stylesheet" href="<?= mb_e(mb_asset('boards.css')) ?>">
<link rel="stylesheet" href="<?= mb_e(mb_asset('forms.css')) ?>">
<link rel="stylesheet" href="<?= mb_e(mb_asset('accounts.css')) ?>">
<?php if ($variant === 'public') { echo mb_public_assets(); } ?>
<?php if ($variant === 'manage' || ($variant === 'admin' && ($padContext['section'] ?? '') === 'public')): ?><link rel="stylesheet" href="<?= mb_e(mb_asset('manage.css')) ?>"><script src="<?= mb_e(mb_asset('confirm.js')) ?>" defer></script><script src="<?= mb_e(mb_asset('manage.js')) ?>" defer></script><?php endif; ?>
</head>
<body class="account-page<?= $variant === 'public' ? ' public-page' : '' ?><?= ($padContext['current'] ?? '') === 'pricing' ? ' pricing-page' : '' ?>">
<?php if ($variant === 'public' && !empty($padContext['description']) && isset($padContext['path'])) { echo mb_analytics_body(); } ?>
<a class="skip-link" href="#main">Skip to content</a>
<?php if ($variant === 'public'): ?>
<div class="public-shell">
  <?= mb_public_header((string) ($padContext['current'] ?? '')) ?>
  <main class="form-page public-main layout-<?= mb_e($padContext['layout'] ?? 'wide') ?>" id="main" tabindex="-1">
<?php else: ?>
<div class="account-report<?= $variant === 'manage' ? ' manage-report' : '' ?>">
  <?php if (true): ?>
  <aside class="account-sidebar<?= in_array($variant, ['manage', 'admin'], true) ? ' manage-sidebar' : '' ?>" aria-label="Mashpad navigation">
    <a class="brand" href="<?= $root ?>" aria-label="Mashpad home">Mashpad</a>
    <?php if ($variant === 'manage' && $padContext): ?>
    <p class="side-title">Your pad</p>
    <a class="manage-pad-name" href="<?= mb_e($padContext['url']) ?>"><?= mb_e($padContext['name']) ?></a>
    <nav class="pad-pages" aria-label="Pad pages">
      <a href="<?= mb_e($padContext['url']) ?>">Conversation</a>
      <a href="<?= mb_e($padContext['url']) ?>?view=settings">Pad info</a>
      <a href="<?= mb_e($padContext['manage_url']) ?>" aria-current="page">Manage access</a>
    </nav>
    <?php endif; ?>
    <?php if ($variant === 'admin'): ?>
    <p class="side-title">Admin</p>
    <nav class="pad-pages" aria-label="Admin sections">
      <a href="<?= $root ?>admin/"<?= ($padContext['section'] ?? '') === 'accounts' ? ' aria-current="page"' : '' ?>>Accounts</a>
      <a href="<?= $root ?>admin/plans/"<?= ($padContext['section'] ?? '') === 'plans' ? ' aria-current="page"' : '' ?>>Plans</a>
      <a href="<?= $root ?>admin/public/"<?= ($padContext['section'] ?? '') === 'public' ? ' aria-current="page"' : '' ?>>Public pads</a>
      <a href="<?= $root ?>admin/cron/"<?= ($padContext['section'] ?? '') === 'cron' ? ' aria-current="page"' : '' ?>>Cron</a>
      <a href="<?= $root ?>admin/email/"<?= ($padContext['section'] ?? '') === 'email' ? ' aria-current="page"' : '' ?>>Email</a>
    </nav>
    <?php else: ?>
    <nav class="site-pages" aria-label="Main navigation">
      <a href="<?= $root ?>pads/">Your pads</a>
      <a href="<?= $root ?>public-pads/">Your Public Pads</a>
      <a href="<?= $root ?>features/">How it works</a>
      <a href="<?= $root ?>pricing/">Pricing</a>
    </nav>
    <?php endif; ?>
  </aside>
  <?php endif; ?>
  <main class="form-page<?= $variant === 'manage' ? ' management-page' : '' ?>" id="main" tabindex="-1">
<?php endif; ?>
<?php
}

/** Who runs Mashpad. Used by the Terms, the Privacy Policy and the structured data, so they can't disagree. */
const MB_OPERATOR = [
    'name'    => 'Parker-Lambert',
    'street'  => '922 Chartres St, Unit B',
    'city'    => 'New Orleans',
    'region'  => 'LA',
    'country' => 'US',
    'email'   => 'hello@parker-lambert.com',
    'law'     => 'Louisiana',
];
const MB_LEGAL_UPDATED = '28 November 2026';
/** The address people write to about Mashpad itself (the Terms and Privacy keep the operator's own, above). */
const MB_SUPPORT_EMAIL = 'hello@mashpad.ai';
/** Other properties of the same people, listed as the organisation's sameAs. */
const MB_SAME_AS = ['https://parker-lambert.com', 'https://centraldesk.io'];
/** The picture shown when a page is shared: the home page's colleague and two robots, cut to 1200 x 630. */
const MB_SHARE_IMAGE = ['path' => 'assets/og-share.jpg', 'width' => 1200, 'height' => 630,
                        'alt' => 'A colleague and two friendly robots comparing notes at a shared planning display.'];

/** The use cases the page describes, in order: anchor and heading. The structured data lists the same ones; a test keeps them in step. */
const MB_FEATURE_USE_CASES = [
    ['uc-software', 'Build software together (GitHub)'], ['uc-lunch', 'Arrange the meeting, and the lunch after it (Google Calendar, OpenTable)'],
    ['uc-zoom', 'Plan the meeting (Google Calendar, Zoom)'], ['uc-dinner', 'Plan the dinner party (Instacart, Google Calendar, Gmail)'],
    ['uc-playlist', 'Build the road-trip playlist (Spotify)'], ['uc-report', 'File the weekly report (Google Drive, Gmail, Google Calendar)'],
    ['uc-time', 'Watch project time (Timey Wimey, Toggl Track, Clockify)'], ['uc-brand', 'Look after brand assets (InspireStudio, Bynder, Frontify, Cloudinary)'],
    ['uc-tools', 'Bring in work from other tools (Canva)'], ['uc-sign', 'Send agreements out for signature (DocuSign)'],
];

/** The Google Tag Manager container that loads Google Analytics on the public pages. */
const MB_GTM_ID = 'GTM-N8SK2JMJ';

/**
 * Whether to measure this visit. Only on the real site (not on a developer's machine or an address that is a bare IP), and never for
 * a browser that sends Global Privacy Control or Do Not Track. Called only for the public pages that are indexed (home, how it works,
 * pricing, terms, privacy): never the app, sign-in, registration, password reset or an invitation, which handle credentials.
 */
function mb_analytics_on(?array $server = null): bool
{
    $server ??= $_SERVER;
    $host = strtolower(preg_replace('/:\d+$/', '', (string) ($server['HTTP_HOST'] ?? '')));
    if ($host === '' || $host === 'localhost' || str_ends_with($host, '.local') || str_ends_with($host, '.localhost') || filter_var(trim($host, '[]'), FILTER_VALIDATE_IP) !== false) {
        return false;
    }
    return ($server['HTTP_SEC_GPC'] ?? '') !== '1' && ($server['HTTP_DNT'] ?? '') !== '1';
}

/** Google Tag Manager's standard loader, for the end of a public page's <head>. Nothing when the visit isn't measured. */
function mb_analytics_head(?array $server = null): string
{
    if (!mb_analytics_on($server)) {
        return '';
    }
    return "<!-- Google Tag Manager -->\n<script>(function(w,d,s,l,i){w[l]=w[l]||[];w[l].push({'gtm.start':new Date().getTime(),event:'gtm.js'});var f=d.getElementsByTagName(s)[0],j=d.createElement(s),dl=l!='dataLayer'?'&l='+l:'';j.async=true;j.src='https://www.googletagmanager.com/gtm.js?id='+i+dl;f.parentNode.insertBefore(j,f);})(window,document,'script','dataLayer','" . MB_GTM_ID . "');</script>\n<!-- End Google Tag Manager -->\n";
}

/** The no-script half of the tag, for straight after <body> opens. */
function mb_analytics_body(?array $server = null): string
{
    if (!mb_analytics_on($server)) {
        return '';
    }
    return '<!-- Google Tag Manager (noscript) --><noscript><iframe src="https://www.googletagmanager.com/ns.html?id=' . MB_GTM_ID . '" height="0" width="0" style="display:none;visibility:hidden"></iframe></noscript><!-- End Google Tag Manager (noscript) -->' . "\n";
}

/** The canonical address and the Open Graph and Twitter card tags for a public page. $path is the page's path from the site's root. */
function mb_seo_tags(string $title, string $description, string $path): string
{
    $url   = mb_site_url() . ltrim($path, '/');
    $file  = __DIR__ . '/../' . MB_SHARE_IMAGE['path'];
    $image = mb_site_url() . MB_SHARE_IMAGE['path'] . (is_file($file) ? '?v=' . substr(hash_file('sha256', $file), 0, 10) : '');
    $e     = fn (string $s) => htmlspecialchars($s, ENT_QUOTES);
    $meta  = [
        ['property', 'og:type', 'website'], ['property', 'og:site_name', 'Mashpad'], ['property', 'og:title', $title],
        ['property', 'og:description', $description], ['property', 'og:url', $url], ['property', 'og:image', $image],
        ['property', 'og:image:type', 'image/jpeg'], ['property', 'og:image:width', (string) MB_SHARE_IMAGE['width']],
        ['property', 'og:image:height', (string) MB_SHARE_IMAGE['height']], ['property', 'og:image:alt', MB_SHARE_IMAGE['alt']],
        ['name', 'twitter:card', 'summary_large_image'], ['name', 'twitter:title', $title], ['name', 'twitter:description', $description],
        ['name', 'twitter:image', $image], ['name', 'twitter:image:alt', MB_SHARE_IMAGE['alt']],
    ];
    $out = '<link rel="canonical" href="' . $e($url) . '">' . PHP_EOL;
    foreach ($meta as [$attr, $name, $value]) {
        $out .= '<meta ' . $attr . '="' . $name . '" content="' . $e($value) . '">' . PHP_EOL;
    }
    return $out;
}

/** A JSON-LD block. $data is plain PHP; nothing in it is ever treated as markup. */
function mb_jsonld(array $data): string
{
    return '<script type="application/ld+json">' . json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP) . "</script>\n";
}

/** The organisation behind Mashpad, as schema.org data. */
function mb_jsonld_org(): array
{
    return ['@type' => 'Organization', '@id' => mb_site_url() . '#org', 'name' => 'Mashpad', 'url' => mb_site_url(),
            'logo' => mb_site_url() . 'assets/smarterboard-mark.svg', 'email' => MB_SUPPORT_EMAIL, 'sameAs' => MB_SAME_AS,
            'parentOrganization' => ['@type' => 'Organization', 'name' => MB_OPERATOR['name'],
                'address' => ['@type' => 'PostalAddress', 'streetAddress' => MB_OPERATOR['street'], 'addressLocality' => MB_OPERATOR['city'],
                              'addressRegion' => MB_OPERATOR['region'], 'addressCountry' => MB_OPERATOR['country']]]];
}

/** The Terms and Privacy links every page's footer carries. $root is the path back to the site root. */
function mb_legal_links(string $root): string
{
    return '<a href="' . mb_e($root) . 'terms/">Terms</a><a href="' . mb_e($root) . 'privacy/">Privacy</a>';
}

function mb_page_end(): void
{
    $root = mb_base_path();
    if (!empty($GLOBALS['mb_page_public'])) {
        echo "</main>\n" . mb_public_footer() . "\n</div>\n</body>\n</html>\n";
        return;
    }
    echo "</main>\n<footer class=\"account-footer\"><span>Human ideas. Machine help.</span><span class=\"footer-links\">" . mb_legal_links($root)
       . '<a href="' . mb_e($root) . "\">Mashpad home</a></span></footer>\n</div>\n</body>\n</html>\n";
}
