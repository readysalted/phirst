<?php

/** Read-only verification of source identities, source text, themes and media. */

if (!defined('WP_CLI') || !WP_CLI) {
    exit;
}
$directory = realpath($args[0] ?? '') ?: '';
if (!$directory || !is_file($directory . '/legacy-wxr.json') || str_starts_with($directory . '/', get_stylesheet_directory() . '/')) {
    WP_CLI::error('Provide a private source directory outside the theme repository.');
}
$source = json_decode(file_get_contents($directory . '/legacy-wxr.json'), true, 512, JSON_THROW_ON_ERROR);
$report = ['counts' => [], 'errors' => [], 'textReview' => [], 'externalLegacyLinks' => []];
$plain = static function (string $html): string {
    $html = preg_replace('#<(script|style)\b[^>]*>.*?</\1>#si', '', $html);
    return trim(preg_replace('/[^\p{L}\p{N}]+/u', ' ', mb_strtolower(html_entity_decode(wp_strip_all_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8'))));
};
$flatten = static function (mixed $value) use (&$flatten): string {
    if (is_array($value)) {
        return implode(' ', array_map($flatten, $value));
    }
    return is_scalar($value) ? (string) $value : '';
};
foreach ($source['items'] as $item) {
    if (in_array($item['id'], [5165, 5713], true)) {
        continue;
    }
    $matches = get_posts(['post_type' => ['page', 'post', 'person'], 'post_status' => 'publish', 'posts_per_page' => -1, 'meta_key' => '_phirst_source_id', 'meta_value' => $item['id'], 'fields' => 'ids']);
    if (count($matches) !== 1) {
        $report['errors'][] = 'Expected one published destination for source ' . $item['id'];
        continue;
    }
    $id = $matches[0];
    $report['urls'][] = get_permalink($id);
    $rows = get_field('pageComponents', $id, false) ?: [];
    $rendered = $flatten($rows);
    $destinationText = $plain($rendered);
    if (count($rows) < 2) {
        $report['errors'][] = 'No content components: ' . $item['id'];
    }
    $isEvaluation = ($item['meta']['_wp_page_template'] ?? '') === 'templates/evaluating.php';
    $kind = $isEvaluation ? 'evaluations' : ($item['type'] === 'staff' ? 'people' : ($item['type'] === 'post' ? 'news' : 'otherPages'));
    $report['counts'][$kind] = ($report['counts'][$kind] ?? 0) + 1;
    if ($isEvaluation) {
        $terms = wp_get_object_terms($id, 'evaluation_theme', ['fields' => 'slugs']);
        $expected = array_map(static fn (array $term): string => $term['slug'] === 'digitial-and-online-initiatives' ? 'digital-and-online-initiatives' : $term['slug'], array_filter($item['terms'], static fn (array $term): bool => $term['taxonomy'] === 'theme'));
        sort($terms);
        $expected = array_values(array_unique($expected));
        sort($expected);
        if ($terms !== $expected || !$terms) {
            $report['errors'][] = 'Theme assignment mismatch: ' . $item['id'];
        }
    }
    if (!get_post_meta($id, 'phirstSeoTitle', true)) {
        $report['errors'][] = 'SEO title missing: ' . $item['id'];
    }
    $html = $item['content'];
    foreach ($item['meta'] as $key => $value) {
        if (preg_match('/^(research_research_\d+_text|accordion_\d+_input|fusion|short_description|quote_quote)$/', $key)) {
            $html .= '\n' . wpautop($value);
        }
    }
    preg_match_all('#<(p|li|h[2-6]|figcaption)\b[^>]*>(.*?)</\1>#si', $html, $paragraphs);
    if (!in_array($item['id'], [3139, 9616, 5171, 6158], true)) {
        foreach ($paragraphs[2] as $paragraph) {
            $needle = $plain($paragraph);
            if (mb_strlen($needle) > 12 && !str_contains($paragraph, '[video ') && !str_contains($needle, '[tpsearch]') && !str_contains($destinationText, $needle)) {
                $report['textReview'][] = ['sourceId' => $item['id'], 'localId' => $id, 'text' => $needle];
            }
        }
    }
    preg_match_all('#https?://phirst\.nihr\.ac\.uk/[^\s<>"\']+#', $rendered, $links);
    foreach (array_unique($links[0]) as $url) {
        $report['externalLegacyLinks'][$url][] = $item['id'];
    }
}
$themes = get_terms(['taxonomy' => 'evaluation_theme', 'hide_empty' => true]);
$report['counts']['themes'] = count($themes);
$report['urls'][] = home_url('/');
foreach ($themes as $term) {
    $id = (int) get_term_meta($term->term_id, '_phirst_landing_page', true);
    $report['urls'][] = get_permalink($id);
    if (!$id || get_post_status($id) !== 'publish' || (int) get_post_meta($id, 'evaluationThemeTerm', true) !== $term->term_id) {
        $report['errors'][] = 'Broken theme landing page: ' . $term->slug;
    }
}
foreach (get_posts(['post_type' => 'attachment', 'post_status' => 'inherit', 'posts_per_page' => -1, 'meta_key' => '_phirst_legacy_media_url', 'fields' => 'ids']) as $id) {
    $file = get_attached_file($id);
    if (!$file || !is_file($file) || !filesize($file)) {
        $report['errors'][] = 'Missing imported file: ' . $id;
    }
    $report['counts']['media'] = ($report['counts']['media'] ?? 0) + 1;
}
foreach (['impact', 'site-map', 'cookie-policy', 'news-events', 'staff'] as $slug) {
    $page = get_page_by_path($slug);
    $report['urls'][] = get_permalink($page);
}
if (count($report['urls']) !== count(array_unique($report['urls']))) {
    $report['errors'][] = 'Published content has colliding destination URLs.';
}
$report['homeLayouts'] = array_column(get_field('pageComponents', (int) get_option('page_on_front'), false), 'acf_fc_layout');
file_put_contents($directory . '/verification-report.json', wp_json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
WP_CLI::log(wp_json_encode($report['counts']));
WP_CLI::log('Errors: ' . count($report['errors']) . '; source text review: ' . count($report['textReview']) . '; remaining legacy destinations: ' . count($report['externalLegacyLinks']));
if ($report['errors']) {
    WP_CLI::error(implode('\n', $report['errors']));
}
WP_CLI::success('Source identities, evaluation theme assignments, SEO fields and imported files verified.');
