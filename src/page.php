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

function mb_page_start(string $title, string $variant = '', ?array $padContext = null): void
{
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
<link rel="icon" href="<?= mb_e(mb_asset('smarterboard-mark.svg')) ?>" type="image/svg+xml">
<link rel="stylesheet" href="<?= mb_e(mb_asset('boards.css')) ?>">
<link rel="stylesheet" href="<?= mb_e(mb_asset('forms.css')) ?>">
<link rel="stylesheet" href="<?= mb_e(mb_asset('accounts.css')) ?>">
<?php if ($variant === 'manage'): ?><link rel="stylesheet" href="<?= mb_e(mb_asset('manage.css')) ?>"><script src="<?= mb_e(mb_asset('manage.js')) ?>" defer></script><?php endif; ?>
</head>
<body class="account-page<?= $variant === 'pricing' ? ' pricing-page' : '' ?>">
<a class="skip-link" href="#main">Skip to content</a>
<div class="account-report<?= $variant === 'manage' ? ' manage-report' : '' ?>">
  <?php if ($variant === 'manage'): ?>
  <aside class="manage-sidebar" aria-label="Current pad">
    <p class="side-title">Your pad</p>
    <?php if ($padContext): ?>
    <a class="manage-pad-name" href="<?= mb_e($padContext['url']) ?>"><?= mb_e($padContext['name']) ?></a>
    <p class="manage-pad-access">Private pad</p>
    <nav aria-label="Pad pages">
      <a href="<?= mb_e($padContext['url']) ?>">Conversation</a>
      <a href="<?= mb_e($padContext['url']) ?>?view=settings">Pad info</a>
      <a href="<?= mb_e($padContext['manage_url']) ?>" aria-current="page"><?= $padContext['is_owner'] ? 'Manage settings' : 'Members' ?></a>
    </nav>
    <?php endif; ?>
    <a class="manage-all-pads" href="<?= $root ?>pads/">All pads</a>
  </aside>
  <?php endif; ?>
  <header class="account-header">
    <a class="brand" href="<?= $root ?>" aria-label="Mashpad home">Mashpad</a>
    <nav aria-label="Main navigation"><a href="<?= $root ?>features/">How it works</a><a href="<?= $root ?>pricing/">Pricing</a><a href="<?= $root ?>pads/">All pads</a></nav>
  </header>
  <main class="form-page<?= $variant === 'manage' ? ' management-page' : '' ?>" id="main" tabindex="-1">
<?php
}

function mb_page_end(): void
{
    echo "</main>\n<footer class=\"account-footer\"><span>Human ideas. Machine help.</span><a href=\"" . mb_e(mb_base_path()) . "\">Mashpad home</a></footer>\n</div>\n</body>\n</html>\n";
}
