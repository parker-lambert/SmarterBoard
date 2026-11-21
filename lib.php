<?php
/**
 * Message Board — shared storage layer.
 *
 * One JSON file per board in data/boards/<slug>.json, guarded with flock().
 * A board's file mtime is its "last activity" (it only changes on create/post),
 * so pruning is a cheap filemtime() scan with no cron job required.
 */
declare(strict_types=1);

const MB_VERSION       = '1.1.0';
const MB_TTL           = 30 * 86400;  // delete boards after 30 days with no new messages
const MB_PRUNE_EVERY   = 600;         // run the prune scan at most every 10 minutes
const MB_MAX_BODY      = 65536;       // bytes per message
const MB_MAX_AUTHOR    = 40;          // chars
const MB_MAX_MESSAGES  = 2000;        // per board; oldest dropped beyond this
const MB_DATA_DIR      = __DIR__ . '/data';

date_default_timezone_set('UTC');

class MbError extends RuntimeException {}

function mb_boards_dir(): string
{
    $dir = MB_DATA_DIR . '/boards';
    if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) {
        throw new MbError('Storage directory is not writable: ' . $dir);
    }
    return $dir;
}

/** Normalise any board name to a filesystem-safe slug. */
function mb_slug(string $name): string
{
    $s = strtolower(trim($name));
    $s = (string) preg_replace('/[^a-z0-9]+/', '-', $s);
    $s = trim(substr(trim($s, '-'), 0, 64), '-');
    if ($s === '') {
        throw new MbError('Pad name must contain at least one letter or number.');
    }
    return $s;
}

function mb_path(string $slug): string
{
    return mb_boards_dir() . '/' . $slug . '.json';
}

function mb_clean_author(string $author): string
{
    $a = trim((string) preg_replace('/[\x00-\x1F\x7F]+/u', ' ', $author));
    $a = (string) preg_replace('/\s+/u', ' ', $a);
    if ($a === '') {
        throw new MbError('Author is required. Use a short, stable name such as "builder" or "tester".');
    }
    preg_match('/^.{0,' . MB_MAX_AUTHOR . '}/us', $a, $m);   // no mbstring dependency
    return trim($m[0] ?? substr($a, 0, MB_MAX_AUTHOR));
}

function mb_clean_body(string $body): string
{
    $b = str_replace(["\r\n", "\r"], "\n", $body);
    $b = (string) preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $b);
    if (trim($b) === '') {
        throw new MbError('Message is empty.');
    }
    if (strlen($b) > MB_MAX_BODY) {
        throw new MbError('Message is too long (' . strlen($b) . ' bytes; limit ' . MB_MAX_BODY . '). Split it into several posts.');
    }
    return rtrim($b);
}

/**
 * Open a board under an exclusive lock and run $fn(array &$board): bool.
 * $fn returns true if it changed the board (it is then written back).
 * Returns null when the board does not exist and $create is false.
 */
function mb_transaction(string $name, bool $create, callable $fn): ?array
{
    $slug = mb_slug($name);
    $path = mb_path($slug);

    for ($attempt = 0; $attempt < 3; $attempt++) {
        clearstatcache(true, $path);
        if (!$create && !is_file($path)) {
            return null;
        }
        $fh = @fopen($path, 'c+');
        if ($fh === false) {
            throw new MbError('Could not open pad storage.');
        }
        flock($fh, LOCK_EX);

        // If the pruner unlinked this file while we waited for the lock, start over.
        $stat = fstat($fh);
        if ($stat !== false && $stat['nlink'] === 0) {
            flock($fh, LOCK_UN);
            fclose($fh);
            continue;
        }

        $raw   = stream_get_contents($fh);
        $board = $raw !== '' && $raw !== false ? json_decode($raw, true) : null;
        $isNew = false;

        if (!is_array($board)) {
            if (!$create) {
                flock($fh, LOCK_UN);
                fclose($fh);
                return null;
            }
            $board = [
                'name'     => trim($name),
                'slug'     => $slug,
                'created'  => time(),
                'next_id'  => 1,
                'messages' => [],
            ];
            $isNew = true;
        }

        $changed = $fn($board) || $isNew;

        if ($changed) {
            $json = json_encode($board, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
            ftruncate($fh, 0);
            rewind($fh);
            fwrite($fh, (string) $json);
            fflush($fh);
        }
        flock($fh, LOCK_UN);
        fclose($fh);

        $board['_created_now']   = $isNew;
        $board['_last_activity'] = $changed ? time() : (int) ($stat['mtime'] ?? time());
        return $board;
    }
    throw new MbError('Pad is busy; try again.');
}

/** Append a message; creates the board if needed. Returns [board, message]. */
function mb_post(string $boardName, string $author, string $body): array
{
    $author = mb_clean_author($author);
    $body   = mb_clean_body($body);
    $msg    = null;

    $board = mb_transaction($boardName, true, function (array &$b) use ($author, $body, &$msg): bool {
        $msg = [
            'id'     => (int) $b['next_id'],
            'author' => $author,
            'body'   => $body,
            'ts'     => time(),
        ];
        $b['next_id']++;
        $b['messages'][] = $msg;
        if (count($b['messages']) > MB_MAX_MESSAGES) {
            $b['messages'] = array_slice($b['messages'], -MB_MAX_MESSAGES);
        }
        return true;
    });

    return [$board, $msg];
}

/**
 * Read messages. With $sinceId, returns every message newer than it;
 * otherwise the most recent $limit. Returns null if the board doesn't exist
 * and $create is false.
 */
function mb_read(string $boardName, ?int $sinceId, int $limit, bool $create): ?array
{
    $board = mb_transaction($boardName, $create, fn (array &$b): bool => false);
    if ($board === null) {
        return null;
    }
    $all = $board['messages'];
    if ($sinceId !== null) {
        $msgs = array_values(array_filter($all, fn ($m) => $m['id'] > $sinceId));
    } else {
        $msgs = array_slice($all, -$limit);
    }
    $last = end($all);

    return [
        'name'          => $board['name'],
        'slug'          => $board['slug'],
        'created'       => $board['created'],
        'created_now'   => $board['_created_now'],
        'last_activity' => $board['_last_activity'],
        'expires'       => $board['_last_activity'] + MB_TTL,
        'total'         => count($all),
        'latest_id'     => $last ? (int) $last['id'] : 0,
        'messages'      => $msgs,
    ];
}

/** Summaries of every board, most recently active first. */
function mb_list(): array
{
    $out = [];
    foreach (glob(mb_boards_dir() . '/*.json') ?: [] as $path) {
        $fh = @fopen($path, 'r');
        if ($fh === false) {
            continue;
        }
        flock($fh, LOCK_SH);
        $b     = json_decode((string) stream_get_contents($fh), true);
        $mtime = (int) (fstat($fh)['mtime'] ?? 0);
        flock($fh, LOCK_UN);
        fclose($fh);
        if (!is_array($b)) {
            continue;
        }
        $last    = end($b['messages']) ?: null;
        $authors = array_values(array_unique(array_column($b['messages'], 'author')));
        $out[] = [
            'name'          => $b['name'],
            'slug'          => $b['slug'],
            'total'         => count($b['messages']),
            'latest_id'     => $last ? (int) $last['id'] : 0,
            'authors'       => $authors,
            'last_author'   => $last['author'] ?? null,
            'last_activity' => $mtime,
            'expires'       => $mtime + MB_TTL,
        ];
    }
    usort($out, fn ($a, $b) => $b['last_activity'] <=> $a['last_activity']);
    return $out;
}

/** Delete boards with no activity for MB_TTL. Throttled; safe to call on every request. */
function mb_prune(bool $force = false): int
{
    $marker = MB_DATA_DIR . '/.last-prune';
    $dir    = mb_boards_dir();
    if (!$force && is_file($marker) && filemtime($marker) > time() - MB_PRUNE_EVERY) {
        return 0;
    }
    @touch($marker);

    $cutoff  = time() - MB_TTL;
    $deleted = 0;
    foreach (glob($dir . '/*.json') ?: [] as $path) {
        if (@filemtime($path) >= $cutoff) {
            continue;
        }
        $fh = @fopen($path, 'r');
        if ($fh === false) {
            continue;
        }
        flock($fh, LOCK_EX);
        clearstatcache(true, $path);
        if (@filemtime($path) < $cutoff && @unlink($path)) {   // re-check under the lock
            $deleted++;
        }
        flock($fh, LOCK_UN);
        fclose($fh);
    }
    return $deleted;
}

function mb_time(int $ts): string
{
    return gmdate('Y-m-d H:i', $ts) . ' UTC';
}
