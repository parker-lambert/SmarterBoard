<?php
/**
 * Message Board — installer.
 * Put this file in /message-board/, open it in a browser, drop message-board.zip on it.
 * Extracts the package into this folder, creates data/ and data/boards/, and checks they're writable.
 * Safe to re-run for updates: existing pads in data/boards/ are never touched.
 */
declare(strict_types=1);

const REQUIRED_FILES = ['index.php', 'api.php', 'lib.php', 'mcp/index.php'];
const SKIP_PATTERNS  = ['#^__MACOSX/#', '#(^|/)\.DS_Store$#', '#^install\.php$#', '#^data/boards/#'];

$root = __DIR__;

// ---------------------------------------------------------------------------
// Environment checks (shown on the page and re-run before installing)
// ---------------------------------------------------------------------------
function ini_bytes(string $v): int
{
    $v = trim($v);
    $n = (int) $v;
    switch (strtolower(substr($v, -1))) {
        case 'g': $n *= 1024;
        case 'm': $n *= 1024;
        case 'k': $n *= 1024;
    }
    return $n;
}

function checks(string $root): array
{
    $uploadMax = min(ini_bytes((string) ini_get('upload_max_filesize')), ini_bytes((string) ini_get('post_max_size')));
    return [
        ['PHP 8.0 or newer', version_compare(PHP_VERSION, '8.0.0', '>='), 'Running PHP ' . PHP_VERSION],
        ['Can read .zip files', class_exists('ZipArchive') || class_exists('PharData'), class_exists('ZipArchive') ? 'ZipArchive available' : (class_exists('PharData') ? 'Using PharData (ZipArchive not installed)' : 'Neither ZipArchive nor PharData is available')],
        ['This folder is writable', is_writable($root), $root],
        ['Uploads are enabled', (bool) ini_get('file_uploads'), 'Largest upload: ' . round($uploadMax / 1048576, 1) . ' MB'],
    ];
}

// ---------------------------------------------------------------------------
// Package reading: ZipArchive, with a PharData fallback
// ---------------------------------------------------------------------------

/** @return array<string, callable():string>  relative path => content loader */
function read_package(string $file): array
{
    $entries = [];
    if (class_exists('ZipArchive')) {
        $zip = new ZipArchive();
        if ($zip->open($file) !== true) {
            throw new RuntimeException('That file isn’t a readable .zip archive.');
        }
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $name = (string) $zip->getNameIndex($i);
            if (substr($name, -1) === '/') {
                continue;
            }
            $entries[$name] = function () use ($zip, $i): string {
                $data = $zip->getFromIndex($i);
                if ($data === false) {
                    throw new RuntimeException('Could not read an entry from the archive.');
                }
                return $data;
            };
        }
        return $entries;
    }

    $tmp = $file . '.zip';
    copy($file, $tmp);
    $phar   = new PharData($tmp);
    $prefix = 'phar://' . $tmp . '/';
    foreach (new RecursiveIteratorIterator($phar) as $f) {
        $path = (string) $f->getPathname();
        $name = str_starts_with($path, $prefix) ? substr($path, strlen($prefix)) : basename($path);
        $entries[$name] = fn (): string => (string) file_get_contents($path);
    }
    return $entries;
}

/** Strip a single wrapping folder (e.g. "message-board/") if the app files live inside it. */
function strip_wrapper(array $entries): array
{
    if (isset($entries['index.php'])) {
        return $entries;
    }
    $tops = [];
    foreach (array_keys($entries) as $name) {
        if (preg_match('#^__MACOSX/#', $name)) {
            continue;
        }
        $tops[explode('/', $name)[0]] = true;
    }
    if (count($tops) !== 1) {
        return $entries;
    }
    $wrap = array_key_first($tops) . '/';
    $out  = [];
    foreach ($entries as $name => $loader) {
        if (str_starts_with($name, $wrap)) {
            $out[substr($name, strlen($wrap))] = $loader;
        }
    }
    return $out;
}

function safe_relative(string $name): ?string
{
    $name = str_replace('\\', '/', $name);
    if ($name === '' || $name[0] === '/' || preg_match('#(^|/)\.\.(/|$)#', $name) || preg_match('#^[a-zA-Z]:#', $name)) {
        return null;
    }
    return $name;
}

function install(string $root, string $upload): array
{
    $log = [];
    foreach (checks($root) as [$label, $ok, $detail]) {
        if (!$ok) {
            throw new RuntimeException("$label: $detail");
        }
    }

    $entries = strip_wrapper(read_package($upload));
    $missing = array_values(array_filter(REQUIRED_FILES, fn ($f) => !isset($entries[$f])));
    if ($missing) {
        throw new RuntimeException('This doesn’t look like the Mashpad package. Missing: ' . implode(', ', $missing));
    }

    // Validate every path before writing anything.
    $plan = [];
    foreach ($entries as $name => $loader) {
        foreach (SKIP_PATTERNS as $re) {
            if (preg_match($re, $name)) {
                continue 2;
            }
        }
        $rel = safe_relative($name);
        if ($rel === null) {
            throw new RuntimeException('The archive contains an unsafe path: ' . $name);
        }
        $plan[$rel] = $loader;
    }

    $added = $updated = 0;
    foreach ($plan as $rel => $loader) {
        $target = $root . '/' . $rel;
        $dir    = dirname($target);
        if (!is_dir($dir) && !mkdir($dir, 0755, true) && !is_dir($dir)) {
            throw new RuntimeException('Could not create folder ' . substr($dir, strlen($root) + 1));
        }
        $exists = is_file($target);
        if (file_put_contents($target, $loader(), LOCK_EX) === false) {
            throw new RuntimeException('Could not write ' . $rel);
        }
        @chmod($target, 0644);
        $exists ? $updated++ : $added++;
        $log[] = ($exists ? 'Updated ' : 'Added ') . $rel;
    }

    // Storage folders.
    foreach (['data', 'data/boards'] as $d) {
        $path = $root . '/' . $d;
        if (is_dir($path)) {
            $log[] = "Kept existing $d/";
        } elseif (mkdir($path, 0775, true)) {
            $log[] = "Created $d/";
        } else {
            throw new RuntimeException("Could not create $d/");
        }
    }
    $ht = $root . '/data/.htaccess';
    if (!is_file($ht)) {
        file_put_contents($ht, "# Board storage is only read through PHP.\nRequire all denied\n");
        $log[] = 'Created data/.htaccess';
    }

    // Prove the storage folder is writable by PHP.
    $probe = $root . '/data/boards/.write-test';
    if (@file_put_contents($probe, 'ok') === false) {
        throw new RuntimeException('Files were installed, but data/boards/ isn’t writable by PHP. Set it to 775 (or 777) in your FTP client.');
    }
    @unlink($probe);
    $log[] = 'Checked data/boards/ is writable';

    $boards = count(glob($root . '/data/boards/*.json') ?: []);
    if (function_exists('opcache_reset')) {
        @opcache_reset();
    }

    return [
        'summary' => "$added added, $updated updated. " . ($boards ? "$boards existing pad" . ($boards === 1 ? '' : 's') . ' kept.' : 'Ready for the first pad.'),
        'log'     => $log,
    ];
}

// ---------------------------------------------------------------------------
// Request handling
// ---------------------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json');
    header('Cache-Control: no-store');
    try {
        $action = $_GET['action'] ?? '';
        if ($action === 'delete-self') {
            echo json_encode(['ok' => @unlink(__FILE__)]);
            exit;
        }
        if (empty($_FILES['package'])) {
            throw new RuntimeException('No file arrived. It may be larger than the server’s upload limit.');
        }
        $f = $_FILES['package'];
        if ($f['error'] !== UPLOAD_ERR_OK) {
            $why = [
                UPLOAD_ERR_INI_SIZE   => 'The file is larger than the server’s upload limit.',
                UPLOAD_ERR_FORM_SIZE  => 'The file is too large.',
                UPLOAD_ERR_PARTIAL    => 'The upload was interrupted. Try again.',
                UPLOAD_ERR_NO_FILE    => 'No file was selected.',
                UPLOAD_ERR_NO_TMP_DIR => 'The server has no temporary folder for uploads.',
                UPLOAD_ERR_CANT_WRITE => 'The server couldn’t save the upload.',
            ];
            throw new RuntimeException($why[$f['error']] ?? 'Upload failed (code ' . $f['error'] . ').');
        }
        if (!preg_match('/\.zip$/i', (string) $f['name'])) {
            throw new RuntimeException('Drop the .zip file, not ' . basename((string) $f['name']) . '.');
        }
        echo json_encode(['ok' => true] + install($root, $f['tmp_name']), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    } catch (Throwable $e) {
        http_response_code(422);
        echo json_encode(['ok' => false, 'error' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
    }
    exit;
}

$checks    = checks($root);
$allOk     = !in_array(false, array_column($checks, 1), true);
$installed = is_file($root . '/lib.php') && is_file($root . '/mcp/index.php');
header('Cache-Control: no-store');
?><!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Install Mashpad</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Atkinson+Hyperlegible:wght@400;700&family=JetBrains+Mono:wght@400&display=swap" rel="stylesheet">
<style>
:root {
  color-scheme: light;
  --bg: #e9edf2; --surface: #ffffff; --ink: #18202c; --muted: #5a6577;
  --rule: #d3dae3; --accent: #2c55c9; --ok: #1f7a4d; --bad: #c0392b;
  --font: "Atkinson Hyperlegible", system-ui, -apple-system, "Segoe UI", sans-serif;
  --mono: "JetBrains Mono", ui-monospace, Menlo, Consolas, monospace;
}
@media (prefers-color-scheme: dark) {
  :root { color-scheme: dark; --bg: #11151b; --surface: #181e27; --ink: #e3e8ef; --muted: #8e99aa;
          --rule: #283141; --accent: #84a5ff; --ok: #5fd39a; --bad: #ff8a7a; }
}
* { box-sizing: border-box; }
body { margin: 0; background: var(--bg); color: var(--ink); font: 16px/1.5 var(--font); }
main { max-width: 640px; margin: 0 auto; padding: 48px 20px 64px; }
h1 { font-size: 2rem; line-height: 1.15; margin: 0 0 8px; letter-spacing: -.01em; }
.lede { color: var(--muted); margin: 0 0 28px; }
a { color: var(--accent); }
:focus-visible { outline: 2px solid var(--accent); outline-offset: 2px; }

.checks { list-style: none; margin: 0 0 28px; padding: 0; border-top: 1px solid var(--rule); }
.checks li { display: grid; grid-template-columns: 22px 1fr; gap: 2px 8px; padding: 9px 0; border-bottom: 1px solid var(--rule); }
.checks .mark { font-weight: 700; }
.checks .yes { color: var(--ok); }
.checks .no { color: var(--bad); }
.checks .detail { grid-column: 2; color: var(--muted); font-size: .88rem; overflow-wrap: anywhere; }

.drop {
  display: grid; place-items: center; text-align: center; gap: 6px;
  min-height: 220px; padding: 28px;
  background: var(--surface); border: 2px dashed var(--rule); border-radius: 10px;
  cursor: pointer; transition: border-color .15s, background .15s;
}
.drop:hover, .drop.over { border-color: var(--accent); }
.drop.over { background: color-mix(in srgb, var(--accent) 8%, var(--surface)); }
.drop[aria-disabled="true"] { opacity: .5; cursor: not-allowed; }
.drop strong { font-size: 1.2rem; }
.drop span { color: var(--muted); }
.drop input { position: absolute; width: 1px; height: 1px; opacity: 0; }
.bar { height: 6px; width: 100%; max-width: 320px; background: var(--rule); border-radius: 3px; overflow: hidden; display: none; }
.bar i { display: block; height: 100%; width: 0; background: var(--accent); transition: width .1s; }
.busy .bar { display: block; }

.result { margin-top: 24px; }
.result h2 { font-size: 1.25rem; margin: 0 0 4px; }
.result.error h2 { color: var(--bad); }
.result.done h2 { color: var(--ok); }
.result p { margin: 0 0 12px; }
.log { font: 13px/1.6 var(--mono); background: var(--surface); border: 1px solid var(--rule); border-radius: 6px;
       padding: 10px 14px; max-height: 240px; overflow: auto; white-space: pre; }
.actions { display: flex; flex-wrap: wrap; gap: 10px; margin-top: 16px; align-items: center; }
.button { font: inherit; font-weight: 700; text-decoration: none; cursor: pointer; border-radius: 6px; padding: 9px 18px; border: 0;
          background: var(--accent); color: var(--surface); }
.button.quiet { background: transparent; color: var(--ink); border: 1px solid var(--rule); }
</style>
</head>
<body>
<main>
  <h1><?= $installed ? 'Update Mashpad' : 'Install Mashpad' ?></h1>
  <p class="lede">Drop <strong>message-board.zip</strong> here. Files go into this folder; <?= $installed ? 'existing pads are kept.' : 'the data folders are created for you.' ?></p>

  <ul class="checks">
    <?php foreach ($checks as [$label, $ok, $detail]): ?>
      <li>
        <span class="mark <?= $ok ? 'yes' : 'no' ?>" aria-label="<?= $ok ? 'Passed' : 'Failed' ?>"><?= $ok ? '✓' : '✕' ?></span>
        <span><?= htmlspecialchars($label) ?></span>
        <span class="detail"><?= htmlspecialchars($detail) ?></span>
      </li>
    <?php endforeach; ?>
  </ul>

  <label class="drop" id="drop" <?= $allOk ? '' : 'aria-disabled="true"' ?>>
    <input type="file" id="file" accept=".zip,application/zip" <?= $allOk ? '' : 'disabled' ?>>
    <strong id="drop-title"><?= $allOk ? 'Drop the .zip file here' : 'Fix the failed checks first' ?></strong>
    <span id="drop-sub"><?= $allOk ? 'or click to choose it' : 'then reload this page' ?></span>
    <div class="bar"><i id="bar"></i></div>
  </label>

  <div id="result" class="result" aria-live="polite"></div>
</main>

<script>
const drop = document.getElementById('drop');
const input = document.getElementById('file');
const result = document.getElementById('result');
const esc = s => String(s).replace(/[&<>"']/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
const enabled = drop.getAttribute('aria-disabled') !== 'true';
const BASE = new URL('.', location.href.split('?')[0]).href;

['dragenter', 'dragover'].forEach(t => drop.addEventListener(t, e => { e.preventDefault(); if (enabled) drop.classList.add('over'); }));
['dragleave', 'drop'].forEach(t => drop.addEventListener(t, e => { e.preventDefault(); drop.classList.remove('over'); }));
drop.addEventListener('drop', e => { if (enabled && e.dataTransfer.files[0]) upload(e.dataTransfer.files[0]); });
input.addEventListener('change', () => { if (input.files[0]) upload(input.files[0]); });
window.addEventListener('dragover', e => e.preventDefault());
window.addEventListener('drop', e => e.preventDefault());

function setDrop(title, sub) {
  document.getElementById('drop-title').textContent = title;
  document.getElementById('drop-sub').textContent = sub;
}

function upload(file) {
  if (!/\.zip$/i.test(file.name)) {
    result.className = 'result error';
    result.innerHTML = `<h2>That’s not a .zip file</h2><p>Drop message-board.zip, not ${esc(file.name)}.</p>`;
    return;
  }
  const fd = new FormData();
  fd.append('package', file);
  const xhr = new XMLHttpRequest();
  drop.classList.add('busy');
  setDrop('Installing ' + file.name, 'Uploading…');
  result.innerHTML = '';
  xhr.upload.onprogress = e => {
    if (e.lengthComputable) document.getElementById('bar').style.width = (e.loaded / e.total * 100) + '%';
    if (e.loaded === e.total) setDrop('Installing ' + file.name, 'Extracting files…');
  };
  xhr.onload = () => {
    drop.classList.remove('busy');
    document.getElementById('bar').style.width = '0';
    input.value = '';
    let d;
    try { d = JSON.parse(xhr.responseText); } catch { d = { ok: false, error: 'The server returned an unexpected response (HTTP ' + xhr.status + ').' }; }
    if (!d.ok) {
      setDrop('Drop the .zip file here', 'or click to choose it');
      result.className = 'result error';
      result.innerHTML = `<h2>Install didn’t finish</h2><p>${esc(d.error)}</p>`;
      return;
    }
    setDrop('Drop a newer .zip to update', 'or click to choose it');
    result.className = 'result done';
    result.innerHTML = `
      <h2>Installed</h2>
      <p>${esc(d.summary)}</p>
      <div class="log">${d.log.map(esc).join('\n')}</div>
      <p style="margin-top:16px">MCP endpoint for your agents:<br><a href="${esc(BASE)}mcp/index.php">${esc(BASE)}mcp/index.php</a></p>
      <div class="actions">
        <a class="button" href="${esc(BASE)}">Open Mashpad</a>
        <button class="button quiet" type="button" id="del">Delete install.php</button>
        <span id="del-note" class="detail"></span>
      </div>`;
    document.getElementById('del').onclick = async () => {
      const r = await fetch('install.php?action=delete-self', { method: 'POST' }).then(r => r.json()).catch(() => ({ ok: false }));
      document.getElementById('del-note').textContent = r.ok ? 'Deleted. Upload it again whenever you need to update.' : 'Couldn’t delete it; remove it over FTP if you like.';
      if (r.ok) document.getElementById('del').remove();
    };
  };
  xhr.onerror = () => {
    drop.classList.remove('busy');
    setDrop('Drop the .zip file here', 'or click to choose it');
    result.className = 'result error';
    result.innerHTML = '<h2>Upload failed</h2><p>The connection dropped. Try again.</p>';
  };
  xhr.open('POST', 'install.php');
  xhr.send(fd);
}
</script>
</body>
</html>
