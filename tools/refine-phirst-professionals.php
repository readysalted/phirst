<?php

/** Recompose the imported professionals page using editable Flynt layouts. */

if (!defined('WP_CLI') || !WP_CLI) {
    exit;
}
$page = get_page_by_path('public-health-professionals-and-practitioners');
if (!$page || (int) get_post_meta($page->ID, '_phirst_source_id', true) !== 5221) {
    WP_CLI::error('Import the PHIRST professionals page before applying its layout.');
}
$reset = in_array('reset-layout', $args, true);
if (get_post_meta($page->ID, '_phirst_professionals_layout', true) && !$reset) {
    WP_CLI::success('Professionals layout already applied; editorial changes preserved.');
    return;
}
$backup = get_post_meta($page->ID, '_phirst_professionals_before', true);
$original = $reset && $backup ? $backup : get_field('pageComponents', $page->ID, false);
if (count($original) !== 7) {
    WP_CLI::error('The imported layout has changed. Review the page before applying this one-time refinement.');
}
$value = static function (array $row, string $field): mixed {
    foreach ($row as $key => $content) {
        if ($key === $field || str_ends_with($key, '_' . $field)) {
            return $content;
        }
    }
    return '';
};
$plain = static fn (string $html): string => trim(preg_replace('/\s+/u', ' ', html_entity_decode(wp_strip_all_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8')));
$text = static fn (string $html, string $style = 'phirstEditorial', string $theme = 'white'): array => [
    'acf_fc_layout' => 'blockWysiwyg', 'contentHtml' => $html,
    'options' => ['displayStyle' => $style, 'theme' => $theme, 'size' => 'medium', 'align' => 'center', 'textAlign' => 'left'],
];
$blogs = $value($original[4], 'contentHtml');
if (!str_contains($blogs, '<h2>Research blogs</h2>')) {
    WP_CLI::error('Expected the original Research blogs section.');
}
$previous = libxml_use_internal_errors(true);
$document = new DOMDocument();
$document->loadHTML('<?xml encoding="utf-8" ?>' . $blogs, LIBXML_NONET);
libxml_clear_errors();
libxml_use_internal_errors($previous);
$authors = [
    'Chloe-Forte-Blog.png' => 'Chloe Forte',
    'Jane-Hermuka-Blog.png' => 'Jane Hermuka',
    'Anita-Sewornu-Blog.png' => 'Anita Sewornu',
    'PIRi-Practitioner-Blog.png' => 'PIRi practitioner',
    'Sarah-Lee-Blog.png' => 'Sarah Lee',
];
$cards = [];
foreach ($document->getElementsByTagName('figure') as $figure) {
    $link = $figure->getElementsByTagName('a')->item(0);
    $image = $figure->getElementsByTagName('img')->item(0);
    $caption = $figure->getElementsByTagName('figcaption')->item(0);
    if (!$link || !$image || !$caption) {
        WP_CLI::error('A source blog is missing its image, document or role.');
    }
    $name = $authors[basename(wp_parse_url($image->getAttribute('src'), PHP_URL_PATH))] ?? '';
    $imageId = attachment_url_to_postid($image->getAttribute('src'));
    $pdfId = attachment_url_to_postid($link->getAttribute('href'));
    if (!$name || !$imageId || !$pdfId || !is_file(get_attached_file($pdfId))) {
        WP_CLI::error('The original blog image and PDF must exist locally.');
    }
    // Insert spaces for source line breaks before extracting the visible role.
    $role = $plain(str_replace(['<br>', '<br/>', '<br />'], ' ', $document->saveHTML($caption)));
    $cards[] = [
        'image' => $imageId,
        'contentHtml' => '<h3>' . esc_html($name) . '</h3><p>' . esc_html($role) . '</p><p class="document-footer"><span class="document-action">Read blog <span class="document-format">PDF</span><span aria-hidden="true">→</span></span></p>',
        'link' => ['title' => 'Read ' . $name . ' blog (PDF)', 'url' => $link->getAttribute('href'), 'target' => ''],
    ];
}
if (count($cards) !== 5) {
    WP_CLI::error('All five source research blogs must be retained.');
}
$findings = $value($original[6], 'contentHtml');
$split = strpos($findings, '<p>NIHR will review');
if ($split === false) {
    WP_CLI::error('The source funding information could not be separated safely.');
}
$rows = [
    ['acf_fc_layout' => 'BlockHero', 'displayStyle' => 'phirstInterior', 'title' => $value($original[0], 'title'), 'image' => (int) $value($original[0], 'image'), 'contentHtml' => $value($original[1], 'contentHtml')],
    $text('<h2>Research for local government</h2>' . $value($original[2], 'contentHtml')),
    $text($value($original[3], 'contentHtml')),
    ['acf_fc_layout' => 'gridImageText', 'dataSource' => 'manual', 'preContentHtml' => '<h2>Research blogs</h2><p>Read reflections from people involved in PHIRST evaluations</p>', 'titleAlignment' => 'left', 'items' => $cards, 'options' => ['displayStyle' => 'phirstDocuments', 'theme' => 'putty', 'maxColumns' => 3, 'card' => 1]],
    $text($value($original[5], 'contentHtml'), 'phirstEditorial', 'uniform-lilac'),
    $text(substr($findings, 0, $split)),
    $text('<h2>Work with PHIRST</h2>' . substr($findings, $split) . '<p><a class="button" href="' . esc_url(get_permalink(get_page_by_path('contact-us'))) . '">Contact the team</a></p>', 'phirstCallout', 'navy'),
];
update_post_meta($page->ID, '_phirst_professionals_before', wp_slash($original));
update_field('pageComponents', $rows, $page->ID);
update_post_meta($page->ID, '_phirst_professionals_layout', '1.0.0');
phirst_update_search_index($page->ID);
WP_CLI::success('Professionals page refined: source text and all five PDFs retained in seven editable components.');
