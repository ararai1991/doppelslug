# Contributing to Doppelslug

Thanks for helping. Bug reports, ideas, code and translations are all welcome.

## Reporting a bug

Open an [issue](https://github.com/ararai1991/doppelslug/issues/new/choose) with:

- your WordPress and PHP versions, and which editor you use (block editor or Classic Editor)
- your permalink structure (*Settings → Permalinks*)
- the slugs involved, what you expected, and what happened

Security problems should not go in public issues; see [SECURITY.md](SECURITY.md).

## Code

1. Fork the repository and create a branch.
2. Run `composer install` and `npm install`.
3. Make the change inside `doppelslug/`. Follow the [WordPress Coding Standards](https://developer.wordpress.org/coding-standards/): prefix everything with `doppelslug`, sanitize input, escape output, and check capabilities and nonces on anything that handles a request.
4. Run `composer lint` and the Playground tests described in the [README](README.md#development).
5. Open a pull request that explains what changed and why.

The JavaScript has no build step on purpose: edit `doppelslug/assets/js/*.js` directly and keep it readable, since WordPress.org requires human-readable code.

## Text and translations

Every user-facing string uses the `doppelslug` text domain. After changing or adding text:

1. Run `composer i18n` to regenerate `doppelslug/languages/doppelslug.pot`.
2. Translate the new strings in `doppelslug/languages/doppelslug-fa_IR.po` (Persian uses «» quotes and half-spaces).
3. Run `composer i18n` again to rebuild the `.mo` and JavaScript `.json` files.

Once the plugin is on WordPress.org, translations into other languages are best added at [translate.wordpress.org](https://translate.wordpress.org/).

## License

By contributing you agree that your contribution is licensed under GPL-2.0-or-later.
