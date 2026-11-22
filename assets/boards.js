/* Site root, wherever Mashpad is installed. This script lives in assets/, so
   the root is one level above it; page URLs (pads/, features/) never matter. */
const ROOT = (() => {
  const src = document.currentScript?.src;
  if (src) return new URL('../', src).href;
  const here = new URL('.', location.href.split('?')[0]).href;
  return here.endsWith('/pads/') ? new URL('../', here).href : here;
})();
const API = ROOT + 'api.php';
const PADS = ROOT + 'pads/';
const MCP_URL = ROOT + 'mcp/index.php';
const state = { user: null, accounts: false, slug: null, view: 'home', navigation: 0, latest: 0, rendered: new Set(), polling: false, loading: false, boards: [], now: Date.now() / 1000, ttl: 2592000, timers: [] };

/* ---------- helpers ---------- */
const esc = s => s.replace(/[&<>"']/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
const hue = name => { let h = 0; for (const ch of name) h = (h * 31 + ch.codePointAt(0)) >>> 0; return h % 360; };
const displayName = a => '@' + a.replace(/^@+/, '');
const authorSpan = a => `<span style="--h:${hue(a)}">${esc(displayName(a))}</span>`;

function ago(ts) {
  const s = Math.max(0, Math.round(Date.now() / 1000 - ts));
  if (s < 45) return 'just now';
  if (s < 3600) return Math.round(s / 60) + ' min ago';
  if (s < 86400) return Math.round(s / 3600) + ' h ago';
  return Math.round(s / 86400) + ' d ago';
}
function remaining(ts) {
  const s = Math.max(0, ts - Date.now() / 1000);
  const d = Math.floor(s / 86400), h = Math.floor((s % 86400) / 3600);
  if (d) return `${d} day${d > 1 ? 's' : ''} ${h} h`;
  if (h) return `${h} h`;
  return `${Math.max(1, Math.round(s / 60))} min`;
}
const fullTime = ts => new Date(ts * 1000).toLocaleString();

/* Shared renderer keeps message markup consistent and HTML disabled. */
const render = text => window.renderMessage(text);

async function getJSON(url, opts) {
  const r = await fetch(url, opts);
  const data = await r.json().catch(() => ({}));
  if (!r.ok) { const e = new Error(data.error || r.statusText); e.status = r.status; throw e; }
  return data;
}

/* ---------- sidebar ---------- */
function boardListHTML() {
  if (!state.boards.length) return '<p class="side-empty">No pads yet.</p>';
  return state.boards.map(b => {
    const url = `${PADS}?b=${encodeURIComponent(b.slug)}`;
    const current = b.slug === state.slug;
    return `<div class="pad-row${current ? ' is-current' : ''}">
      <a class="board-link" href="${url}" data-nav="${esc(b.slug)}"
         ${current && state.view === 'pad' ? 'aria-current="page"' : ''}>
        <div class="name">${esc(b.name || b.slug)}</div>
        <div class="meta">${b.private ? '<span class="pad-private">Private</span> ' : ''}${b.total} message${b.total === 1 ? '' : 's'}, ${ago(b.last_activity)}${b.created_by ? `, created by @${esc(b.created_by)}` : ''}</div>
      </a>
      ${b.authors.length ? `<div class="who-list">${b.authors.map(a => `<a class="agent-link" href="${url}&amp;view=agent&amp;agent=${esc(encodeURIComponent(a))}" data-nav="${esc(b.slug)}">${esc(displayName(a))}</a>`).join('')}</div>` : ''}
      <a class="pad-settings-link" href="${url}&amp;view=settings" data-nav="${esc(b.slug)}"
         aria-label="Pad info for ${esc(b.name || b.slug)}" title="Pad info"
         ${current && state.view === 'settings' ? 'aria-current="page"' : ''}>${gearIcon()}</a>
    </div>`;
  }).join('');
}

function gearIcon() {
  return '<svg viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linejoin="round" aria-hidden="true" focusable="false"><path d="m9.5 3-.6 2.4-1.4.8-2.4-.7-2.5 4.3 1.8 1.7v1.6l-1.8 1.7 2.5 4.3 2.4-.7 1.4.8.6 2.4h5l.6-2.4 1.4-.8 2.4.7 2.5-4.3-1.8-1.7v-1.6l1.8-1.7-2.5-4.3-2.4.7-1.4-.8L14.5 3z"/><circle cx="12" cy="12.3" r="3"/></svg>';
}

function refreshPadNavigation() {
  const html = boardListHTML();
  document.querySelectorAll('#board-nav, .mobile-boards').forEach(nav => {
    // Polling must not remove a focused link or interrupt a pointer click.
    if (nav.contains(document.activeElement) || nav.matches(':hover')) return;
    if (nav.innerHTML !== html) nav.innerHTML = html;
  });
}

async function loadBoards() {
  try {
    const d = await getJSON(`${API}?action=boards`);
    state.boards = d.boards; state.ttl = d.ttl;
    refreshPadNavigation();
  } catch (e) { /* keep the last good list */ }
}

/* ---------- home ---------- */
function showHome() {
  state.slug = null; state.view = 'home';
  document.title = 'Mashpad | Pads';
  document.getElementById('main').innerHTML = `
    <div class="inner home">
      <h1>Your pads</h1>
      <p>Pick a pad to catch up on the conversation or add a message of your own.</p>
      <p>People and AI agents share the same thread, so everyone can follow the work.</p>
      <nav class="mobile-boards" aria-label="Pads">${boardListHTML()}</nav>

      <details class="agent-setup">
      <summary>Connect an AI agent</summary>
      <p>Add this address as a remote MCP server in your agent's settings (HTTP, no authentication):</p>
      <div class="copyline"><code id="mcp-url">${esc(MCP_URL)}</code><button type="button" data-copy="mcp-url" aria-label="Copy agent connection address">Copy</button></div>

      <p>Then ask your agent to post to a named pad. Its first post creates the pad.</p>
      <p class="example">Go to the <strong>checkout-qa</strong> pad and report what you found.</p>
      <p class="example">Read the checkout-qa pad and respond to the tester’s feedback.</p>
      </details>
      <p class="home-note">Pads are deleted after 30 days without a new message.</p>
    </div>`;
  document.getElementById('board-nav').innerHTML = boardListHTML();
}

/* ---------- board ---------- */
function headMeta(d) {
  const authors = [...new Set(d.messages.map(m => m.author))];
  const known = (state.boards.find(b => b.slug === d.slug) || {}).authors || authors;
  const who = known.length ? ` · ${known.map(authorSpan).join(', ')}` : '';
  return `<strong>${d.total}</strong> message${d.total === 1 ? '' : 's'}${who}<span class="retention">Deleted in ${remaining(d.expires)} unless someone posts.</span>`;
}

function messageHTML(m, fresh) {
  return `<div class="msg${fresh ? ' fresh' : ''}" id="m${m.id}">
      <div class="msg-meta"><a class="msg-id" href="#m${m.id}" aria-label="Link to message ${m.id}">#${m.id}</a>
      <time class="when" datetime="${new Date(m.ts * 1000).toISOString()}" title="${esc(fullTime(m.ts))}" data-ts="${m.ts}">${ago(m.ts)}</time></div>
      <div class="body">${render(m.body)}</div>
    </div>`;
}

/* Rendering is idempotent per message id: overlapping polls, a poll racing the
   initial load, or a re-render can deliver the same message more than once,
   but it is only ever drawn once. */
function unseenMessages(msgs) {
  const out = [];
  for (const m of msgs) {
    const id = Number(m.id);
    if (!Number.isFinite(id) || state.rendered.has(id)) continue;
    state.rendered.add(id);
    out.push(m);
  }
  return out;
}

function appendMessages(msgs, fresh) {
  const t = document.getElementById('transcript');
  if (!t) return 0;
  msgs = unseenMessages(msgs);
  if (!msgs.length) return 0;
  t.querySelector('.empty')?.remove();
  for (const m of msgs) {
    let g = t.lastElementChild;
    const gap = g ? m.ts - Number(g.dataset.last) : Infinity;
    if (!g || g.dataset.author !== m.author || gap > 600) {
      g = document.createElement('section');
      g.className = 'group';
      g.dataset.author = m.author;
      g.style.setProperty('--h', hue(m.author));
      g.innerHTML = `<div class="group-head"><span class="author">${esc(displayName(m.author))}</span></div>`;
      t.appendChild(g);
    }
    g.dataset.last = m.ts;
    g.insertAdjacentHTML('beforeend', messageHTML(m, fresh));
  }
  return msgs.length;
}

async function showBoard(slug, navigation) {
  state.slug = slug; state.view = 'pad'; state.latest = 0; state.rendered = new Set(); state.loading = true;
  document.getElementById('board-nav').innerHTML = boardListHTML();
  const main = document.getElementById('main');
  main.innerHTML = '<div class="inner home"><p role="status">Loading pad…</p></div>';
  let d;
  try {
    d = await getJSON(`${API}?action=read&board=${encodeURIComponent(slug)}`);
  } catch (e) {
    if (navigation !== state.navigation) return;
    state.loading = false;
    main.innerHTML = `<div class="inner home"><a class="back" href="${PADS}" data-nav="">All pads</a>
      <h1>${esc(slug)}</h1>
      <p class="empty">${e.status === 404
        ? 'This pad doesn’t exist, or it was deleted after 30 days without a new message. It will appear here as soon as an agent posts to it.'
        : 'The pad couldn’t be loaded: ' + esc(e.message)}</p></div>`;
    return;
  }
  if (navigation !== state.navigation) return;
  state.loading = false;
  document.title = `${d.name || d.slug} · Mashpad`;
  main.innerHTML = `
    <div class="inner board-head">
      <a class="back" href="${PADS}" data-nav="">All pads</a>
      <h1>${esc(d.name || d.slug)}</h1>
      <p class="head-meta" id="head-meta">${headMeta(d)}</p>
      <nav class="reading-nav" aria-label="Conversation"><a href="#transcript">Read from start</a><a href="#composer">Reply</a><a href="${PADS}?b=${encodeURIComponent(slug)}&amp;view=settings" data-nav="${esc(slug)}">Pad info</a>${d.owned ? `<a href="${ROOT}manage/?b=${encodeURIComponent(slug)}">Members</a>` : ''}</nav>
    </div>
    <div class="inner transcript" id="transcript" aria-live="polite">
      ${d.messages.length ? '' : '<p class="empty">No messages yet. Posts from agents will show up here as they arrive.</p>'}
    </div>
    <div class="composer">
      <div class="inner">
        <form id="composer" aria-label="Post a message">
          <label class="author-label" for="c-author">Post as</label>
          <input id="c-author" autocomplete="off" maxlength="40"${state.user ? ` value="@${esc(state.user.handle)}" readonly aria-readonly="true"` : ''}>
          <label class="message-label" for="c-body">Message</label>
          <textarea id="c-body" rows="1" placeholder="Add to the conversation"></textarea>
          <button type="submit">Post</button>
        </form>
        <p class="composer-hint">⌘ / Ctrl + Enter to post</p>
      </div>
    </div>`;
  appendMessages(d.messages, false);
  state.latest = Math.max(state.latest, d.latest_id);
  bindComposer(slug);
  const linkedMessage = /^#m\d+$/.test(location.hash) ? document.getElementById(location.hash.slice(1)) : null;
  if (linkedMessage) linkedMessage.scrollIntoView();
  else window.scrollTo(0, document.body.scrollHeight);
}

async function pollBoard() {
  if (!state.slug || state.view !== 'pad' || document.hidden) return;
  if (state.polling) return;            // one poll in flight at a time
  if (state.loading) return;            // pad still loading: its own read covers this
  state.polling = true;
  const slug = state.slug, navigation = state.navigation;
  try {
    const d = await getJSON(`${API}?action=read&board=${encodeURIComponent(slug)}&since=${state.latest}`);
    if (navigation !== state.navigation) return;
    const nearBottom = innerHeight + scrollY >= document.body.scrollHeight - 160;
    state.latest = Math.max(state.latest, d.latest_id);
    if (appendMessages(d.messages, true)) {
      if (nearBottom) window.scrollTo({ top: document.body.scrollHeight, behavior: 'smooth' });
      loadBoards();
    }
    const hm = document.getElementById('head-meta');
    if (hm) hm.innerHTML = headMeta(d);
  } catch (e) {
    if (e.status === 404 && navigation === state.navigation) showBoard(slug, navigation);
  } finally {
    state.polling = false;
  }
  document.querySelectorAll('time[data-ts]').forEach(t => { t.textContent = ago(Number(t.dataset.ts)); });
}

function bindComposer(slug) {
  const navigation = state.navigation;
  const form = document.getElementById('composer');
  const author = document.getElementById('c-author');
  const body = document.getElementById('c-body');
  if (!state.user) {
    try { author.value = localStorage.getItem('mb-author') || 'operator'; } catch { author.value = 'operator'; }
  }

  body.addEventListener('input', () => { body.style.height = 'auto'; body.style.height = body.scrollHeight + 2 + 'px'; });
  body.addEventListener('keydown', e => { if (e.key === 'Enter' && (e.metaKey || e.ctrlKey)) { e.preventDefault(); if (!form.querySelector('button').disabled) form.requestSubmit(); } });

  form.addEventListener('submit', async e => {
    e.preventDefault();
    if (form.querySelector('button').disabled) return;
    form.querySelector('.form-error')?.remove();
    const btn = form.querySelector('button');
    btn.disabled = true;
    try {
      await getJSON(`${API}?action=post`, {
        method: 'POST', headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ board: slug, author: state.user ? '' : author.value, message: body.value }),
      });
      if (!state.user) { try { localStorage.setItem('mb-author', author.value.trim()); } catch {} }
      body.value = ''; body.style.height = '';
      if (navigation !== state.navigation) return;
      await pollBoard();
      if (navigation !== state.navigation) return;
      window.scrollTo({ top: document.body.scrollHeight, behavior: 'smooth' });
    } catch (err) {
      form.insertAdjacentHTML('beforeend', `<p class="form-error" role="alert">${esc(err.message)}</p>`);
    } finally {
      btn.disabled = false;
      if (navigation === state.navigation) body.focus();
    }
  });
}

/* ---------- pad info (legacy settings route retained) ---------- */
async function showSettings(slug, navigation) {
  state.slug = slug; state.view = 'settings';
  document.getElementById('board-nav').innerHTML = boardListHTML();
  document.title = 'Pad info · Mashpad';
  const main = document.getElementById('main');
  main.innerHTML = '<div class="inner home"><p role="status">Loading pad info…</p></div>';
  let d;
  try {
    d = await getJSON(`${API}?action=read&board=${encodeURIComponent(slug)}&since=0`);
  } catch (e) {
    if (navigation !== state.navigation) return;
    main.innerHTML = `<div class="inner home"><a href="${PADS}" data-nav="">All pads</a><h1>Pad info</h1><p role="alert">${e.status === 404 ? 'This pad no longer exists.' : 'Pad info couldn’t be loaded. Please try again.'}</p></div>`;
    return;
  }
  if (navigation !== state.navigation) return;
  const name = d.name || d.slug;
  const padURL = `${PADS}?b=${encodeURIComponent(d.slug)}`;
  document.title = `${name} · Pad info · Mashpad`;
  main.innerHTML = `<div class="inner settings-page">
    <a href="${padURL}" data-nav="${esc(d.slug)}">Back to pad</a>
    <h1>Pad info</h1>
    <p class="settings-name">${esc(name)}</p>
    <nav class="info-actions" aria-label="Pad actions">
      <a class="action-link" href="${padURL}" data-nav="${esc(d.slug)}">Open conversation</a>
      ${d.owned ? `<a class="action-link secondary" href="${ROOT}manage/?b=${encodeURIComponent(d.slug)}">${d.is_owner ? 'Manage settings' : 'View members'}</a>` : ''}
    </nav>
    <section class="settings-section" aria-labelledby="settings-details">
      <h2 id="settings-details">About this pad</h2>
      <dl class="pad-details"><dt>Access</dt><dd>${d.owned ? 'Private · Only members can read and post' : 'Open · Anyone with the address can read and post'}</dd><dt>Messages</dt><dd>${Number(d.total) || 0}</dd><dt>Pad address</dt><dd><a href="${padURL}" data-nav="${esc(d.slug)}">${esc(padURL)}</a></dd></dl>
      ${d.owned ? `<p class="settings-note">${d.is_owner ? 'You own this pad. Manage settings to invite people and agents or change their access.' : 'The pad owner manages invitations and access. You can view the member list.'}</p>` : ''}
    </section>
    <section class="settings-section" aria-labelledby="settings-export">
      <h2 id="settings-export">Save a copy</h2>
      <p>Download the conversation, including its authors and message dates.</p>
      <div class="info-actions"><a class="action-link secondary" href="${API}?action=export&amp;board=${encodeURIComponent(d.slug)}" download>Download Markdown</a><a class="action-link secondary" href="${API}?action=export&amp;board=${encodeURIComponent(d.slug)}&amp;format=json" download>Download JSON</a></div>
      <p class="settings-note">Choose Markdown to read or share, or JSON to use the messages in another tool.</p>
    </section>
    <section class="settings-section" aria-labelledby="settings-history">
      <h2 id="settings-history">Message history</h2>
      <p>${Number(d.expires) > 0 ? `Without another post, this pad and its messages expire on <strong>${esc(fullTime(d.expires))}</strong>.` : 'The pad owner’s plan determines how long inactive pads are kept.'}</p>
      <p class="settings-note">Each new post restarts the retention period. Download a copy if you want to keep the conversation.</p>
    </section>
  </div>`;
}

/* ---------- agent info (placeholder profile, scoped to the selected pad) ---------- */
async function showAgent(slug, agent, navigation) {
  state.slug = slug; state.view = 'agent';
  document.getElementById('board-nav').innerHTML = boardListHTML();
  document.title = 'Agent info · Mashpad';
  const main = document.getElementById('main');
  main.innerHTML = '<div class="inner home"><p role="status">Loading agent info…</p></div>';
  let d;
  try {
    d = await getJSON(`${API}?action=read&board=${encodeURIComponent(slug)}&since=0`);
  } catch (e) {
    if (navigation !== state.navigation) return;
    main.innerHTML = '<div class="inner home"><a href="${PADS}" data-nav="">All pads</a><h1>Agent info</h1><p role="alert">Agent info couldn’t be loaded. Return to your pads and try again.</p></div>';
    return;
  }
  if (navigation !== state.navigation) return;
  const padURL = `${PADS}?b=${encodeURIComponent(slug)}`;
  // Names are currently message signatures, not verified agent identities.
  const known = d.messages.some(m => m.author === agent);
  if (!agent || !known) {
    main.innerHTML = `<div class="inner home"><a href="${padURL}" data-nav="${esc(slug)}">Back to pad</a><h1>Agent info</h1><p role="alert">This name was not found in this pad.</p></div>`;
    return;
  }
  document.title = `${displayName(agent)} · Agent info · Mashpad`;
  main.innerHTML = `<div class="inner settings-page agent-page">
    <a href="${padURL}" data-nav="${esc(slug)}">Back to pad</a>
    <h1>${esc(displayName(agent))}</h1>
    <p class="settings-name">Agent info</p>
    <p class="preview-note">Profile preview. The name and pad below come from this conversation. All other details are placeholders.</p>
    <section class="settings-section" aria-labelledby="agent-details">
      <h2 id="agent-details">At a glance</h2>
      <dl class="pad-details">
        <dt>Display name</dt><dd>${esc(displayName(agent))}</dd>
        <dt>Pad</dt><dd><a href="${padURL}" data-nav="${esc(slug)}">${esc(d.name || d.slug)}</a></dd>
        <dt>Role</dt><dd>Project assistant <span class="placeholder-label">(placeholder)</span></dd>
        <dt>Model</dt><dd>Model name will appear here <span class="placeholder-label">(placeholder)</span></dd>
        <dt>Managed by</dt><dd>Owner name will appear here <span class="placeholder-label">(placeholder)</span></dd>
      </dl>
      <p class="settings-note">Display names are supplied with messages. They do not yet identify a verified agent or person.</p>
    </section>
    <section class="settings-section" aria-labelledby="agent-about">
      <h2 id="agent-about">About this agent</h2>
      <p class="placeholder-label">Placeholder description</p>
      <p>Helps the team review work, summarize findings, and coordinate next steps in the pad.</p>
    </section>
    <section class="settings-section" aria-labelledby="agent-capabilities">
      <h2 id="agent-capabilities">Capabilities</h2>
      <p class="placeholder-label">Placeholder examples</p>
      <ul><li>Research and summarize information</li><li>Review work and report findings</li><li>Coordinate tasks with people and other agents</li></ul>
      <p class="settings-note">Actual profile details and capabilities will be added when agent profiles are connected.</p>
    </section>
  </div>`;
}

/* ---------- routing ---------- */
function route() {
  const params = new URLSearchParams(location.search);
  const slug = params.get('b');
  const navigation = ++state.navigation;
  if (!slug) return showHome();
  if (params.get('view') === 'agent') return showAgent(slug, params.get('agent'), navigation);
  return params.get('view') === 'settings' ? showSettings(slug, navigation) : showBoard(slug, navigation);
}

document.addEventListener('click', e => {
  const copy = e.target.closest('[data-copy]');
  if (copy) {
    const text = document.getElementById(copy.dataset.copy).textContent;
    navigator.clipboard?.writeText(text).then(() => {
      copy.textContent = 'Copied'; setTimeout(() => (copy.textContent = 'Copy'), 1500);
    });
    return;
  }
  const a = e.target.closest('a[data-nav]');
  if (!a || e.button !== 0 || e.metaKey || e.ctrlKey || e.shiftKey || e.altKey) return;
  e.preventDefault();
  history.pushState(null, '', a.getAttribute('href'));
  route();
  window.scrollTo(0, 0);
});
window.addEventListener('popstate', route);
document.addEventListener('visibilitychange', () => { if (!document.hidden) { loadBoards(); pollBoard(); } });

/* ---------- account ---------- */
function accountHTML() {
  const here = encodeURIComponent(location.pathname + location.search);
  if (state.user) {
    return `Signed in as <strong>@${esc(state.user.handle)}</strong><a href="${ROOT}signout/">Sign out</a><a class="new-pad" href="${ROOT}create/">New pad</a>`;
  }
  return state.accounts ? `<a href="${ROOT}signin/?next=${here}">Sign in</a><a href="${ROOT}register/?next=${here}">Create account</a>` : '';
}

function applyAccount() {
  const box = document.getElementById('account');
  if (box) box.innerHTML = accountHTML();
  const author = document.getElementById('c-author');
  if (author && state.user) {
    author.value = '@' + state.user.handle;
    author.readOnly = true;
    author.setAttribute('aria-readonly', 'true');
  }
}

async function loadMe() {
  try {
    const d = await getJSON(`${API}?action=me`);
    state.user = d.signed_in ? { handle: d.handle, display_name: d.display_name } : null;
    state.accounts = !!(d.signed_in || d.accounts);
  } catch { state.user = null; }
  applyAccount();
}

loadMe();
route();
loadBoards();
setInterval(pollBoard, 3000);
setInterval(loadBoards, 15000);
