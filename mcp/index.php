<?php
/**
 * Message Board — MCP server.
 * Streamable HTTP transport, stateless: every POST is a self-contained JSON-RPC exchange.
 * Endpoint: https://parker-lambert-sandbox.com/message-board/mcp/index.php
 */
declare(strict_types=1);

require __DIR__ . '/../lib.php';

const MCP_PROTOCOLS = ['2025-06-18', '2025-03-26', '2024-11-05'];
const MAX_WAIT      = 20;

header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, GET, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Accept, Authorization, Mcp-Session-Id, Mcp-Protocol-Version');
header('Access-Control-Expose-Headers: Mcp-Session-Id');

switch ($_SERVER['REQUEST_METHOD'] ?? 'GET') {
    case 'OPTIONS':
        http_response_code(204);
        exit;
    case 'DELETE':                       // session teardown: nothing to tear down
        http_response_code(204);
        exit;
    case 'POST':
        break;
    case 'GET':
        // MCP clients ask for an SSE stream here; tell them we don't offer one (spec-required 405).
        // Anything else is a person in a browser, so show a status page instead.
        if (stripos($_SERVER['HTTP_ACCEPT'] ?? '', 'text/event-stream') === false) {
            header('Content-Type: text/plain; charset=utf-8');
            echo "Mashpad MCP server " . MB_VERSION . " is running.\n\n"
               . "This URL is for MCP clients, which talk to it with POST requests.\n"
               . "Add it as a remote MCP server (Streamable HTTP, no authentication).\n"
               . "To watch the pads, open the folder above this one.\n";
            exit;
        }
        // fall through
    default:                             // no server-initiated SSE stream
        header('Allow: POST, OPTIONS');
        http_response_code(405);
        exit;
}

try {
    mb_prune();
} catch (Throwable $e) {
    // pruning must never block a request
}

$payload = json_decode((string) file_get_contents('php://input'), true);
if (!is_array($payload)) {
    send(rpc_error(null, -32700, 'Parse error'));
}

if (isset($payload[0])) {                // JSON-RPC batch (older protocol versions)
    $out = array_values(array_filter(array_map('handle', $payload)));
    $out ? send($out) : accepted();
}
$res = handle($payload);
$res === null ? accepted() : send($res);


// ---------------------------------------------------------------------------

function send(array $body): void
{
    header('Content-Type: application/json');
    echo json_encode($body, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
    exit;
}

function accepted(): void
{
    http_response_code(202);
    exit;
}

function rpc_result($id, array $result): array
{
    return ['jsonrpc' => '2.0', 'id' => $id, 'result' => (object) $result];
}

function rpc_error($id, int $code, string $message): array
{
    return ['jsonrpc' => '2.0', 'id' => $id, 'error' => ['code' => $code, 'message' => $message]];
}

function handle($msg): ?array
{
    if (!is_array($msg) || !isset($msg['method'])) {
        return isset($msg['id']) ? rpc_error($msg['id'], -32600, 'Invalid request') : null;
    }
    $isNotification = !array_key_exists('id', $msg);
    $id     = $msg['id'] ?? null;
    $params = is_array($msg['params'] ?? null) ? $msg['params'] : [];

    if ($isNotification) {
        return null;                     // notifications/initialized, cancelled, etc.
    }

    switch ($msg['method']) {
        case 'initialize':
            $asked = (string) ($params['protocolVersion'] ?? '');
            return rpc_result($id, [
                'protocolVersion' => in_array($asked, MCP_PROTOCOLS, true) ? $asked : MCP_PROTOCOLS[0],
                'capabilities'    => ['tools' => ['listChanged' => false]],
                'serverInfo'      => ['name' => 'message-board', 'title' => 'Mashpad', 'version' => MB_VERSION],
                'instructions'    => instructions(),
            ]);
        case 'ping':
            return rpc_result($id, []);
        case 'tools/list':
            return rpc_result($id, ['tools' => tools()]);
        case 'tools/call':
            return rpc_result($id, call_tool((string) ($params['name'] ?? ''), (array) ($params['arguments'] ?? [])));
        case 'resources/list':
            return rpc_result($id, ['resources' => []]);
        case 'prompts/list':
            return rpc_result($id, ['prompts' => []]);
        default:
            return rpc_error($id, -32601, 'Method not found: ' . $msg['method']);
    }
}

function instructions(): string
{
    return <<<TXT
Mashpad is a shared workspace of pads for people and agents working together, e.g. one agent building an app and another testing it.

- Pads are named. Posting to or reading a pad that doesn't exist creates it.
- Use “pad” and “pads” in all messages, explanations, and product copy. Legacy tool identifiers and the board argument remain compatible; they refer to pads.
- Always sign posts with the same short author name (your role, e.g. "builder", "tester").
- To check for new messages, call read_board with since_id set to the latest_id from your previous read. Use wait_seconds to wait briefly for a reply.
- Write each post so it stands on its own: what you did, what you found, what you need from the other agent.
- Pads are deleted after 30 days without a new message. The operator can watch every pad at https://parker-lambert-sandbox.com/message-board/
TXT;
}

function tools(): array
{
    $board = ['type' => 'string', 'description' => 'Pad name, e.g. "pixelbrief-qa". Case and punctuation are normalised, so "PixelBrief QA" and "pixelbrief-qa" are the same pad. Created automatically if it does not exist.'];

    return [
        [
            'name'        => 'post_message',
            'title'       => 'Post to a pad',
            'description' => 'Post a message to a named pad, creating the pad if it does not exist. Markdown is fine. Returns the new message id.',
            'inputSchema' => [
                'type'       => 'object',
                'properties' => [
                    'board'   => $board,
                    'author'  => ['type' => 'string', 'description' => 'Your short, stable name on this pad, e.g. "builder" or "tester". Use the same name every time you post.'],
                    'message' => ['type' => 'string', 'description' => 'The message. Up to 64 KB; split longer reports into several posts.'],
                ],
                'required' => ['board', 'author', 'message'],
            ],
            'annotations' => ['readOnlyHint' => false, 'destructiveHint' => false, 'idempotentHint' => false, 'openWorldHint' => false],
        ],
        [
            'name'        => 'read_board',
            'title'       => 'Read a pad',
            'description' => 'Read messages from a named pad (creating it if it does not exist). Without since_id, returns the most recent messages. With since_id, returns only messages newer than that id: pass the latest_id from your previous read to check for new messages. Set wait_seconds to wait up to 20 seconds for a new message to arrive.',
            'inputSchema' => [
                'type'       => 'object',
                'properties' => [
                    'board'        => $board,
                    'since_id'     => ['type' => 'integer', 'minimum' => 0, 'description' => 'Only return messages with an id greater than this. Use latest_id from your previous read.'],
                    'limit'        => ['type' => 'integer', 'minimum' => 1, 'maximum' => 200, 'description' => 'When since_id is omitted, how many recent messages to return (default 50).'],
                    'wait_seconds' => ['type' => 'integer', 'minimum' => 0, 'maximum' => MAX_WAIT, 'description' => 'If there are no new messages yet, wait up to this many seconds for one (default 0).'],
                ],
                'required' => ['board'],
            ],
            'annotations' => ['readOnlyHint' => true, 'openWorldHint' => false],
        ],
        [
            'name'        => 'list_boards',
            'title'       => 'List pads',
            'description' => 'List every active pad with its message count, participants, latest message id and last activity time.',
            'inputSchema' => ['type' => 'object', 'properties' => (object) []],
            'annotations' => ['readOnlyHint' => true, 'openWorldHint' => false],
        ],
    ];
}

function tool_text(string $text, bool $isError = false): array
{
    $r = ['content' => [['type' => 'text', 'text' => $text]]];
    if ($isError) {
        $r['isError'] = true;
    }
    return $r;
}

function call_tool(string $name, array $args): array
{
    try {
        switch ($name) {
            case 'post_message':
                return tool_post($args);
            case 'read_board':
                return tool_read($args);
            case 'list_boards':
                return tool_list();
            default:
                return tool_text('Unknown tool: ' . $name, true);
        }
    } catch (MbError $e) {
        return tool_text($e->getMessage(), true);
    } catch (Throwable $e) {
        return tool_text('Server error: ' . $e->getMessage(), true);
    }
}

function tool_post(array $a): array
{
    [$board, $msg] = mb_post((string) ($a['board'] ?? ''), (string) ($a['author'] ?? ''), (string) ($a['message'] ?? ''));
    $new = $board['_created_now'] ? " (new pad created)" : '';
    return tool_text(
        "Posted message #{$msg['id']} to \"{$board['slug']}\"{$new} as {$msg['author']}.\n" .
        "To see replies later, call read_board with since_id={$msg['id']}."
    );
}

function tool_read(array $a): array
{
    $boardName = (string) ($a['board'] ?? '');
    $sinceId   = isset($a['since_id']) && $a['since_id'] !== '' ? max(0, (int) $a['since_id']) : null;
    $limit     = min(200, max(1, (int) ($a['limit'] ?? 50)));
    $wait      = min(MAX_WAIT, max(0, (int) ($a['wait_seconds'] ?? 0)));

    $r = mb_read($boardName, $sinceId, $limit, true);
    $createdNow = $r['created_now'];

    if ($sinceId !== null && $wait > 0 && !$r['messages']) {
        @set_time_limit(MAX_WAIT + 15);
        $deadline = time() + $wait;
        while (!$r['messages'] && time() < $deadline && !connection_aborted()) {
            sleep(1);
            $r = mb_read($boardName, $sinceId, $limit, true);
        }
    }

    $lines = [];
    $lines[] = "Pad \"{$r['slug']}\": {$r['total']} message" . ($r['total'] === 1 ? '' : 's') . ' total. latest_id: ' . $r['latest_id'];
    if ($createdNow) {
        $lines[] = 'This pad did not exist, so it was just created. It is empty.';
    }

    if (!$r['messages']) {
        if ($sinceId !== null && !$createdNow) {
            $lines[] = "No new messages since #{$sinceId}" . ($wait ? " (waited {$wait}s)" : '') . '.';
        } elseif (!$createdNow) {
            $lines[] = 'No messages yet.';
        }
    } else {
        $n = count($r['messages']);
        if ($sinceId !== null) {
            $lines[] = "{$n} new message" . ($n === 1 ? '' : 's') . " since #{$sinceId}:";
        } elseif ($n < $r['total']) {
            $lines[] = "Showing the latest {$n}:";
        }
        foreach ($r['messages'] as $m) {
            $lines[] = '';
            $lines[] = "--- #{$m['id']} from {$m['author']} at " . mb_time($m['ts']) . ' ---';
            $lines[] = $m['body'];
        }
    }

    $lines[] = '';
    $lines[] = "Next time, call read_board with since_id={$r['latest_id']} to get only newer messages.";
    return tool_text(implode("\n", $lines));
}

function tool_list(): array
{
    $boards = mb_list();
    if (!$boards) {
        return tool_text('There are no pads yet. Post to any pad name to create one.');
    }
    $lines = [count($boards) . ' active pad' . (count($boards) === 1 ? '' : 's') . ':'];
    foreach ($boards as $b) {
        $who = $b['authors'] ? implode(', ', $b['authors']) : 'nobody yet';
        $lines[] = "- {$b['slug']}: {$b['total']} messages (latest_id {$b['latest_id']}), participants: {$who}, last activity " . mb_time($b['last_activity']);
    }
    return tool_text(implode("\n", $lines));
}
