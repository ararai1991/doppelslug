# Publishing Doppelslug on WordPress.org

## Before every release

1. Bump the version in three places (the build script refuses to run if they differ):
   - `doppelslug/doppelslug.php`: the `Version:` header and `DOPPELSLUG_VERSION`
   - `doppelslug/readme.txt`: `Stable tag:` and a new `== Changelog ==` entry
2. Keep `Tested up to:` in `readme.txt` at the current WordPress major version.
3. If you changed or added any text, run `composer i18n` and translate the new strings in `doppelslug/languages/doppelslug-fa_IR.po`, then run `composer i18n` again.
4. Run the checks (see README.md): `composer lint`, `bash tests/run-playground-tests.sh`, `node tests/browser-check.mjs`, `python tests/plugin-check.py`.
5. Build the ZIP: `python bin/build-zip.py` → `dist/doppelslug-<version>.zip`.

WordPress.org holds every release for 6 hours and scans it before it reaches sites. A release with a security finding is blocked automatically, so run the checks before tagging.

## First submission (one time)

1. **Account.** Log in at https://login.wordpress.org/ as `ararai`, the username in the `Contributors:` line of `readme.txt`.
2. **Two-factor authentication.** Turn it on under your profile → Account & Security. WordPress.org requires it for anyone who can commit plugin code.
3. **Submit.** Upload `dist/doppelslug-1.0.0.zip` at https://wordpress.org/plugins/developers/add/. The upload runs Plugin Check automatically.
4. **Check the slug in the confirmation email.** It should be `doppelslug`. A slug can only be changed before approval, by replying to the review email.
5. **Review.** A volunteer reviews the code; the queue can take a few weeks. Reply to their email (from plugins@wordpress.org) and fix anything they ask for by uploading a new ZIP on the same page.

## After approval: first release through SVN

WordPress.org hosts plugins in Subversion. You get `https://plugins.svn.wordpress.org/doppelslug/`.

1. Create an SVN password under your profile → Account & Security (your normal password does not work for SVN).
2. Install an SVN client. On Windows, TortoiseSVN with "command line client tools" selected.
3. Publish:

```bash
svn checkout https://plugins.svn.wordpress.org/doppelslug svn-doppelslug
cp -r doppelslug/. svn-doppelslug/trunk/
cp .wordpress-org/*.png svn-doppelslug/assets/
cd svn-doppelslug
svn add --force trunk assets
svn propset svn:mime-type image/png assets/*.png
svn cp trunk tags/1.0.0
svn ci -m "Release 1.0.0" --username YOUR_WPORG_USERNAME
```

`assets/` holds the banner, icon, and screenshots (`screenshot-1.png` … match the numbered `== Screenshots ==` captions in `readme.txt`). Sources for the banner and icon are in `.wordpress-org/src/`; re-render with `node bin/render-assets.mjs`.

## Later releases

Copy the new `doppelslug/` contents over `trunk/`, `svn add` any new files, `svn rm` deleted ones, then `svn cp trunk tags/<version>` and commit. The `Stable tag` in `trunk/readme.txt` tells WordPress.org which tag to ship.
