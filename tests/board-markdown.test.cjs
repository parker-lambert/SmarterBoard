const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');
const { test } = require('node:test');
const root = path.resolve(__dirname, '..');
const sandbox = {};
sandbox.window = sandbox;
vm.createContext(sandbox);
vm.runInContext(fs.readFileSync(path.join(root, 'assets/vendor/markdown-it.min.js'), 'utf8'), sandbox);
vm.runInContext(fs.readFileSync(path.join(root, 'assets/board-markdown.js'), 'utf8'), sandbox);
const render = sandbox.renderMessage;

test('reports render as headings, paragraphs, lists, quotations and tables', () => {
  const output = render('# Review\n\nFirst paragraph.\n\nSecond paragraph.\n\n- One\n- Two\n\n1. First\n2. Second\n\n> A quoted note\n\n| Check | Status |\n| --- | --- |\n| Layout | Passed |');
  assert.match(output, /<h2>Review<\/h2>/);
  assert.doesNotMatch(output, /<h1[ >]/);
  for (const tag of ['p', 'ul', 'ol', 'blockquote', 'table', 'thead', 'tbody']) assert.match(output, new RegExp('<' + tag + '[ >]'));
  assert.match(output, /class="table-scroll"[^>]+tabindex="0"/);
});

test('single newlines and code whitespace are preserved', () => {
  assert.match(render('First line\nSecond line'), /First line<br>\nSecond line/);
  const output = render('```html\n<div>\n  exact spacing\n</div>\n```');
  assert.match(output, /<pre tabindex="0" aria-label="Code block">/);
  assert.match(output, /&lt;div&gt;\n  exact spacing\n&lt;\/div&gt;/);
  assert.match(render('    indented code'), /<pre tabindex="0" aria-label="Code block">/);
});

test('named links and bare URLs render without executing embedded markup', () => {
  const output = render('[Review](https://example.com/a?x=1&y=2)\n\nhttps://example.com/status');
  assert.match(output, /href="https:\/\/example.com\/a\?x=1&amp;y=2"/);
  assert.match(output, /target="_blank" rel="noopener noreferrer"/);
  assert.match(output, />Review<\/a>/);
});

test('HTML, event handlers, dangerous links and remote images stay inert', () => {
  for (const input of [
    '<script>alert(1)</script>', '<img src=x onerror=alert(1)>', '<svg onload=alert(1)>',
    '[x](javascript:alert(1))', '[x](jav&#x61;script:alert(1))',
    '[x](vbscript:msgbox(1))', '[x](file:///etc/passwd)',
    '[x](data:text/html;base64,PHNjcmlwdD4=)',
    '![pixel](https://example.com/tracker.png)',
    '[x](https://example.com/"onmouseover="alert(1))',
  ]) {
    const output = render(input);
    assert.doesNotMatch(output, /<(?:script|img|svg|iframe|object)\b/i, input);
    assert.doesNotMatch(output, /href="(?:javascript|vbscript|file|data):/i, input);
    assert.doesNotMatch(output, /<[^>]+\son(?:load|error|mouseover)=/i, input);
  }
});

test('deep headings remain inside the message hierarchy', () => {
  assert.match(render('## Section\n\n###### Detail'), /<h3>Section<\/h3>/);
  assert.match(render('###### Detail'), /<h6>Detail<\/h6>/);
});
