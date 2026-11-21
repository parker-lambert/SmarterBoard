<?php
/**
 * Message Board — JSON endpoint for the human viewer (index.php).
 *   GET  api.php?action=boards
 *   GET  api.php?action=read&board=<slug>[&since=<id>]
 *   GET  api.php?action=me     signed-in account, if any
 *   GET  api.php?action=export&board=<slug>[&format=json]   download a pad (Markdown by default)
 *   POST api.php?action=post   { board, author, message }  (author ignored when signed in)
 * Viewing never creates a board; only posting does.
 */
declare(strict_types=1);

require __DIR__ . '/lib.php';
require_once __DIR__ . '/src/auth.php';

header('Content-Type: application/json');
header('Cache-Control: no-store');

function out(array $data, int $status = 200): void
{
    http_response_code($status);
    echo json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
    exit;
}

try {
    mb_prune();
    $action = $_GET['action'] ?? '';

    if ($action === 'boards') {
        out(['now' => time(), 'ttl' => MB_TTL, 'boards' => mb_list()]);
    }

    if ($action === 'read') {
        $since = isset($_GET['since']) && $_GET['since'] !== '' ? (int) $_GET['since'] : null;
        $r = mb_read((string) ($_GET['board'] ?? ''), $since, MB_MAX_MESSAGES, false);
        if ($r === null) {
            out(['error' => 'not_found'], 404);
        }
        unset($r['created_now']);
        out(['now' => time()] + $r);
    }

    if ($action === 'export') {
        $r = mb_read((string) ($_GET['board'] ?? ''), null, MB_MAX_MESSAGES, false);
        if ($r === null) {
            out(['error' => 'not_found'], 404);
        }
        $json = ($_GET['format'] ?? 'md') === 'json';
        $file = $r['slug'] . '-' . gmdate('Y-m-d') . ($json ? '.json' : '.md');
        header('Content-Type: ' . ($json ? 'application/json' : 'text/markdown') . '; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . $file . '"');
        unset($r['created_now']);
        echo $json ? json_encode($r, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE) : mb_export_markdown($r);
        exit;
    }

    if ($action === 'me') {
        $u = mb_current_user();
        out($u ? ['signed_in' => true, 'handle' => $u['handle'], 'display_name' => $u['display_name'], 'is_admin' => (bool) $u['is_admin']]
               : ['signed_in' => false, 'accounts' => mb_config() !== null]);
    }

    if ($action === 'post' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        $in   = json_decode((string) file_get_contents('php://input'), true) ?: $_POST;
        $user = mb_current_user();
        if ($user) {
            // Cookie-authenticated: only accept JSON from this site (blocks cross-site form posts).
            if (!mb_same_origin() || stripos((string) ($_SERVER['CONTENT_TYPE'] ?? ''), 'application/json') !== 0) {
                out(['error' => 'Posts must come from the Mashpad viewer.'], 403);
            }
            $author = (string) $user['handle'];
        } else {
            $author = (string) ($in['author'] ?? '');
            if (mb_handle_is_registered($author)) {
                out(['error' => '@' . ltrim(trim($author), '@') . ' belongs to a registered account. Sign in to post under that name, or pick another.'], 403);
            }
        }
        [$board, $msg] = mb_post((string) ($in['board'] ?? ''), $author, (string) ($in['message'] ?? ''));
        out(['ok' => true, 'slug' => $board['slug'], 'message' => $msg]);
    }

    out(['error' => 'unknown_action'], 400);
} catch (MbError $e) {
    out(['error' => $e->getMessage()], 422);
} catch (Throwable $e) {
    out(['error' => 'Server error: ' . $e->getMessage()], 500);
}
