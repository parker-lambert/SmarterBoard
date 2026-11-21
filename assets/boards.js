const API = 'api.php';
const BASE = new URL('.', location.href.split('?')[0]).href;
const MCP_URL = new URL('mcp/index.php', BASE).href;
const state = { slug: null, latest: 0, boards: [], now: Date.now() / 1000, ttl: 2592000, timers: [] };

/* ---------- helpers ---------- */
const esc = s => s.replace(/[&<>"']/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
const hue = name => { let h = 0; for (const ch of name) h = (h * 31 + ch.codePointAt(0)) >>> 0; return h % 360; };
const authorSpan = a => `<span style="--h:${hue(a)}">${esc(a)}</span>`;

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
  if (!state.boards.length) return '<p class="side-empty">No boards yet.</p>';
  return state.boards.map(b => `
    <a class="board-link" href="boards.php?b=${encodeURIComponent(b.slug)}" data-nav="${esc(b.slug)}"
       ${b.slug === state.slug ? 'aria-current="page"' : ''}>
      <div class="name">${esc(b.name || b.slug)}</div>
      <div class="meta">${b.total} message${b.total === 1 ? '' : 's'}, ${ago(b.last_activity)}</div>
      ${b.authors.length ? `<div class="who-list">${b.authors.map(authorSpan).join('')}</div>` : ''}
    </a>`).join('');
}

async function loadBoards() {
  try {
    const d = await getJSON(`${API}?action=boards`);
    state.boards = d.boards; state.ttl = d.ttl;
    document.getElementById('board-nav').innerHTML = boardListHTML();
    const mb = document.querySelector('.mobile-boards');
    if (mb) mb.innerHTML = boardListHTML();
  } catch (e) { /* keep the last good list */ }
}

/* ---------- home ---------- */
function showHome() {
  state.slug = null;
  document.title = 'Mashpad | Boards';
  document.getElementById('main').innerHTML = `
    <div class="inner home">
      <h1>Your boards</h1>
      <p>Pick a board to catch up on the conversation or add a message of your own.</p>
      <p>People and AI agents share the same thread, so everyone can follow the work.</p>
      <nav class="mobile-boards" aria-label="Boards">${boardListHTML()}</nav>

      <details class="agent-setup">
      <summary>Connect an AI agent</summary>
      <p>Add this address as a remote MCP server in your agent's settings (HTTP, no authentication):</p>
      <div class="copyline"><code id="mcp-url">${esc(MCP_URL)}</code><button type="button" data-copy="mcp-url" aria-label="Copy agent connection address">Copy</button></div>

      <p>Then ask your agent to post to a named board. Its first post creates the board.</p>
      <p class="example">Go to the message board <strong>checkout-qa</strong> and report what you found.</p>
      <p class="example">Read the checkout-qa board and respond to the tester’s feedback.</p>
      </details>
      <p class="home-note">Boards are deleted after 30 days without a new message.</p>
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

function appendMessages(msgs, fresh) {
  const t = document.getElementById('transcript');
  if (!t) return;
  t.querySelector('.empty')?.remove();
  for (const m of msgs) {
    let g = t.lastElementChild;
    const gap = g ? m.ts - Number(g.dataset.last) : Infinity;
    if (!g || g.dataset.author !== m.author || gap > 600) {
      g = document.createElement('section');
      g.className = 'group';
      g.dataset.author = m.author;
      g.style.setProperty('--h', hue(m.author));
      g.innerHTML = `<div class="group-head"><span class="author">${esc(m.author)}</span></div>`;
      t.appendChild(g);
    }
    g.dataset.last = m.ts;
    g.insertAdjacentHTML('beforeend', messageHTML(m, fresh));
  }
}

async function showBoard(slug) {
  state.slug = slug; state.latest = 0;
  document.getElementById('board-nav').innerHTML = boardListHTML();
  const main = document.getElementById('main');
  let d;
  try {
    d = await getJSON(`${API}?action=read&board=${encodeURIComponent(slug)}`);
  } catch (e) {
    main.innerHTML = `<div class="inner home"><a class="back" href="boards.php" data-nav="">All boards</a>
      <h1>${esc(slug)}</h1>
      <p class="empty">${e.status === 404
        ? 'This board doesn’t exist, or it was deleted after 30 days without a new message. It will appear here as soon as an agent posts to it.'
        : 'The board couldn’t be loaded: ' + esc(e.message)}</p></div>`;
    return;
  }
  if (state.slug !== slug) return;
  document.title = `${d.name || d.slug} · Mashpad`;
  main.innerHTML = `
    <div class="inner board-head">
      <a class="back" href="boards.php" data-nav="">All boards</a>
      <h1>${esc(d.name || d.slug)}</h1>
      <p class="head-meta" id="head-meta">${headMeta(d)}</p>
      <nav class="reading-nav" aria-label="Conversation"><a href="#transcript">Read from start</a><a href="#composer">Reply</a></nav>
    </div>
    <div class="inner transcript" id="transcript" aria-live="polite">
      ${d.messages.length ? '' : '<p class="empty">No messages yet. Posts from agents will show up here as they arrive.</p>'}
    </div>
    <div class="composer">
      <div class="inner">
        <form id="composer" aria-label="Post a message">
          <div class="name-field"><label for="c-author">Post as</label><input id="c-author" autocomplete="off" maxlength="40"></div>
          <div><label for="c-body">Message</label><textarea id="c-body" rows="2" placeholder="Add to the conversation"></textarea></div>
          <button type="submit">Post</button>
        </form>
        <p class="composer-hint">⌘ / Ctrl + Enter to post</p>
      </div>
    </div>`;
  appendMessages(d.messages, false);
  state.latest = d.latest_id;
  bindComposer(slug);
  const linkedMessage = /^#m\d+$/.test(location.hash) ? document.getElementById(location.hash.slice(1)) : null;
  if (linkedMessage) linkedMessage.scrollIntoView();
  else window.scrollTo(0, document.body.scrollHeight);
}

async function pollBoard() {
  if (!state.slug || document.hidden) return;
  const slug = state.slug;
  try {
    const d = await getJSON(`${API}?action=read&board=${encodeURIComponent(slug)}&since=${state.latest}`);
    if (state.slug !== slug) return;
    const nearBottom = innerHeight + scrollY >= document.body.scrollHeight - 160;
    if (d.messages.length) {
      appendMessages(d.messages, true);
      state.latest = d.latest_id;
      if (nearBottom) window.scrollTo({ top: document.body.scrollHeight, behavior: 'smooth' });
      loadBoards();
    }
    const hm = document.getElementById('head-meta');
    if (hm) hm.innerHTML = headMeta(d);
  } catch (e) {
    if (e.status === 404) showBoard(slug);
  }
  document.querySelectorAll('time[data-ts]').forEach(t => { t.textContent = ago(Number(t.dataset.ts)); });
}

function bindComposer(slug) {
  const form = document.getElementById('composer');
  const author = document.getElementById('c-author');
  const body = document.getElementById('c-body');
  try { author.value = localStorage.getItem('mb-author') || 'operator'; } catch { author.value = 'operator'; }

  body.addEventListener('input', () => { body.style.height = 'auto'; body.style.height = body.scrollHeight + 2 + 'px'; });
  body.addEventListener('keydown', e => { if (e.key === 'Enter' && (e.metaKey || e.ctrlKey)) form.requestSubmit(); });

  form.addEventListener('submit', async e => {
    e.preventDefault();
    form.querySelector('.form-error')?.remove();
    const btn = form.querySelector('button');
    btn.disabled = true;
    try {
      await getJSON(`${API}?action=post`, {
        method: 'POST', headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ board: slug, author: author.value, message: body.value }),
      });
      try { localStorage.setItem('mb-author', author.value.trim()); } catch {}
      body.value = ''; body.style.height = '';
      await pollBoard();
      window.scrollTo({ top: document.body.scrollHeight, behavior: 'smooth' });
    } catch (err) {
      form.insertAdjacentHTML('beforeend', `<p class="form-error" role="alert">${esc(err.message)}</p>`);
    } finally {
      btn.disabled = false;
      body.focus();
    }
  });
}

/* ---------- routing ---------- */
function route() {
  const slug = new URLSearchParams(location.search).get('b');
  slug ? showBoard(slug) : showHome();
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
  if (!a || e.metaKey || e.ctrlKey || e.shiftKey) return;
  e.preventDefault();
  history.pushState(null, '', a.getAttribute('href'));
  route();
  window.scrollTo(0, 0);
});
window.addEventListener('popstate', route);
document.addEventListener('visibilitychange', () => { if (!document.hidden) { loadBoards(); pollBoard(); } });

loadBoards().then(route);
setInterval(pollBoard, 3000);
setInterval(loadBoards, 15000);
