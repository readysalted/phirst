# PHIRST content migration

The legacy PHIRST website is the content authority. Figma supplies layout and styling, not theme names or placeholder copy. This migration remains local; nothing has been deployed to UAT or production.

## Imported content

- 104 legacy pages, including all 76 real evaluations and 28 other pages. The approved Home remains the same ten component layouts and images, with source copy replacing Lorem ipsum.
- All 18 published news posts, with original publication dates, source categories/tags, featured images and SEO fields.
- All 170 published staff profiles, rendered through the existing `person` content type at `/staff/<slug>/`.
- 18 evaluation theme landing pages, generated from actual source taxonomy assignments. The spelling duplicate “Digitial and online initiatives.” is merged into “Digital and online initiatives”. Multiple assignments are retained.
- 547 additional local media/document attachments. Existing homepage artwork and original partner logos are reused.

The published test evaluation with source ID 5713 (`__trashed`, “Title of Evaluation, please update 1”) is excluded. Unpublished/trash content remains in the private full WXR export and is not published on the new site. Source users, credentials and plugin settings are not imported.

## Public behavior

Evaluations → theme image tiles → evaluations assigned to that theme → evaluation detail. There is no map or visitor filter. Catalogue cards and individual headers use real source images; the catalogue hero follows the Figma frame.

Evaluation URLs retain their source structure. Previous local navigation URLs redirect to the imported source slugs. A legacy page and a news post shared the same healthy-place-making webinar URL; the page retains that URL and the news post gains `-news` so both are reachable. The obsolete smoking-cessation link on the source seminar page now points to the correct imported evaluation.

All text, reports, existing output accordions, embedded videos, contact details and team relationships are mapped into existing Flynt components. Evaluation research-team text uses the yellow section treatment; source relationship lists use Meet: The Team cards. News and profile templates render editable components. Search indexes component text and refreshes when ACF saves a page.

The old Evaluations catalogue is a **draft** named “Legacy Evaluations — map and filters reference”. Its original HTML is retained in `_phirst_legacy_catalogue_html`; original ACF fields, including map coordinates, are retained in `_phirst_legacy_fields`. This material is never rendered in the public catalogue.

## Source gaps and editorial choices

- The live Contact page outputs a broken Everest Forms shortcode. The new page provides the source site's published `PHIRST@NIHR.ac.uk` email. No new mail service has been configured.
- The live Publications page outputs a broken `[tpsearch]` shortcode. The new page groups actual linked documents from 54 evaluations using the existing accordion component.
- Impact uses the original About PHIRST explanation of knowledge mobilisation, linking to evaluations, publications and seminars.
- Cookie Policy uses the source Privacy page's Cookies section; the full original Privacy, Terms and Accessibility texts are retained. These are legacy policies, including statements about the old site's features; editorial review remains appropriate before launch.
- Newsletter signup retains the source Mailchimp form action, field names, audience choices, privacy copy, required email/consent and honeypot. No subscription was submitted during QA.
- Original source fields are preserved privately in `_phirst_legacy_fields` for comparison and future editing. Imported SEO is editable under PHIRST Content Details and is rendered without requiring Yoast. Equivalent Yoast title/description fields are retained.

## Editing in WordPress

For an evaluation, use **PHIRST Content Details → Page role: Evaluation**, then select one or more **Evaluation themes**. The corresponding theme pages update automatically. Edit the hero, text, outputs and team sections under **Page Components**.

A theme landing page has **Page role: Evaluation theme landing page**, its linked taxonomy term, and **Grid: Image Text → Content source: Evaluations in this theme**. The catalogue uses **All evaluation themes**. A new theme needs a published landing page linked to that term; the importer creates these for the initial migration.

Mailing-list and footer content remain in the existing Flynt options. The homepage keeps its own mailing-list row; imported interior pages use the global row.

## Repeatable import

First back up the local database. Keep all exports, media caches and reports **outside this Git repository**. The private source folder used for this migration is `../../../../../migration-source/20261023` relative to the theme directory (the project's `migration-source/20261023` folder).

1. Download **Tools → Export → All content** from the legacy PHIRST admin, and save it as `source-export.xml` in the private source directory.
2. Extract published records and cache public content:

   ```sh
   python3 tools/read-phirst-wxr.py /absolute/private/phirst-source
   python3 tools/fetch-phirst-legacy.py /absolute/private/phirst-source
   ```

3. With ACF Pro active, run from the theme directory:

   ```sh
   wp eval-file tools/import-phirst-content.php /absolute/private/phirst-source
   wp eval-file tools/finalize-phirst-content.php /absolute/private/phirst-source
   wp eval-file tools/verify-phirst-migration.php /absolute/private/phirst-source
   python3 tools/check-phirst-http.py /absolute/private/phirst-source
   ```

The importer reuses source identities and media, avoiding duplicates. It is a migration utility, not a background sync: rerunning updates source fields, slugs, taxonomies, SEO, listings and global options. Component rows whose source hash is unchanged are preserved. Pass `reset-components` only to deliberately rebuild imported rows; run finalization afterward to restore derived contact/publication content. Finalization also updates homepage placeholder copy/URLs, footer options and the search index. Review these effects before rerunning over later editorial work.

## Validation and handoff

- All source identities, normalized evaluation theme assignments, source text paragraphs, SEO titles and local files verified; zero missing source assets and zero remaining legacy-domain destinations in imported component rows.
- All 316 unique public destination URLs return HTTP 200, with one H1, no Lorem ipsum and no PHP/Twig fatal output.
- Catalogue and evaluation saved successfully through the ACF editor; all three selected themes on the sampled evaluation survived saving.
- Browser checks cover 320, 390, 768, 1024, 1280 and 1728 px, including the automatic theme grid, output accordion, mobile menu, deferred video and body-text search. No horizontal page overflow was observed.
- Targeted PHPCS, Stylelint and JavaScript Standard checks and production Vite build pass. The inherited repository-wide lint baseline is unchanged.
- The full source WXR/cache and before/after SQL snapshots remain outside Git. WordPress database and uploads are required for any later UAT transfer; cloning the theme alone does not transfer content.
