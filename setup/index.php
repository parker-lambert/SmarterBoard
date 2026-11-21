<?php
/**
 * Mashpad — one-time setup: connect the database, then create the admin account.
 * Requires the code in data/setup-code.txt. Refuses to run once setup is complete.
 */
declare(strict_types=1);

require_once __DIR__ . '/../src/setup.php';

header('Cache-Control: no-store');
header('X-Robots-Tag: noindex');
header('Referrer-Policy: no-referrer');

$e = fn ($s) => htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');
$error = null;
$notice = null;

try {
    $stage = mb_setup_stage();
} catch (Throwable $t) {
    $stage = 'broken';
    $error = 'The saved database settings no longer work: ' . $t->getMessage()
           . ' Fix the database, or delete data/config.php on the server to run setup again.';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && in_array($stage, ['database', 'admin'], true)) {
    try {
        if (!mb_setup_code_ok((string) ($_POST['setup_code'] ?? ''))) {
            throw new MbAccountError('That setup code doesn\'t match data/setup-code.txt.');
        }
        if ($stage === 'database') {
            $label = mb_setup_database($_POST);
            header('Location: ./?step=account&db=' . rawurlencode($label), true, 303);
        } else {
            mb_setup_admin($_POST);
            header('Location: ./?step=done', true, 303);
        }
        exit;
    } catch (MbAccountError $x) {
        $error = $x->getMessage();
    } catch (Throwable $x) {
        $error = 'Setup failed: ' . $x->getMessage();
    }
}

if ($stage !== 'done' && $stage !== 'broken') {
    try {
        mb_setup_code();   // make sure the code file exists so it can be read
    } catch (Throwable $x) {
        $error = $x->getMessage();
    }
}
if (isset($_GET['db']) && $stage === 'admin') {
    $notice = 'Connected to ' . $_GET['db'] . ' and created the tables.';
}
$v = fn (string $k, string $d = '') => $e($_POST[$k] ?? $d);
?><!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex">
<title>Set up Mashpad</title>
<link rel="icon" href="../assets/smarterboard-mark.svg" type="image/svg+xml">
<link rel="stylesheet" href="../assets/boards.css?v=<?= substr(hash_file('sha256', __DIR__ . '/../assets/boards.css'), 0, 12) ?>">
<style>
  .setup { max-width: 40rem; margin: 0 auto; padding: 48px 24px 72px; }
  .setup h1 { margin: 8px 0 6px; line-height: 1.2; }
  .setup .step { color: var(--muted); margin: 0 0 24px; }
  .setup form { display: grid; gap: 16px; margin-top: 20px; }
  .setup label { display: grid; gap: 4px; font-weight: 700; }
  .setup .hint { font-weight: 400; color: var(--muted); font-size: .9em; }
  .setup input { font: inherit; padding: 10px 12px; border: 1px solid #b9c4c2; border-radius: 4px; background: #fff; color: var(--ink); width: 100%; box-sizing: border-box; }
  .setup .row { display: grid; grid-template-columns: 1fr 8rem; gap: 12px; }
  .setup button { justify-self: start; font: inherit; font-weight: 700; padding: 10px 22px; border: 0; border-radius: 4px; background: var(--ink); color: #fff; cursor: pointer; }
  .setup .error { border-left: 4px solid #a8322d; padding: 8px 14px; background: #fbefee; }
  .setup .notice { border-left: 4px solid var(--accent); padding: 8px 14px; background: #eef5f4; }
  .setup code { font-size: .9em; }
  .handle-field { display: flex; align-items: stretch; }
  .handle-field .handle-at { display: flex; align-items: center; padding: 0 10px; border: 1px solid #b9c4c2; border-right: 0; border-radius: 4px 0 0 4px; background: #f1f4f3; font-weight: 700; }
  .handle-field input { border-radius: 0 4px 4px 0; }
  @media (max-width: 520px) { .setup .row { grid-template-columns: 1fr; } }
</style>
</head>
<body>
<main class="setup" id="main">
  <a class="brand" href="../">Mashpad</a>
<?php if ($stage === 'done'): ?>
  <h1>Mashpad is set up</h1>
  <p><?= isset($_GET['step']) ? 'Your admin account is ready. ' : '' ?>The database is connected and an admin account exists, so this page is switched off.</p>
  <p><a href="../pads/">Go to your pads</a></p>
<?php elseif ($stage === 'broken'): ?>
  <h1>Database unavailable</h1>
  <p class="error" role="alert"><?= $e($error) ?></p>
<?php else: ?>
  <p class="step">Step <?= $stage === 'database' ? '1' : '2' ?> of 2</p>
  <h1><?= $stage === 'database' ? 'Connect the database' : 'Create your admin account' ?></h1>
  <?php if ($notice): ?><p class="notice" role="status"><?= $e($notice) ?></p><?php endif; ?>
  <?php if ($error): ?><p class="error" role="alert"><?= $e($error) ?></p><?php endif; ?>

  <?php if ($stage === 'database'): ?>
  <p>Enter the details from your hosting control panel. They're tested before anything is saved, and stored only on this server in <code>data/config.php</code>.</p>
  <?php else: ?>
  <p>This becomes the first account and the site's administrator.</p>
  <?php endif; ?>

  <form method="post" autocomplete="off">
    <label>Setup code
      <span class="hint">Open <code>data/setup-code.txt</code> in this Mashpad folder on your server, for example with your host's file manager.</span>
      <input name="setup_code" required spellcheck="false" autocapitalize="characters" value="<?= $v('setup_code') ?>">
    </label>
  <?php if ($stage === 'database'): ?>
    <div class="row">
      <label>Database host <input name="host" required spellcheck="false" value="<?= $v('host') ?>"></label>
      <label>Port <input name="port" inputmode="numeric" value="<?= $v('port', '3306') ?>"></label>
    </div>
    <label>Database name <input name="name" required spellcheck="false" value="<?= $v('name') ?>"></label>
    <label>User name <input name="user" required spellcheck="false" value="<?= $v('user') ?>"></label>
    <label>Password <input name="pass" type="password" autocomplete="new-password"></label>
    <button type="submit">Test and connect</button>
  <?php else: ?>
    <label>Your name <input name="display_name" required maxlength="80" value="<?= $v('display_name') ?>"></label>
    <label>Handle <span class="hint">How you appear on pads, e.g. dylan. Letters, numbers, - and _; no need to type the @.</span>
      <span class="handle-field"><span class="handle-at" aria-hidden="true">@</span><input name="handle" required maxlength="41" spellcheck="false" autocapitalize="none" value="<?= $v('handle') ?>"></span></label>
    <label>Email <input name="email" type="email" required autocomplete="email" value="<?= $v('email') ?>"></label>
    <label>Password <span class="hint">At least <?= MB_PASSWORD_MIN ?> characters.</span>
      <input name="password" type="password" required minlength="<?= MB_PASSWORD_MIN ?>" autocomplete="new-password"></label>
    <label>Password again <input name="password2" type="password" required minlength="<?= MB_PASSWORD_MIN ?>" autocomplete="new-password"></label>
    <button type="submit">Create admin account</button>
  <?php endif; ?>
  </form>
<?php endif; ?>
</main>
</body>
</html>
