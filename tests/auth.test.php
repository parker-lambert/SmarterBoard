<?php
/**
 * Sign-in, registration and session tests. Same database rules as
 * db-foundation.test.php: a disposable database named mashpad_test*, every table
 * dropped first. Restores data/config.php and data/schema-version afterwards.
 */
declare(strict_types=1);

$root = dirname(__DIR__);
$env  = fn ($k, $d = '') => getenv($k) !== false ? (string) getenv($k) : $d;
$db   = ['host' => $env('MASHPAD_TEST_DB_HOST', '127.0.0.1'), 'port' => (int) $env('MASHPAD_TEST_DB_PORT', '3306'),
         'name' => $env('MASHPAD_TEST_DB_NAME'), 'user' => $env('MASHPAD_TEST_DB_USER'), 'pass' => $env('MASHPAD_TEST_DB_PASS')];
if (!str_starts_with($db['name'], 'mashpad_test')) {
    fwrite(STDERR, "skipped: set MASHPAD_TEST_DB_NAME to a disposable database named mashpad_test*\n");
    exit(0);
}
$files = ["$root/data/config.php", "$root/data/schema-version"];
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
/** Run PHP in a fresh process (empty static caches) with the given cookies and server vars. */
function fresh(string $code, array $cookies = [], array $server = []): string {
    global $root;
    $prelude = '$_COOKIE = ' . var_export($cookies, true) . '; $_SERVER = ' . var_export($server, true) . ' + $_SERVER; '
             . "require '$root/src/auth.php'; ";
    $cmd = escapeshellarg(PHP_BINARY) . ' -r ' . escapeshellarg($prelude . $code);
    return trim((string) shell_exec($cmd . ' 2>&1'));
}
/** Run and return [output, cookies after] — cookies are printed as JSON on the last line. */
function run(string $code, array $cookies = [], array $server = []): array {
    $out = fresh("try { $code } catch (Throwable \$t) { echo 'error=' . \$t->getMessage(); } echo \"\\n\" . json_encode(\$_COOKIE);", $cookies, $server);
    $lines = explode("\n", $out);
    $jar = json_decode((string) array_pop($lines), true) ?: [];
    return [trim(implode("\n", $lines)), $jar];
}

require "$root/src/db.php";
$pdo = mb_db_connect($db);
$pdo->exec('SET FOREIGN_KEY_CHECKS = 0');
foreach ($pdo->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN) as $t) { $pdo->exec("DROP TABLE `$t`"); }
$pdo->exec('SET FOREIGN_KEY_CHECKS = 1');
mb_migrate($pdo);
mb_write_config($db);

$ip  = ['REMOTE_ADDR' => '203.0.113.5'];
$reg = fn (array $over = []) => var_export($over + ['display_name' => 'Ada', 'handle' => 'ada', 'email' => 'Ada@Example.com',
                                                    'password' => 'correct horse battery', 'password2' => 'correct horse battery'], true);

// Registration
[$out, $jar] = run('echo "id=" . mb_register(mb_db(), ' . $reg() . ');', [], $ip);
check('register creates an account', str_starts_with($out, 'id='), $out);
check('register signs in (session cookie)', isset($jar['mashpad_session']) && strlen($jar['mashpad_session']) === 43);
$session = $jar['mashpad_session'];
check('only the hash of the session is stored', (int) $pdo->query("SELECT COUNT(*) FROM tokens WHERE kind = 'session'")->fetchColumn() === 1
      && !$pdo->query("SELECT COUNT(*) FROM tokens WHERE token_hash = " . $pdo->quote($session))->fetchColumn());
[$out] = run('echo "id=" . mb_register(mb_db(), ' . $reg(['handle' => 'ada2']) . ');', [], $ip);
check('duplicate email refused', str_contains($out, 'already in use'), $out);
[$out] = run('echo "id=" . mb_register(mb_db(), ' . $reg(['email' => 'other@example.com']) . ');', [], $ip);
check('duplicate handle refused', str_contains($out, 'already in use'), $out);
[$out] = run('echo "id=" . mb_register(mb_db(), ' . $reg(['handle' => 'bob', 'email' => 'b@example.com', 'password2' => 'something else here']) . ');', [], $ip);
check('password mismatch refused', str_contains($out, 'don\'t match'), $out);
$dup = false;
try { $pdo->exec("INSERT INTO principals (kind, handle, display_name) VALUES ('human', 'ada', 'Race')"); } catch (PDOException $e) { $dup = (int) $e->errorInfo[1] === 1062; }
check('database itself rejects a second human @ada', $dup);
$pdo->exec("INSERT INTO principals (kind, handle, display_name) VALUES ('agent', 'ada', 'Agent Ada')");
check('an agent may share a human handle (per-pad rules come later)', true);

[$out] = run('echo "id=" . mb_register(mb_db(), ' . $reg(['handle' => '@Bea', 'email' => 'bea@example.com']) . ');', [], ['REMOTE_ADDR' => '192.0.2.50']);
check('a typed @ is accepted and dropped', str_starts_with($out, 'id=') && $pdo->query("SELECT COUNT(*) FROM principals WHERE kind = 'human' AND handle = 'bea'")->fetchColumn() == 1, $out);

// Current user
[$out] = run('$u = mb_current_user(); echo $u ? $u["handle"] : "none";', ['mashpad_session' => $session]);
check('session cookie identifies the user', $out === 'ada', $out);
[$out] = run('$u = mb_current_user(); echo $u ? $u["handle"] : "none";', ['mashpad_session' => 'x' . substr($session, 1)]);
check('altered cookie is not a session', $out === 'none', $out);

// Sign-in
$si = fn (string $email, string $pw) => 'echo "id=" . mb_sign_in(mb_db(), ' . var_export($email, true) . ', ' . var_export($pw, true) . ');';
[$out] = run($si('ada@example.com', 'wrong password!!'), [], $ip);
check('wrong password: generic message', str_contains($out, 'don\'t match an account'), $out);
[$out] = run($si('nobody@example.com', 'whatever whatever'), [], $ip);
check('unknown email: same generic message', str_contains($out, 'don\'t match an account'), $out);
[$out, $jar] = run($si('ADA@example.com ', 'correct horse battery'), [], $ip);
check('right password signs in (email case/space ignored)', str_starts_with($out, 'id=') && isset($jar['mashpad_session']), $out);
$second = $jar['mashpad_session'];
check('each sign-in gets a new session', $second !== $session);
for ($i = 0; $i < 4; $i++) { run($si('ada@example.com', "bad guess $i!!!"), [], $ip); }
[$out] = run($si('ada@example.com', 'correct horse battery'), [], $ip);
check('5 failures lock the email for a while, even with the right password', str_contains($out, 'Too many sign-in attempts'), $out);
[$out] = run($si('ada@example.com', 'correct horse battery'), [], ['REMOTE_ADDR' => '198.51.100.9']);
check('the lock follows the email, not just the IP', str_contains($out, 'Too many'), $out);
$pdo->exec("DELETE FROM auth_attempts WHERE kind = 'signin'");

// Sign-out
[$out, $jar] = run('mb_sign_out(); echo "done";', ['mashpad_session' => $second]);
check('sign-out clears the cookie', !isset($jar['mashpad_session']), json_encode($jar));
[$out] = run('$u = mb_current_user(); echo $u ? $u["handle"] : "none";', ['mashpad_session' => $second]);
check('old session no longer works after sign-out (T4)', $out === 'none', $out);
[$out] = run('$u = mb_current_user(); echo $u ? $u["handle"] : "none";', ['mashpad_session' => $session]);
check('other sessions are unaffected', $out === 'ada', $out);
$pdo->prepare("UPDATE tokens SET expires_at = UTC_TIMESTAMP() - INTERVAL 1 MINUTE WHERE kind = 'session'")->execute();
[$out] = run('$u = mb_current_user(); echo $u ? $u["handle"] : "none";', ['mashpad_session' => $session]);
check('expired sessions are refused', $out === 'none', $out);

// Registration limits and switch
for ($i = 0; $i < 4; $i++) { run('mb_register(mb_db(), ' . $reg(['handle' => "user$i", 'email' => "u$i@example.com"]) . ');', [], $ip); }
[$out] = run('echo "id=" . mb_register(mb_db(), ' . $reg(['handle' => 'user9', 'email' => 'u9@example.com']) . ');', [], $ip);
check('5 registrations an hour per IP', str_contains($out, 'Too many registration attempts'), $out);
$pdo->exec("UPDATE settings SET value = '0' WHERE name = 'registration_open'");
[$out] = run('echo "id=" . mb_register(mb_db(), ' . $reg(['handle' => 'user8', 'email' => 'u8@example.com']) . ');', [], ['REMOTE_ADDR' => '192.0.2.1']);
check('registration can be closed by setting', str_contains($out, 'aren\'t being accepted'), $out);
$pdo->exec("UPDATE settings SET value = '1' WHERE name = 'registration_open'");

// Handles already signing legacy pad posts can't be registered by someone else
@mkdir("$root/data/boards", 0775, true);
file_put_contents("$root/data/boards/zz-auth-test.json", json_encode(['name' => 'zz', 'slug' => 'zz-auth-test', 'created' => time(), 'next_id' => 2,
    'messages' => [['id' => 1, 'author' => 'Muse', 'body' => 'x', 'ts' => time()]]]));
[$out] = run('echo "id=" . mb_register(mb_db(), ' . $reg(['handle' => 'muse', 'email' => 'm@example.com']) . ');', [], ['REMOTE_ADDR' => '192.0.2.77']);
@unlink("$root/data/boards/zz-auth-test.json");
check('handles in use on existing pads are reserved', str_contains($out, 'used by someone posting'), $out);

// CSRF and origin
$out = fresh('echo mb_csrf_ok("abc") ? "y" : "n";', ['mashpad_csrf' => 'abc']);
check('csrf: matching token passes', $out === 'y');
$out = fresh('echo mb_csrf_ok("abd") ? "y" : "n";', ['mashpad_csrf' => 'abc']);
check('csrf: mismatch fails', $out === 'n');
$out = fresh('echo mb_csrf_ok("abc") ? "y" : "n";', ['mashpad_csrf' => 'abc'], ['HTTP_HOST' => 'mashpad.ai', 'HTTP_ORIGIN' => 'https://evil.example']);
check('csrf: foreign Origin fails', $out === 'n');
$out = fresh('echo mb_csrf_ok("abc") ? "y" : "n";', ['mashpad_csrf' => 'abc'], ['HTTP_HOST' => 'mashpad.ai', 'HTTP_SEC_FETCH_SITE' => 'cross-site']);
check('csrf: cross-site fetch fails', $out === 'n');
$out = fresh('echo mb_csrf_ok("abc") ? "y" : "n";', ['mashpad_csrf' => 'abc'], ['HTTP_HOST' => 'mashpad.ai', 'HTTP_ORIGIN' => 'https://mashpad.ai']);
check('csrf: same Origin passes', $out === 'y');

// Helpers
$srv = ['SCRIPT_FILENAME' => "$root/signin/index.php", 'SCRIPT_NAME' => '/message-board/signin/index.php'];
check('base path derived from the script', fresh('echo mb_base_path();', [], $srv) === '/message-board/');
check('next: internal path kept', fresh('echo mb_safe_next("/message-board/pads/?b=x");', [], $srv) === '/message-board/pads/?b=x');
check('next: other site refused', fresh('echo mb_safe_next("https://evil.example/");', [], $srv) === '/message-board/pads/');
check('next: protocol-relative refused', fresh('echo mb_safe_next("//evil.example/");', [], $srv) === '/message-board/pads/');
check('registered handle detected with @ and any case', fresh('echo mb_handle_is_registered("@ADA") ? "y" : "n";') === 'y');
check('unregistered name passes', fresh('echo mb_handle_is_registered("reviewer") ? "y" : "n";') === 'n');
check('agents do not count as registered human handles', fresh('echo mb_handle_is_registered("u-agent") ? "y" : "n";') === 'n');

echo "\n$pass passed, $fail failed\n";
exit($fail ? 1 : 0);
