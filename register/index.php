<?php
declare(strict_types=1);
require_once __DIR__ . '/../src/auth.php';
require_once __DIR__ . '/../src/page.php';

$error = null;
$next  = mb_safe_next((string) ($_POST['next'] ?? $_GET['next'] ?? ''));
$pdo   = null;
try { $pdo = mb_db(); } catch (Throwable $t) { $error = 'Registration is unavailable right now. Please try again shortly.'; }

if ($pdo && $_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        if (!mb_csrf_ok((string) ($_POST['csrf'] ?? ''))) {
            throw new MbAccountError('Your session form expired. Please try again.');
        }
        mb_register($pdo, $_POST);
        header('Location: ' . $next, true, 303);
        exit;
    } catch (MbAccountError $x) {
        $error = $x->getMessage();
    }
}
$user = mb_current_user();
$open = $pdo && mb_registration_open($pdo);
$csrf = mb_csrf_token();
$v    = fn (string $k) => mb_e($_POST[$k] ?? '');
mb_page_start('Create an account');
?>
  <h1>Create an account</h1>
<?php if ($pdo === null && !$error): ?>
  <p>Accounts aren't set up on this site yet.</p>
<?php elseif ($user): ?>
  <p class="notice" role="status">You're signed in as <strong>@<?= mb_e($user['handle']) ?></strong>.</p>
  <p><a href="<?= mb_e($next) ?>">Continue to your pads</a> · <a href="../signout/">Sign out</a></p>
<?php elseif ($pdo && !$open): ?>
  <p>New accounts aren't being accepted right now.</p>
<?php else: ?>
  <?php if ($error): ?><p class="error" role="alert"><?= mb_e($error) ?></p><?php endif; ?>
  <?php if ($pdo): ?>
  <form method="post" action="./">
    <input type="hidden" name="csrf" value="<?= mb_e($csrf) ?>">
    <input type="hidden" name="next" value="<?= mb_e($next) ?>">
    <label>Your name <input name="display_name" required maxlength="80" autocomplete="name" value="<?= $v('display_name') ?>"></label>
    <label>Handle <span class="hint">How you appear on pads. Letters, numbers, - and _; no need to type the @.</span>
      <span class="handle-field"><span class="handle-at" aria-hidden="true">@</span><input name="handle" required minlength="3" maxlength="41" spellcheck="false" autocapitalize="none" autocomplete="username" value="<?= $v('handle') ?>"></span></label>
    <label>Email <input name="email" type="email" required autocomplete="email" value="<?= $v('email') ?>"></label>
    <label>Password <span class="hint">At least <?= MB_PASSWORD_MIN ?> characters. A few unrelated words works well.</span>
      <input name="password" type="password" required minlength="<?= MB_PASSWORD_MIN ?>" autocomplete="new-password"></label>
    <label>Password again <input name="password2" type="password" required minlength="<?= MB_PASSWORD_MIN ?>" autocomplete="new-password"></label>
    <button type="submit">Create account</button>
  </form>
  <p class="aside">Already have one? <a href="../signin/?next=<?= mb_e(rawurlencode($next)) ?>">Sign in</a>.</p>
  <?php endif; ?>
<?php endif; ?>
<?php mb_page_end();
