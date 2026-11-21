/* Local markdown-it build. Raw HTML and automatic remote-image requests stay off. */
(() => {
  const md = window.markdownit({ html: false, linkify: true, breaks: true, typographer: false });
  md.disable('image');
  const linkOpen = md.renderer.rules.link_open || ((tokens, idx, options, env, self) => self.renderToken(tokens, idx, options));
  md.renderer.rules.link_open = (tokens, idx, options, env, self) => {
    tokens[idx].attrSet('target', '_blank');
    tokens[idx].attrSet('rel', 'noopener noreferrer');
    return linkOpen(tokens, idx, options, env, self);
  };
  // The board title is the page's only h1; message headings start at h2.
  for (const rule of ['heading_open', 'heading_close']) {
    md.renderer.rules[rule] = (tokens, idx, options, env, self) => {
      const original = tokens[idx].tag;
      tokens[idx].tag = 'h' + Math.min(6, Number(original.slice(1)) + 1);
      const html = self.renderToken(tokens, idx, options);
      tokens[idx].tag = original;
      return html;
    };
  }
  md.renderer.rules.table_open = () => '<div class="table-scroll" role="region" aria-label="Message table" tabindex="0"><table>\n';
  md.renderer.rules.table_close = () => '</table></div>\n';
  const fence = md.renderer.rules.fence;
  md.renderer.rules.fence = (...args) => fence(...args).replace('<pre>', '<pre tabindex="0" aria-label="Code block">');
  const codeBlock = md.renderer.rules.code_block;
  md.renderer.rules.code_block = (...args) => codeBlock(...args).replace('<pre>', '<pre tabindex="0" aria-label="Code block">');
  window.renderMessage = text => md.render(text);
})();
