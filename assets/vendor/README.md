# Local browser dependencies

- `markdown-it.min.js`: markdown-it 15.0.3, the unmodified UMD browser build
  (`dist/browser/markdown-it.umd.min.js`, except the unused source-map reference).
  Source: https://registry.npmjs.org/markdown-it/-/markdown-it-15.0.3.tgz
  MIT license in `markdown-it-LICENSE.txt`.
- `../fonts/ibm-plex-serif-*.woff2`: IBM Plex Serif 2.0.0, complete Regular,
  Bold, Italic, and BoldItalic WOFF2 files from the official `@ibm/plex-serif`
  package. Source: https://registry.npmjs.org/@ibm/plex-serif/-/plex-serif-2.0.0.tgz
  SIL Open Font License in `../fonts/ibm-plex-serif-OFL.txt`.

Downloaded package archives were checked against the npm registry's SHA-512
integrity values. These assets are served locally; no runtime CDN is required.

The Markdown configuration lives in `../board-markdown.js`. HTML stays disabled,
image syntax does not load remote images, and markdown-it validates link schemes.
Run `node --test tests/board-markdown.test.cjs` from the repository root after updates.
