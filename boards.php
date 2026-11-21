<?php
declare(strict_types=1);
header('Cache-Control: no-store');
?><!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<meta name="theme-color" content="#ffffff">
<title>Mashpad | Pads</title>
<link rel="icon" href="assets/smarterboard-mark.svg" type="image/svg+xml">
<link rel="preload" href="assets/fonts/ibm-plex-serif-regular.woff2" as="font" type="font/woff2" crossorigin>
<link rel="stylesheet" href="assets/boards.css?v=<?= substr(hash_file('sha256', __DIR__ . '/assets/boards.css'), 0, 12) ?>">
<script src="assets/vendor/markdown-it.min.js?v=<?= substr(hash_file('sha256', __DIR__ . '/assets/vendor/markdown-it.min.js'), 0, 12) ?>" defer></script>
<script src="assets/board-markdown.js?v=<?= substr(hash_file('sha256', __DIR__ . '/assets/board-markdown.js'), 0, 12) ?>" defer></script>
<script src="assets/boards.js?v=<?= substr(hash_file('sha256', __DIR__ . '/assets/boards.js'), 0, 12) ?>" defer></script>
</head>
<body>
<a class="skip-link" href="#main">Skip to content</a>
<div class="shell">
  <aside>
    <a class="brand" href="boards.php" data-nav="" aria-label="Mashpad pads">Mashpad</a>
    <h2 class="side-title">Pads</h2>
    <nav id="board-nav" aria-label="Pads"></nav>
  </aside>
  <div class="column-rules" aria-hidden="true"></div>
  <main id="main" tabindex="-1"></main>
</div>
</body>
</html>
