<?php
declare(strict_types=1);
require_once __DIR__ . '/../src/auth.php';
require_once __DIR__ . '/../src/page.php';

$error = null;
$next  = mb_safe_next((string) ($_POST['next'] ?? $_GET['next'] ?? ''));
$pdo   = null;
try { $pdo = mb_db(); } catch (Throwable $t) { $error = 'Sign-in is unavailable right now. Please try again shortly.'; }

if ($pdo && $_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        if (!mb_csrf_ok((string) ($_POST['csrf'] ?? ''))) {
            throw new MbAccountError('Your session form expired. Please try again.');
        }
        mb_sign_in($pdo, (string) ($_POST['email'] ?? ''), (string) ($_POST['password'] ?? ''));
        header('Location: ' . $next, true, 303);
        exit;
    } catch (MbAccountError $x) {
        $error = $x->getMessage();
    }
}
$user = mb_current_user();
$csrf = mb_csrf_token();
mb_page_start('Sign in');
?>
  <h1>Sign in</h1>
<?php if ($pdo === null && !$error): ?>
  <p>Accounts aren't set up on this site yet.</p>
<?php elseif ($user): ?>
  <p class="notice" role="status">You're signed in as <strong>@<?= mb_e($user['handle']) ?></strong>.</p>
  <p><a href="<?= mb_e($next) ?>">Continue to your pads</a> · <a href="../signout/">Sign out</a></p>
<?php else: ?>
  <?php if (isset($_GET['signed_out'])): ?><p class="notice" role="status">You've signed out.</p><?php endif; ?>
  <?php if ($error): ?><p class="error" role="alert"><?= mb_e($error) ?></p><?php endif; ?>
  <?php if ($pdo): ?>
  <form method="post" action="./">
    <input type="hidden" name="csrf" value="<?= mb_e($csrf) ?>">
    <input type="hidden" name="next" value="<?= mb_e($next) ?>">
    <label>Email <input name="email" type="email" required autocomplete="email" value="<?= mb_e($_POST['email'] ?? '') ?>"></label>
    <label>Password <input name="password" type="password" required autocomplete="current-password"></label>
    <button type="submit">Sign in</button>
  </form>
  <p class="aside">New here? <a href="../register/?next=<?= mb_e(rawurlencode($next)) ?>">Create an account</a>.</p>
  <p class="aside">Forgot your password? Email reset is on its way; until then the site admin can reset it.</p>
  <?php endif; ?>
<?php endif; ?>
<?php mb_page_end();
