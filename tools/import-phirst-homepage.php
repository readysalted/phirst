<?php

/**
 * Import a small homepage news feed, preserving the approved first-slide artwork.
 * wp eval-file tools/import-phirst-homepage.php
 */

if (!defined('WP_CLI') || !WP_CLI) {
    exit;
}

require_once ABSPATH . 'wp-admin/includes/image.php';
require_once ABSPATH . 'wp-admin/includes/file.php';
require_once ABSPATH . 'wp-admin/includes/media.php';

$home = (int) get_option('page_on_front');
// ACF returns field keys for unformatted Flexible Content. Keep raw attachment
// and relationship IDs, while resolving names before updating nested rows.
function phirstRawFieldNames(array $values): array
{
    $result = [];
    foreach ($values as $key => $value) {
        $field = is_string($key) && str_starts_with($key, 'field_') ? acf_get_field($key) : null;
        $name = $field['name'] ?? $key;
        $result[$name] = is_array($value) ? phirstRawFieldNames($value) : $value;
    }
    return $result;
}
$rawRows = get_field('pageComponents', $home, false);
$rows = is_array($rawRows) ? phirstRawFieldNames($rawRows) : [];
if (!$home || !$rows) {
    WP_CLI::error('Run seed-phirst-site.php first.');
}
$response = wp_remote_get('https://phirst.nihr.ac.uk/wp-json/wp/v2/posts?per_page=3&_embed=wp:featuredmedia', ['timeout' => 30]);
if (is_wp_error($response) || wp_remote_retrieve_response_code($response) !== 200) {
    WP_CLI::error('Cannot read the source news feed. Existing content preserved.');
}
$sourcePosts = json_decode(wp_remote_retrieve_body($response), true);
if (!is_array($sourcePosts) || !$sourcePosts) {
    WP_CLI::error('The source news feed is empty or invalid.');
}

$category = term_exists('phirst-news', 'category');
if (!$category) {
    $category = wp_insert_term('PHIRST News', 'category', ['slug' => 'phirst-news']);
}
if (is_wp_error($category)) {
    WP_CLI::error($category->get_error_message());
}
$categoryId = (int) (is_array($category) ? $category['term_id'] : $category);
$summaries = [
    11939 => 'Discover how PHIRST evaluations can leave practical tools and lasting benefits for local teams. This webinar shares examples and perspectives from public advisers on sustaining the value of evaluation.',
    11935 => 'Explore PHIRST research on services for children and young people, with examples of how young people contributed to evaluations. The session includes presentations and resources from the research teams.',
];
$imported = [];
foreach ($sourcePosts as $source) {
    $sourceId = (int) $source['id'];
    $existing = get_posts([
        'post_type' => 'post',
        'post_status' => 'any',
        'meta_key' => '_phirst_source_post_id',
        'meta_value' => $sourceId,
        'numberposts' => 1,
    ]);
    $approved = get_posts(['post_type' => 'post', 'name' => 'phirst-webinar-economic-evaluation', 'numberposts' => 1]);
    $postId = $existing ? (int) $existing[0]->ID : 0;
    if (!$postId && $sourceId === 12420 && $approved) {
        $postId = (int) $approved[0]->ID;
    }
    if (!$postId) {
        $summary = $summaries[$sourceId] ?? 'Read the latest news and updates from the PHIRST research teams.';
        $postId = wp_insert_post([
            'post_type' => 'post',
            'post_status' => 'publish',
            'post_name' => sanitize_title($source['slug']),
            'post_title' => html_entity_decode(wp_strip_all_tags($source['title']['rendered']), ENT_QUOTES, 'UTF-8'),
            'post_date' => $source['date'],
            'post_content' => '<p>' . esc_html($summary) . '</p>',
            'post_excerpt' => $summary,
        ], true);
        if (is_wp_error($postId)) {
            WP_CLI::error($postId->get_error_message());
        }
    }
    update_post_meta($postId, '_phirst_source_post_id', $sourceId);
    update_field('externalStoryLink', esc_url_raw($source['link']), $postId);
    wp_set_post_categories($postId, [$categoryId]);

    if (!has_post_thumbnail($postId)) {
        $media = $source['_embedded']['wp:featuredmedia'][0] ?? [];
        if (!empty($media['source_url'])) {
            $attachment = media_sideload_image(esc_url_raw($media['source_url']), $postId, wp_strip_all_tags($source['title']['rendered']), 'id');
            if (!is_wp_error($attachment)) {
                update_post_meta($attachment, '_wp_attachment_image_alt', $media['alt_text'] ?: get_the_title($postId));
                set_post_thumbnail($postId, $attachment);
            } else {
                WP_CLI::error('Cannot import the news image: ' . $attachment->get_error_message());
            }
        }
    }
    $imported[] = (int) $postId;
}

foreach ($rows as &$row) {
    if (($row['options']['displayStyle'] ?? '') === 'homepage' && $row['acf_fc_layout'] === 'BlockPostTabs') {
        $row['relationship'] = $imported;
        $row['pullLatestFromCategory'] = 1;
        $row['selectedCategory'] = $categoryId;
        $row['latestPostsCount'] = 3;
        $row['pinnedPost'] = $imported[0];
    }
    if (($row['options']['displayStyle'] ?? '') === 'phirstQuotes' && count($row['quotes']) === 1) {
        $row['quotes'][] = ['quoteHtml' => '<p>We are very grateful to be one of the successful PHIRST applicants.</p><p>Pegs Bailey</p>'];
        $row['quotes'][] = ['quoteHtml' => '<p>Food poverty is a national crisis.</p><p>Marcus Robinson</p>'];
    }
}
unset($row);
update_field('pageComponents', $rows, $home);
WP_CLI::success(sprintf('Homepage feed ready: %d news posts and three testimonials.', count($imported)));
