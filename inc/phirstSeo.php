<?php

/**
 * Keep imported SEO editable and usable without requiring a licensed plugin.
 * Equivalent Yoast fields are retained by the importer for future activation.
 */

add_filter('pre_get_document_title', function (string $title): string {
    if (is_singular() && !defined('WPSEO_VERSION')) {
        $imported = get_post_meta(get_queried_object_id(), 'phirstSeoTitle', true);
        if ($imported) {
            return wp_strip_all_tags($imported);
        }
    }
    return $title;
});

add_action('wp_head', function (): void {
    if (!is_singular() || defined('WPSEO_VERSION')) {
        return;
    }
    $id = get_queried_object_id();
    $description = get_post_meta($id, 'phirstSeoDescription', true);
    $title = get_post_meta($id, 'phirstSeoTitle', true);
    if (!$description && !$title) {
        return;
    }
    if ($description) {
        printf('<meta name="description" content="%s">' . "\n", esc_attr($description));
        printf('<meta property="og:description" content="%s">' . "\n", esc_attr($description));
    }
    printf('<meta property="og:title" content="%s">' . "\n", esc_attr($title ?: get_the_title($id)));
    printf('<meta property="og:url" content="%s">' . "\n", esc_url(get_permalink($id)));
    printf('<meta property="og:type" content="%s">' . "\n", get_post_type($id) === 'post' ? 'article' : 'website');
    printf('<meta property="og:site_name" content="%s">' . "\n", esc_attr(get_bloginfo('name')));
    if (has_post_thumbnail($id)) {
        printf('<meta property="og:image" content="%s">' . "\n", esc_url(get_the_post_thumbnail_url($id, 'large')));
    }
    echo '<meta name="twitter:card" content="summary_large_image">' . "\n";
}, 5);

add_filter('wp_robots', function (array $robots): array {
    if (is_singular() && get_post_meta(get_queried_object_id(), '_phirst_source_noindex', true)) {
        $robots['noindex'] = true;
        unset($robots['index']);
    }
    return $robots;
});
