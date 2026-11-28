import sys, subprocess, json, re, os
import xml.etree.ElementTree as ET
from html.parser import HTMLParser
from playwright.sync_api import sync_playwright
B = sys.argv[1]; res = []
def ck(name, cond, detail=''):
    res.append(bool(cond)); print(('ok   ' if cond else 'FAIL ') + name + ('' if cond else '  ' + str(detail)[:600]))
def get(path_or_url):
    url = path_or_url if path_or_url.startswith('http') else B + path_or_url
    raw = subprocess.run(['curl', '-s', '-i', url], capture_output=True).stdout
    head, _, body = raw.replace(b'\r\n', b'\n').partition(b'\n\n')
    lines = head.decode('latin1').split('\n'); hd = {l.split(':', 1)[0].lower(): l.split(':', 1)[1].strip() for l in lines[1:] if ':' in l}
    return int(lines[0].split()[1]), hd, body
class Head(HTMLParser):
    def __init__(self): super().__init__(); self.tags = []; self.ld = []; self._ld = False
    def handle_starttag(self, tag, attrs):
        a = dict(attrs)
        if tag in ('meta', 'link'): self.tags.append((tag, a))
        if tag == 'script' and a.get('type') == 'application/ld+json': self._ld = True; self.ld.append('')
    def handle_data(self, data):
        if self._ld: self.ld[-1] += data
    def handle_endtag(self, tag):
        if tag == 'script': self._ld = False
def page(path):
    s, h, b = get(path); p = Head(); p.feed(b.decode('utf-8', 'replace'))
    meta = {(a.get('property') or a.get('name')): a.get('content') for t, a in p.tags if t == 'meta' and (a.get('property') or a.get('name'))}
    canon = [a['href'] for t, a in p.tags if t == 'link' and a.get('rel') == 'canonical']
    return s, meta, canon, [json.loads(x) for x in p.ld]
HOST = re.match(r'(https?://[^/]+)', B).group(1)
PAGES = {'': 'Mashpad | People and agents thinking together', 'features/': 'How Mashpad works | Mashpad', 'pricing/': 'Pricing | Mashpad', 'terms/': 'Terms of Service | Mashpad', 'privacy/': 'Privacy Policy | Mashpad'}
for path, title in PAGES.items():
    s, meta, canon, ld = page('/' + path)
    ck(f'/{path} has one canonical address, absolute, its own', s == 200 and canon == [HOST + '/' + path], (s, canon))
    ck(f'/{path} has a description of a sensible length', 40 <= len(meta.get('description', '')) <= 220, meta.get('description'))
    ck(f'/{path} has Open Graph tags and a large Twitter card, with the same title and the picture', meta.get('og:title') == title and meta.get('og:url') == HOST + '/' + path and meta.get('og:type') == 'website' and meta.get('og:description') == meta.get('description') and meta.get('og:image', '').startswith(HOST + '/assets/og-share.jpg') and meta.get('twitter:card') == 'summary_large_image' and meta.get('twitter:title') == title and meta.get('twitter:image') == meta.get('og:image') and meta.get('og:image:width') == '1200' and meta.get('og:image:height') == '630' and meta.get('og:image:alt'), meta)
s, hd, body = get(meta['og:image'])
ck('the share picture loads as a JPEG of the right size, and is the home page picture cut down', s == 200 and hd['content-type'] == 'image/jpeg' and len(body) < 400000 and body[:3] == b'\xff\xd8\xff')
# ---- structured data
s, meta, canon, ld = page('/')
org = [n for g in ld for n in g.get('@graph', [g]) if n.get('@type') == 'Organization'][0]
ck('the home page\'s organisation has Mashpad\'s own email, its sameAs and its parent', org['email'] == 'hello@mashpad.ai' and org['sameAs'] == ['https://parker-lambert.com', 'https://centraldesk.io'] and org['parentOrganization']['address']['streetAddress'] == '922 Chartres St, Unit B' and 'hello@parker-lambert.com' not in json.dumps(ld), org)
ck('and a WebSite that points at it', any(n.get('@type') == 'WebSite' and n['publisher'] == {'@id': org['@id']} for g in ld for n in g.get('@graph', [g])))
s, meta, canon, ld = page('/features/')
nodes = [n for g in ld for n in g.get('@graph', [g])]; wp = [n for n in nodes if n.get('@type') == 'WebPage'][0]; il = [n for n in nodes if n.get('@type') == 'ItemList'][0]
ck('the features page has structured data: a WebPage in the site, about the organisation, with its use cases as a list', wp['url'] == HOST + '/features/' and wp['isPartOf'] == {'@id': HOST + '/#site'} and wp['about'] == {'@id': HOST + '/#org'} and wp['mainEntity'] == {'@id': il['@id']} and len(il['itemListElement']) == 10, nodes)
with sync_playwright() as p:
    br = p.chromium.launch(); pg = br.new_context().new_page(); pg.goto(B + '/features/')
    heads = pg.eval_on_selector_all('h3[id^=uc-]', 'els => els.map(e => [e.id, e.textContent.trim()])'); br.close()
ck('and that list is exactly the headings the page shows, with working anchors, so the data matches the words', [(i['name'], i['url']) for i in il['itemListElement']] == [(t, HOST + '/features/#' + a) for a, t in heads] and all(i['position'] == n + 1 for n, i in enumerate(il['itemListElement'])), (il['itemListElement'][:2], heads[:2]))
s, meta, canon, ld = page('/pricing/')
app = [n for g in ld for n in g.get('@graph', [g]) if n.get('@type') == 'SoftwareApplication'][0]
ck('the pricing structured data lists only the free offer: nothing is claimed to be for sale that is not', [o['price'] for o in app['offers']] == ['0'] and 'Plus' not in json.dumps(app) and app['publisher'] == {'@id': HOST + '/#org'}, app['offers'])
ck('the pricing page has a description, and says a paid plan is on the way, not available', len(meta['description']) > 40 and 'free' in meta['description'].lower() and 'A paid plan for teams is on the way' in get('/pricing/')[2].decode())
# ---- the files for crawlers and agents
s, hd, b = get('/robots.txt'); t = b.decode()
ck('robots.txt is plain text, closes what needs an account, names the sitemap, and leaves the public pages open', s == 200 and hd['content-type'].startswith('text/plain') and 'Disallow: /pads/' in t and 'Disallow: /admin/' in t and 'Sitemap: https://mashpad.ai/sitemap.xml' in t and not re.search(r'^Disallow: /(features/|pricing/|terms/|privacy/)?$', t, re.M), (s, hd.get('content-type')))
s, hd, b = get('/sitemap.xml'); root = ET.fromstring(b); ns = {'s': 'http://www.sitemaps.org/schemas/sitemap/0.9'}; locs = [e.text for e in root.findall('s:url/s:loc', ns)]
ck('sitemap.xml is XML, well-formed, and lists the five public pages', s == 200 and 'xml' in hd['content-type'] and locs == ['https://mashpad.ai/' + x for x in PAGES], (hd.get('content-type'), locs))
ck('and each of them is a real page on this site', all(get(l.replace('https://mashpad.ai', HOST))[0] == 200 for l in locs))
s, hd, b = get('/llms.txt'); t = b.decode()
ck('llms.txt is plain text, with a title, a summary and links to the agents guide, the connector and the public pages', s == 200 and hd['content-type'].startswith('text/plain') and t.startswith('# Mashpad') and '(https://mashpad.ai/agents.md)' in t and 'https://mashpad.ai/mcp/' in t and '(https://mashpad.ai/features/)' in t and '(https://mashpad.ai/pricing/)' in t, (s, hd.get('content-type')))
s, hd, b = get('/agents.md'); t = b.decode()
ck('/agents.md is a real page now, not a "multiple choices" dead end', s == 200 and 'Multiple Choices' not in t and t.startswith('# Mashpad, for agents'), (s, t[:100]))
ck('it is shown as text, not downloaded, and says how to connect and join', 'markdown' in hd['content-type'] and 'attachment' not in hd.get('content-disposition', '') and 'mcp/open/' in t and 'find_public_pads' in t, hd)
s, hd, b = get('/agents')
ck('the signed-in agents page is still where it was', s in (200, 301, 302, 303) , s)
# ---- Google Analytics, through Google Tag Manager
def raw(path, headers=None):
    cmd = ['curl', '-s', B + path]
    for k, v in (headers or {}).items(): cmd += ['-H', f'{k}: {v}']
    return subprocess.run(cmd, capture_output=True, text=True).stdout
LIVE = {'Host': 'mashpad.ai'}
GTM = 'GTM-N8SK2JMJ'
for path in PAGES:
    h = raw('/' + path, LIVE); head = h.split('</head>')[0]; after = h.split('<body', 1)[1] if '<body' in h else ''
    ck(f'/{path}, as the real site, loads the tag in the head and the no-script frame just inside the body', head.count(f"'{GTM}'") == 1 and 'googletagmanager.com/gtm.js?id=' in head and 'gtm.start' in head and f'ns.html?id={GTM}' in after[:700] and after.count(f'ns.html?id={GTM}') == 1, path)
    ck(f'/{path} measures nothing on a developer\'s machine, or for a browser that asks not to be', GTM not in raw('/' + path) and GTM not in raw('/' + path, {**LIVE, 'Sec-GPC': '1'}) and GTM not in raw('/' + path, {**LIVE, 'DNT': '1'}))
for path in ['signin/', 'register/', 'reset/', 'join/abcdefghijklmnop', 'pads/', 'public-pads/', 'admin/', 'no-such-page', 'cron/', 'features/../pads/']:
    ck(f'/{path} never loads it, even as the real site', GTM not in raw('/' + path, LIVE) and 'googletagmanager' not in raw('/' + path, LIVE), path)
priv = raw('/privacy/', LIVE)
ck('the privacy page says so, in words, and carries the new date', 'Measuring visits to the public pages' in priv and 'Google Tag Manager to run Google Analytics' in priv and '28 November 2026' in priv)
print(f"{sum(res)} passed, {len(res)-sum(res)} failed")
