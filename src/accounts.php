<?php
/**
 * Mashpad — human accounts (foundation: creation and password handling).
 * Sign-in, sessions and registration build on this in the next release step.
 */
declare(strict_types=1);

require_once __DIR__ . '/db.php';

const MB_PASSWORD_MIN = 12;
const MB_PASSWORD_MAX = 4096;   // bytes; stops hashing-cost abuse

class MbAccountError extends RuntimeException {}

/** Character count without depending on the mbstring extension. */
function mb_ulen(string $s): int
{
    return (int) preg_match_all('/./su', $s);
}

function mb_password_algo()
{
    return defined('PASSWORD_ARGON2ID') ? PASSWORD_ARGON2ID : PASSWORD_DEFAULT;
}

function mb_normalize_email(string $email): string
{
    return strtolower(trim($email));
}

function mb_validate_handle(string $handle): string
{
    $h = strtolower(ltrim(trim($handle), '@'));   // people often type the @ anyway
    if (!preg_match('/^[a-z0-9][a-z0-9_-]{1,38}[a-z0-9]$/', $h)) {
        throw new MbAccountError('Handles are 3 to 40 characters: letters, numbers, hyphens and underscores, starting and ending with a letter or number.');
    }
    return $h;
}

function mb_validate_password(string $password): void
{
    $len = mb_ulen($password);
    if ($len < MB_PASSWORD_MIN) {
        throw new MbAccountError('Use a password of at least ' . MB_PASSWORD_MIN . ' characters. A few unrelated words works well.');
    }
    if (strlen($password) > MB_PASSWORD_MAX) {
        throw new MbAccountError('That password is too long.');
    }
}

/** Create a human principal. Returns its id. */
function mb_create_human(PDO $pdo, string $name, string $handle, string $email, string $password, bool $admin = false, ?string $plan = null): int
{
    $name = trim((string) preg_replace('/\s+/u', ' ', $name));
    if ($name === '' || mb_ulen($name) > 80) {
        throw new MbAccountError('Enter a name of up to 80 characters.');
    }
    $handle = mb_validate_handle($handle);
    $normal = mb_normalize_email($email);
    if (!filter_var($normal, FILTER_VALIDATE_EMAIL) || strlen($normal) > 254) {
        throw new MbAccountError('Enter a valid email address.');
    }
    mb_validate_password($password);

    $plan ??= (string) ($pdo->query("SELECT value FROM settings WHERE name = 'default_plan'")->fetchColumn() ?: 'free');

    $exists = $pdo->prepare("SELECT 1 FROM principals WHERE kind = 'human' AND (email_normalized = ? OR handle = ?)");
    $exists->execute([$normal, $handle]);
    if ($exists->fetchColumn()) {
        throw new MbAccountError('That email address or handle is already in use.');
    }

    $pdo->prepare("INSERT INTO principals (kind, handle, display_name, email, email_normalized, password_hash, plan_code, is_admin)
                   VALUES ('human', ?, ?, ?, ?, ?, ?, ?)")
        ->execute([$handle, $name, trim($email), $normal, password_hash($password, mb_password_algo()), $plan, $admin ? 1 : 0]);
    $id = (int) $pdo->lastInsertId();
    mb_audit($pdo, $id, $admin ? 'account.create_admin' : 'account.create', 'principal:' . $id);
    return $id;
}

function mb_admin_exists(PDO $pdo): bool
{
    return (bool) $pdo->query("SELECT 1 FROM principals WHERE kind = 'human' AND is_admin = 1 AND disabled_at IS NULL LIMIT 1")->fetchColumn();
}
