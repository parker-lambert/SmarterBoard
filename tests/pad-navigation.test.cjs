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
      focus() {}, requestSubmit() {}, setAttribute(name, value) { this[name] = value; },
    });
    return elements.get(id);
  }
  const context = {
    URL, URLSearchParams, Date, setInterval() {}, setTimeout() {},
    location: { href: 'https://example.test/mashpad/pads/', search: '', hash: '' },
    fetch: async () => reply({ boards: [], ttl: 2592000 }),
    document: { title: '', hidden: false, activeElement: null, body: { scrollHeight: 900 },
      getElementById: element, querySelectorAll: () => [],
      addEventListener(type, handler) { listeners[type] = handler; } },
    addEventListener() {}, scrollTo() {}, innerHeight: 600, scrollY: 0,
  };
  context.window = context;
  vm.createContext(context);
  vm.runInContext(source + '\nthis.app = {state, route, pollBoard, boardListHTML, refreshPadNavigation, bindComposer, unseenMessages, accountHTML, applyAccount, loadMe};', context);
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
  assert.match(html, /href="https:\/\/example\.test\/mashpad\/pads\/\?b=launch-review&amp;view=settings"/);
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

test('a message id is rendered at most once, however often it arrives', async () => {
  const { api } = await app();
  const m = (id) => ({ id, author: 'muse', body: 'hi', ts: 1 });
  const ids = list => Array.from(api.unseenMessages(list), x => x.id);
  assert.deepEqual(ids([m(44), m(45)]), [44, 45]);
  assert.deepEqual(ids([m(45), m(45), m(46)]), [46]);
  assert.equal(api.unseenMessages([m(44), m(45), m(46)]).length, 0);
});

function fakeTranscript(context, element) {
  const t = element('transcript');
  t.children = [];
  Object.defineProperty(t, 'lastElementChild', { get() { return this.children[this.children.length - 1] || null; } });
  t.appendChild = child => { t.children.push(child); return child; };
  context.renderMessage = context.renderMessage || (text => text);
  context.document.createElement = () => {
    const g = { dataset: {}, style: { setProperty() {} }, innerHTML: '', messages: 0 };
    g.insertAdjacentHTML = () => { g.messages++; };
    return g;
  };
  return () => t.children.reduce((n, g) => n + g.messages, 0);
}

test('overlapping polls send one request; a poll racing the initial load adds nothing twice', async () => {
  const { api, context, element } = await app();
  const drawn = fakeTranscript(context, element);
  const msgs = [{ id: 1, author: 'muse', body: 'a', ts: 1 }, { id: 2, author: 'muse', body: 'b', ts: 2 }];
  context.fetch = async () => reply({ ...pad(), messages: msgs, total: 2, latest_id: 2 });
  context.location.search = '?b=launch-review';
  await api.route();
  assert.equal(api.state.rendered.size, 2);
  let calls = 0, release;
  context.fetch = () => { calls++; return new Promise(r => { release = () => r(reply({ ...pad(), messages: msgs, total: 2, latest_id: 2 })); }); };
  const first = api.pollBoard();
  const second = api.pollBoard();
  const third = api.pollBoard();
  release();
  await Promise.all([first, second, third]);
  assert.equal(calls, 1);
  assert.equal(api.state.rendered.size, 2);
  assert.equal(drawn(), 2);
  assert.equal(api.state.latest, 2);
});

test('opening a pad starts a fresh rendered set', async () => {
  const { api, context, element } = await app();
  fakeTranscript(context, element);
  api.state.rendered.add(99);
  context.fetch = async () => reply(pad());
  context.location.search = '?b=launch-review';
  await api.route();
  assert.equal(api.state.rendered.has(99), false);
});

test('viewer links use the clean pads/ address from any page depth', async () => {
  const { api } = await app();
  api.state.boards = [{ slug: 'launch-review', name: 'Launch review', total: 1, last_activity: 0, authors: [] }];
  const html = api.boardListHTML();
  assert.doesNotMatch(html, /\.php/);
  assert.match(html, /href="https:\/\/example\.test\/mashpad\/pads\/\?b=launch-review"/);
});

test('participant links are independent, encoded, and show a single @ prefix', async () => {
  const { api } = await app();
  api.state.boards = [{ slug: 'launch-review', name: 'Launch', total: 2, last_activity: 0, authors: ['muse', '@tester', '<img " &'] }];
  const html = api.boardListHTML();
  assert.match(html, /<\/a>\s*<div class="who-list">/);
  assert.match(html, /view=agent&amp;agent=muse[^>]*>@muse<\/a>/);
  assert.match(html, /agent=%40tester[^>]*>@tester<\/a>/);
  assert.doesNotMatch(html, /@@tester|<img/);
  assert.match(html, /@&lt;img &quot; &amp;/);
});

test('agent route displays escaped name, real pad and clearly labeled placeholders', async () => {
  const { api, context, element } = await app();
  const name = '<img onerror="bad()">';
  const requests = [];
  context.fetch = async (url, options) => { requests.push({url, options}); return reply({...pad(), messages: [{author:name}]}); };
  context.location.search = '?b=launch-review&view=agent&agent=' + encodeURIComponent(name);
  await api.route();
  await api.pollBoard();
  const html = element('main').innerHTML;
  assert.equal(requests.length, 1);
  assert.equal(requests[0].options, undefined);
  assert.match(html, /Profile preview/);
  assert.match(html, /placeholder/);
  assert.match(html, /@&lt;img onerror=&quot;bad\(\)&quot;&gt;/);
  assert.match(html, /Launch review/);
  assert.doesNotMatch(html, /<img|<input|<form/);
  assert.equal(api.state.view, 'agent');
});

test('late pad response cannot overwrite an agent profile', async () => {
  const { api, context, element } = await app();
  let finish;
  context.fetch = () => new Promise(resolve => { finish = resolve; });
  context.location.search = '?b=launch-review';
  const reading = api.route();
  context.fetch = async () => reply({...pad(), messages:[{author:'muse'}]});
  context.location.search = '?b=launch-review&view=agent&agent=muse';
  await api.route();
  finish(reply(pad())); await reading;
  assert.match(element('main').innerHTML, /<h1>@muse<\/h1>/);
});

test('unknown participant gets a recovery page instead of a fabricated profile', async () => {
  const { api, context, element } = await app();
  context.fetch = async () => reply(pad());
  context.location.search = '?b=launch-review&view=agent&agent=unknown';
  await api.route();
  assert.match(element('main').innerHTML, /This name was not found/);
  assert.doesNotMatch(element('main').innerHTML, /Project assistant/);
});

test('signed out with accounts enabled: sign-in and create-account links that return here', async () => {
  const { api, context } = await app();
  context.fetch = async () => reply({ signed_in: false, accounts: true });
  await api.loadMe();
  const html = api.accountHTML();
  assert.match(html, /href="https:\/\/example\.test\/mashpad\/signin\/\?next=/);
  assert.match(html, /href="https:\/\/example\.test\/mashpad\/register\/\?next=/);
  assert.equal(api.state.user, null);
});

test('no account links before the site has accounts', async () => {
  const { api, context } = await app();
  context.fetch = async () => reply({ signed_in: false, accounts: false });
  await api.loadMe();
  assert.equal(api.accountHTML(), '');
});

test('signed in: account shown, composer name fixed to the handle, handle escaped', async () => {
  const { api, context, element } = await app();
  context.fetch = async url => /action=me/.test(url) ? reply({ signed_in: true, handle: 'dy<lan', display_name: 'Dylan' }) : reply(pad());
  await api.loadMe();
  assert.match(api.accountHTML(), /Signed in as <strong>@dy&lt;lan<\/strong>/);
  assert.match(api.accountHTML(), /signout\//);
  context.location.search = '?b=launch-review';
  await api.route();
  assert.match(element('main').innerHTML, /id="c-author"[^>]*value="@dy&lt;lan" readonly/);
});

test('signed-in posts send no author name', async () => {
  const { api, context, element } = await app();
  const posts = [];
  context.fetch = async (url, opts) => {
    if (/action=me/.test(url)) return reply({ signed_in: true, handle: 'dylan', display_name: 'Dylan' });
    if (/action=post/.test(url)) { posts.push(JSON.parse(opts.body)); return reply({ ok: true }); }
    return reply(pad());
  };
  await api.loadMe();
  api.bindComposer('launch-review');
  element('c-author').value = 'someone-else';
  element('c-body').value = 'hello';
  await element('composer').handlers.submit({ preventDefault() {} });
  assert.equal(posts.length, 1);
  assert.equal(posts[0].author, '');
  assert.equal(posts[0].message, 'hello');
});

test('pad settings offer Markdown and JSON export of the whole pad', async () => {
  const { api, context, element } = await app();
  context.fetch = async () => reply(pad());
  context.location.search = '?b=launch-review&view=settings';
  await api.route();
  const html = element('main').innerHTML;
  assert.match(html, /href="https:\/\/example\.test\/mashpad\/api\.php\?action=export&amp;board=launch-review" download>Markdown/);
  assert.match(html, /action=export&amp;board=launch-review&amp;format=json" download>JSON/);
});
