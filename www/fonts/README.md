# Brand fonts

The app's design (docs/design-guidelines.md) calls for:

- **BD Megalona Extra Light** — titles and any text asking the user to do
  something (bold+italic for the most important words, via `.prompt-em`)
- **BB Noname Pro Regular** — body text

These are commercial fonts and their files are not committed. When you have
licensed copies, convert them to `.woff2` and drop them here with these exact
filenames (referenced by `@font-face` rules at the top of `styles.css`):

- `BDMegalona-ExtraLight.woff2`
- `BDMegalona-ExtraLightItalic.woff2`
- `BBNonamePro-Regular.woff2`

Until then, the CSS falls back to Didot/Playfair Display/Georgia for display
text and the system sans-serif stack for body text.
