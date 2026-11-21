<?php
/** Pad export: Markdown is a faithful archive. No database needed. Run: php tests/export.test.php */
declare(strict_types=1);
require dirname(__DIR__) . '/lib.php';

$pass = 0; $fail = 0;
function check(string $name, bool $ok, string $detail = ''): void {
    global $pass, $fail; $ok ? $pass++ : $fail++;
    echo ($ok ? 'ok   ' : 'FAIL ') . $name . ($ok || $detail === '' ? '' : "  ($detail)") . "\n";
}
$slug = 'zz-export-test-' . getmypid();
register_shutdown_function(fn () => @unlink(mb_path($slug)));

$tricky = "# A heading inside a post\n\n```php\necho \"<b>\";\n```\n\nUnicode: Zoë 🌱, trailing spaces kept inside.  \nEnd.";
mb_post($slug, 'muse', 'First post.');
mb_post($slug, '@reviewer', $tricky);
$r  = mb_read($slug, null, MB_MAX_MESSAGES, false);
$md = mb_export_markdown($r);

check('title and summary line', str_starts_with($md, "# $slug\n\nExported from Mashpad on ") && str_contains($md, "2 messages (#1 to #2)"));
check('each message headed with id, @author and UTC time', (bool) preg_match('/^## #1 from @muse, \d{4}-\d\d-\d\d \d\d:\d\d UTC$/m', $md));
check('author @ not doubled', str_contains($md, '## #2 from @reviewer,') && !str_contains($md, '@@'));
check('bodies verbatim, including code, HTML and unicode', str_contains($md, rtrim($tricky)));
check('messages separated by rules', substr_count($md, "\n---\n") === 2);
check('ends with one newline', str_ends_with($md, "End.\n") && !str_ends_with($md, "\n\n"));
$empty = mb_export_markdown(['name' => 'Empty', 'slug' => 'empty', 'messages' => [], 'latest_id' => 0]);
check('empty pad exports cleanly', str_contains($empty, '0 messages.') && !str_contains($empty, '---'));

echo "\n$pass passed, $fail failed\n";
exit($fail ? 1 : 0);
