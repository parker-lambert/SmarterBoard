const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');
const { test } = require('node:test');
const source = fs.readFileSync(path.join(__dirname, '../assets/boards.js'), 'utf8');
const flush = () => new Promise(resolve => setImmediate(resolve));
const reply = (data, status = 200) => ({ ok: status === 200, status, json: async () => data });
const pad = (name = 'Launch review') => ({ slug: 'launch-review', name, total: 0, messages: [], latest_id: 0, expires: Date.now() / 1000 + 86400 });

async function app() {
  const elements = new Map();
  const listeners = {};
  function element(id) {
    if (!elements.has(id)) elements.set(id, {
      innerHTML: '', value: '', style: {}, handlers: {}, disabled: false,
      contains: () => false, matches: () => false,
      querySelector: selector => selector === 'button' ? element('post-button') : null,
      addEventListener(type, handler) { this.handlers[type] = handler; },
      focus() {}, requestSubmit() {},
    });
    return elements.get(id);
  }
  const context = {
    URL, URLSearchParams, Date, setInterval() {}, setTimeout() {},
    location: { href: 'https://example.test/mashpad/boards.php', search: '', hash: '' },
    fetch: async () => reply({ boards: [], ttl: 2592000 }),
    document: { title: '', hidden: false, activeElement: null, body: { scrollHeight: 900 },
      getElementById: element, querySelectorAll: () => [],
      addEventListener(type, handler) { listeners[type] = handler; } },
    addEventListener() {}, scrollTo() {}, innerHeight: 600, scrollY: 0,
  };
  context.window = context;
  vm.createContext(context);
  vm.runInContext(source + '\nthis.app = {state, route, pollBoard, boardListHTML, refreshPadNavigation, bindComposer};', context);
  await flush();
  return { context, api: context.app, element, listeners };
}

test('pad row has independent, escaped conversation and settings links', async () => {
  const { api } = await app();
  api.state.boards = [{ slug: 'launch-review', name: 'A "name" <img src=x>', total: 2, last_activity: 0, authors: [] }];
  api.state.slug = 'launch-review'; api.state.view = 'settings';
  const html = api.boardListHTML();
  assert.equal((html.match(/<a\s/g) || []).length, 2);
  assert.match(html, /<\/a>\s*<a class="pad-settings-link"/);
  assert.match(html, /href="boards.php\?b=launch-review&amp;view=settings"/);
  assert.match(html, /aria-label="Settings for A &quot;name&quot; &lt;img src=x&gt;"/);
  assert.equal((html.match(/aria-current="page"/g) || []).length, 1);
  assert.doesNotMatch(html, /<img/);
});

test('direct settings route reads the selected pad without posting or polling messages', async () => {
  const { api, context, element } = await app();
  const requests = [];
  context.fetch = async (url, options) => { requests.push({ url, options }); return reply(pad()); };
  context.location.search = '?b=launch-review&view=settings';
  await api.route();
  await api.pollBoard();
  assert.equal(requests.length, 1);
  assert.equal(requests[0].options, undefined);
  assert.match(requests[0].url, /action=read&board=launch-review/);
  assert.match(element('main').innerHTML, /Settings are coming soon/);
  assert.match(element('main').innerHTML, /Back to pad/);
  assert.doesNotMatch(element('main').innerHTML, /<form|<input|<button/);
  assert.equal(api.state.view, 'settings');
});

test('late conversation response cannot replace settings for the same pad', async () => {
  const { api, context, element } = await app();
  let finishRead;
  context.fetch = () => new Promise(resolve => { finishRead = resolve; });
  context.location.search = '?b=launch-review';
  const reading = api.route();
  context.fetch = async () => reply(pad());
  context.location.search = '?b=launch-review&view=settings';
  await api.route();
  const settings = element('main').innerHTML;
  finishRead(reply(pad()));
  await reading;
  assert.equal(element('main').innerHTML, settings);
});

test('late settings response cannot replace home after Back navigation', async () => {
  const { api, context, element } = await app();
  let finishRead;
  context.fetch = () => new Promise(resolve => { finishRead = resolve; });
  context.location.search = '?b=launch-review&view=settings';
  const reading = api.route();
  context.location.search = '';
  await api.route();
  finishRead(reply(pad()));
  await reading;
  assert.match(element('main').innerHTML, /Your pads/);
  assert.equal(api.state.view, 'home');
});

test('missing pad settings show a recovery link without fabricating a pad', async () => {
  const { api, context, element } = await app();
  context.fetch = async () => reply({ error: 'not_found' }, 404);
  context.location.search = '?b=launch-review&view=settings';
  await api.route();
  assert.match(element('main').innerHTML, /This pad no longer exists/);
  assert.match(element('main').innerHTML, />All pads<\/a>/);
});

test('sidebar refresh preserves a focused settings link', async () => {
  const { api, context, element } = await app();
  const nav = element('board-nav'); nav.innerHTML = 'focused navigation'; nav.contains = () => true;
  context.document.querySelectorAll = () => [nav];
  api.refreshPadNavigation();
  assert.equal(nav.innerHTML, 'focused navigation');
  nav.contains = () => false;
  api.refreshPadNavigation();
  assert.match(nav.innerHTML, /No pads yet/);
});

test('keyboard posting prevents a newline and does not submit again while pending', async () => {
  const { api, element } = await app();
  let submissions = 0, prevented = 0;
  element('composer').requestSubmit = () => submissions++;
  api.bindComposer('launch-review');
  const event = { key: 'Enter', ctrlKey: true, preventDefault: () => prevented++ };
  element('c-body').handlers.keydown(event);
  element('post-button').disabled = true;
  element('c-body').handlers.keydown(event);
  assert.equal(submissions, 1);
  assert.equal(prevented, 2);
});
