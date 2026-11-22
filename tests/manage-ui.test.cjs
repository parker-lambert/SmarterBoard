const { test } = require('node:test');
const assert = require('node:assert/strict');
const vm = require('node:vm');
const fs = require('node:fs');
const source = fs.readFileSync(require('node:path').join(__dirname, '../assets/manage.js'), 'utf8');
function setup({ confirm = true, writeText = async () => {} } = {}) {
  const handlers = {};
  const status = { textContent: '' };
  const invitation = { textContent: 'Invitation with a test-only token' };
  const form = { dataset: { confirm: 'Disconnect agent everywhere?' }, addEventListener: (name, fn) => handlers.submit = fn };
  const button = { hidden: true, dataset: { copy: 'agent-invitation' }, parentElement: { querySelector: () => status }, addEventListener: (name, fn) => handlers.click = fn };
  let selected = false;
  vm.runInNewContext(source, {
    document: { querySelectorAll: selector => selector.startsWith('form') ? [form] : [button], getElementById: () => invitation, createRange: () => ({ selectNodeContents(node) { assert.equal(node, invitation); } }) },
    navigator: { clipboard: { writeText } },
    window: { confirm: () => confirm, getSelection: () => ({ removeAllRanges() {}, addRange() { selected = true; } }) },
  });
  return { handlers, button, status, invitation, selected: () => selected };
}
test('cancelling a management confirmation prevents the destructive submit; accepting allows it', () => {
  for (const accepted of [false, true]) {
    let prevented = false;
    setup({ confirm: accepted }).handlers.submit({ preventDefault() { prevented = true; } });
    assert.equal(prevented, !accepted);
  }
});
test('copy invitation reports success only after copying and offers selection when clipboard fails', async () => {
  let copied;
  const ok = setup({ writeText: async text => { copied = text; } });
  assert.equal(ok.button.hidden, false);
  await ok.handlers.click();
  assert.equal(copied, ok.invitation.textContent);
  assert.match(ok.status.textContent, /copied/);
  const denied = setup({ writeText: async () => { throw Error('denied'); } });
  await denied.handlers.click();
  assert.equal(denied.selected(), true);
  assert.match(denied.status.textContent, /Automatic copying is unavailable/);
});
