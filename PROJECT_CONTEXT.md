# PHIRST project context

Last verified: 2026-10-23 (UAT migration repair; content migration and professionals page refinement).

## Project

- Theme and repository root: `app/public/wp-content/themes/phirst` inside the Local site.
- Site: http://phirst.local/
- UAT: https://phirst.readysalteddev.co.uk/ (`staging`, search indexing disabled).
- Repository: git@github-readysalted:readysalted/phirst.git, branch `main`.
- Independent Git history, initialized for PHIRST. PRU Git metadata and old migration scripts are outside this repository.
- Stack: Flynt 2.1.1, Timber 2, Twig 3, ACF Pro, Vite 5, PHP >=8.1 and Node >=20.
- The existing library contains 59 component folders; see [COMPONENTS.md](COMPONENTS.md). Reuse existing components first. Add a component only when the existing library cannot express the required block.
- Do not commit credentials, ACF license keys, WordPress core/plugins, database exports, `vendor`, `node_modules` or `dist/hot`.

## Approved design

Figma file: https://www.figma.com/design/Ige5obtBeQJly4vc31jyTA/NIHR-PHIRST-DEV

The approved Home is `3402:10818` (Homepage) on the `2026` page (`3402:91`). The originally supplied deep link (`3404:12115`) is an accordion on an evaluation detail example, rather than the Home frame.

Design colors: Navy `#1C285E`, Brand Blue `#193E72`, Blue Flame `#0051C2`, Glove Blue `#C7E9FF`, Lilac `#C4C5FF`, Putty `#FAF4EB`, Slate `#2D2D2D`, Coral `#FC5D5D`. Headings use local Archia; body/navigation use Inclusive Sans; editorial card/news copy uses Lato.

The current site logo is the SVG supplied by the user on 2026-10-21: `assets/images/phirst/nihr-phirst-logo.svg`. It replaces the previous two cropped PNGs in both navigation components and on the WordPress login screen. Its live lettering uses the theme's Archia Regular font embedded inside the SVG, so rendering does not depend on installed fonts or external font requests. The original artwork, colors and proportions are preserved.

Approved assets are in `assets/images/phirst/`; `figma-assets.json` records their Figma layer IDs without temporary download URLs. Home content images are real WordPress Media Library attachments. Newsletter artwork preserves the crop shown in Figma.

At the user's request, the partner strip uses nine individual original logos from https://phirst.nihr.ac.uk/ instead of the Figma crops. Local SVG/PNG originals and provenance are in `assets/images/phirst/partners/manifest.json`: Fuse, Bristol, LiLaC, LSBU, Hertfordshire, PHRESH, Nottingham, Northumbria and Glasgow. Each logo is a separate Media Library attachment and ACF repeater item, displayed with its full aspect ratio. Links remain optional, matching the unlinked legacy strip. The horizontal scroller supports touch, mouse dragging, keyboard navigation and an interactive progress track.

## Home content

Home is page ID 5 on the initial local database and contains ten ACF Flexible Content rows:

1. `BlockImageTextHero` / `homepage`
2. `BlockFeedbackBanner`
3. `BlockImageText` / `phirstLatest`
4. `BlockWysiwyg` / `phirstIntro`
5. `ListLogos` / `homepagePartners`
6. `BlockVideoOembed` / `phirstVideo`
7. `GridImageText` / `phirstCards`
8. `BlockPostTabs` / `phirstQuotes`
9. `BlockPostTabs` / `homepage`
10. `BlockMailingList` / `homepage`

No new frontend components were needed. PHIRST variants extend the existing components; their design overrides are in `assets/styles/_phirst.scss`.

There are fifteen editable image fields directly on Home, including the nine partner logos, plus the news posts' Featured Images. The first news slide retains its approved Figma image.

There are three news posts with Featured Images and three testimonials. The first news slide retains the approved Figma artwork/copy. The news feed uses the PHIRST News category, has a pinned first post and requests three slides. Additional news slides use short summaries and link to the original full stories via the editable `externalStoryLink` ACF field. Two additional testimonials are brief excerpts from the current PHIRST site.

The video is the actual PHIRST introduction (`ZfOqd7JJCrY`) and loads only after Play. Newsletter signup links to the local page containing the original PHIRST Mailchimp form. Footer social links point to the verified PHIRST YouTube channel and LinkedIn page.

## Pages and menus

The full legacy content migration is now implemented locally. There are 128 published pages (including 76 evaluations and 18 theme landing pages), 18 news posts and 170 staff profiles. The approved ten-row Home and its images are retained; source content replaces Figma placeholder prose. Header/footer links target the imported source slugs, with redirects for previous local navigation URLs.

Evaluations uses real source themes and automatic image tiles. Individual evaluations preserve source content, documents, theme assignments and SEO. No map or visitor filters are active. A legacy catalogue reference is saved as a draft. Interior pages have the global newsletter row; Home retains its own row.

See [MIGRATION.md](MIGRATION.md) for the source inventory, editor instructions, source gaps, repeatable migration commands and verification. The user cloned the site to UAT with a migration plugin on 2026-10-23. Its copied Vite hot marker was removed, WordPress was set to `staging`, and SiteGround's dynamic cache was purged. Local and UAT search indexing remain disabled.

## Development and setup

```sh
composer install
npm install
npm start
```

`vite.config.js` points to `http://phirst.local/`. Vite is served on `http://localhost:5173/`; open the WordPress URL. Its CORS origin is restricted to the local WordPress origin. `dist/hot` is ignored by Git and must be excluded from migration archives. Vite is enabled only for `local` or `development` WordPress environments; UAT and production always use compiled assets.

Production:

```sh
npm run build:production
```

Use `npm run build:production -- --emptyOutDir false` when compiling while the dev server is intentionally running, so its `dist/hot` file remains present. Tracked `dist` assets and the Vite manifest are required for deployment. PHP dependencies are installed separately from `composer.lock`.

On a fresh WordPress database, activate ACF Pro and PHIRST, then run from the theme directory with WP-CLI:

```sh
wp eval-file tools/seed-phirst-site.php
wp eval-file tools/import-phirst-homepage.php
```

The seed imports design and partner assets, creates empty pages/menus/options and populates Home. It preserves an already seeded Home; `reset-home` explicitly restores the initial content. The homepage importer reuses existing source posts and adds the news feed and testimonial excerpts. Do not rerun either command over later editorial work without reviewing what it updates. WordPress content/uploads are not stored in Git.

For an already seeded site, `wp eval-file tools/import-phirst-partners.php` replaces only the `homepagePartners` logo items and reuses imported attachments. It preserves all other Home rows. Reviewed bundled SVGs are allowed only during that CLI import; normal upload permissions are unchanged.

ACF Pro 6.8.10 is installed and active locally. A separate license key was not copied from PRU; enter the PHIRST license under ACF Updates for automatic plugin updates.

## Verification

- Production Vite build passed, including the shared Swiper components.
- Targeted Stylelint, JavaScript Standard and PHPCS checks passed for the new/changed implementation.
- Composer configuration validates; Composer audit reports no advisories after updating Twig and PHPCS. Production npm dependencies report zero vulnerabilities after the Swiper update.
- The inherited development tooling still has npm audit findings. A broad toolchain migration is outside this homepage implementation.
- Browser QA covered 320, 390, 768, 1024, 1200 and 1728 px without horizontal page overflow.
- Checked mobile menu open/close and Escape, card navigation, carousel controls and the deferred YouTube iframe. Inactive slides are inert; carousel IDs are unique per component.
- No browser console errors were captured during final Home QA.
- Logged into WordPress and successfully saved Home through the ACF editor; checked the ten rows and real image previews.
- Verified all nine partner originals load locally, the last logo is reachable, mouse dragging and the progress track work, and 320/390 px mobile layouts do not cause page overflow.
- Verified all 316 unique public destination URLs and imported files after the full content migration.

## Migration status

All published source content has been imported and checked. The source Contact and Publications shortcodes were already broken; their local replacements and remaining policy-review notes are documented in [MIGRATION.md](MIGRATION.md). No Figma Lorem ipsum or sample phone/address remains in the public site. The database, uploaded files and private source snapshots are excluded from Git.
