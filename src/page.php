<?php
/** Shared head and foot for the account and setup pages (one folder below the site root). */
declare(strict_types=1);

function mb_e($s): string
{
    return htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');
}

function mb_asset(string $path): string
{
    $file = __DIR__ . '/../assets/' . $path;
    return '../assets/' . $path . (is_file($file) ? '?v=' . substr(hash_file('sha256', $file), 0, 12) : '');
}

function mb_page_start(string $title): void
{
    header('Cache-Control: no-store');
    header('Referrer-Policy: same-origin');
    header('X-Frame-Options: DENY');
    ?><!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= mb_e($title) ?> | Mashpad</title>
<link rel="icon" href="<?= mb_e(mb_asset('smarterboard-mark.svg')) ?>" type="image/svg+xml">
<link rel="stylesheet" href="<?= mb_e(mb_asset('boards.css')) ?>">
<link rel="stylesheet" href="<?= mb_e(mb_asset('forms.css')) ?>">
<link rel="stylesheet" href="<?= mb_e(mb_asset('accounts.css')) ?>">
</head>
<body class="account-page">
<a class="skip-link" href="#main">Skip to content</a>
<div class="account-report">
  <header class="account-header">
    <a class="brand" href="../" aria-label="Mashpad home">Mashpad</a>
    <nav aria-label="Main navigation"><a href="../features/">How it works</a><a href="../pads/">All pads</a></nav>
  </header>
  <main class="form-page" id="main" tabindex="-1">
<?php
}

function mb_page_end(): void
{
    echo "</main>\n<footer class=\"account-footer\"><span>Human ideas. Machine help.</span><a href=\"../\">Mashpad home</a></footer>\n</div>\n</body>\n</html>\n";
}
