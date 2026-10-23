<?php

/**
 * PHIRST evaluations retain their legacy page URLs and gain editable themes.
 */

use ACFComposer\ACFComposer;

add_action('init', function (): void {
    register_taxonomy('evaluation_theme', ['page'], [
        'label' => __('Evaluation Themes', 'flynt'),
        'labels' => [
            'singular_name' => __('Evaluation Theme', 'flynt'),
            'add_new_item' => __('Add Evaluation Theme', 'flynt'),
        ],
        'public' => false,
        'show_ui' => true,
        'show_admin_column' => true,
        'show_in_rest' => true,
        'hierarchical' => true,
        'rewrite' => false,
        'meta_box_cb' => false,
    ]);
    register_taxonomy_for_object_type('post_tag', 'page');
    register_taxonomy_for_object_type('post_tag', 'person');
});

add_action('Flynt/afterRegisterComponents', function (): void {
    ACFComposer::registerFieldGroup([
        'name' => 'phirstContentDetails',
        'title' => __('PHIRST Content Details', 'flynt'),
        'fields' => [
            [
                'name' => 'phirstPageKind',
                'label' => __('Page role', 'flynt'),
                'type' => 'select',
                'choices' => [
                    'standard' => __('Content page', 'flynt'),
                    'evaluation' => __('Evaluation', 'flynt'),
                    'theme' => __('Evaluation theme landing page', 'flynt'),
                    'archive' => __('Content listing', 'flynt'),
                ],
                'default_value' => 'standard',
            ],
            [
                'name' => 'evaluationThemes',
                'label' => __('Evaluation themes', 'flynt'),
                'type' => 'taxonomy',
                'taxonomy' => 'evaluation_theme',
                'field_type' => 'multi_select',
                'return_format' => 'id',
                'save_terms' => 1,
                'load_terms' => 1,
                'add_term' => 1,
                'instructions' => __('An evaluation may appear in more than one theme. Theme pages update automatically.', 'flynt'),
            ],
            [
                'name' => 'evaluationThemeTerm',
                'label' => __('Theme shown on this landing page', 'flynt'),
                'type' => 'taxonomy',
                'taxonomy' => 'evaluation_theme',
                'field_type' => 'select',
                'return_format' => 'id',
                'allow_null' => 1,
                'save_terms' => 0,
                'load_terms' => 0,
            ],
            [
                'name' => 'evaluationStatusText',
                'label' => __('Evaluation status / completion date', 'flynt'),
                'type' => 'text',
            ],
            [
                'name' => 'phirstSeoTitle',
                'label' => __('SEO title', 'flynt'),
                'type' => 'text',
            ],
            [
                'name' => 'phirstSeoDescription',
                'label' => __('Meta description', 'flynt'),
                'type' => 'textarea',
                'rows' => 3,
                'new_lines' => '',
            ],
            [
                'name' => 'phirstSourceUrl',
                'label' => __('Original PHIRST URL', 'flynt'),
                'type' => 'url',
                'readonly' => 1,
                'instructions' => __('Source reference for content review. This does not change the public URL.', 'flynt'),
            ],
        ],
        'location' => [
            [['param' => 'post_type', 'operator' => '==', 'value' => 'page']],
            [['param' => 'post_type', 'operator' => '==', 'value' => 'post']],
            [['param' => 'post_type', 'operator' => '==', 'value' => 'person']],
        ],
    ]);
});

/**
 * Provide theme-aware breadcrumbs while preserving existing evaluation URLs.
 */
function phirst_content_breadcrumbs(int $postId): array
{
    $items = [['title' => __('Home', 'flynt'), 'url' => home_url('/')]];
    $post = get_post($postId);
    if (!$post) {
        return $items;
    }
    if ($post->post_type === 'post') {
        $news = get_page_by_path('news-events');
        if ($news) {
            $items[] = ['title' => $news->post_title, 'url' => get_permalink($news)];
        }
    } else {
        foreach (array_reverse(get_post_ancestors($postId)) as $ancestor) {
            $items[] = ['title' => get_the_title($ancestor), 'url' => get_permalink($ancestor)];
        }
    }
    if (get_post_meta($postId, 'phirstPageKind', true) === 'evaluation') {
        $terms = wp_get_object_terms($postId, 'evaluation_theme', ['orderby' => 'name']);
        if (!is_wp_error($terms) && $terms) {
            $themePage = (int) get_term_meta($terms[0]->term_id, '_phirst_landing_page', true);
            if ($themePage && get_post_status($themePage) === 'publish') {
                $items[] = ['title' => get_the_title($themePage), 'url' => get_permalink($themePage)];
            }
        }
    }
    $items[] = ['title' => get_the_title($postId), 'url' => ''];
    return $items;
}

add_action('pre_get_posts', function (WP_Query $query): void {
    if (!is_admin() && $query->is_main_query() && $query->is_tag()) {
        $query->set('post_type', ['post', 'page', 'person']);
    }
});

/** Index visible component text because Flynt pages do not use the core editor. */
function phirst_update_search_index(int $postId): void
{
    if (!in_array(get_post_type($postId), ['page', 'post', 'person'], true) || wp_is_post_revision($postId)) {
        return;
    }
    $text = [];
    $collect = function (array $values) use (&$collect, &$text): void {
        foreach ($values as $key => $value) {
            if (is_array($value)) {
                $collect($value);
            } elseif (is_string($value) && preg_match('/(?:^|_)(?:contentHtml|preContentHtml|title|heading|position|quoteHtml|text)$/', (string) $key)) {
                $text[] = $value;
            }
        }
    };
    $rows = get_field('pageComponents', $postId, false) ?: [];
    $collect($rows);
    foreach ($rows as $row) {
        if (($row['acf_fc_layout'] ?? '') !== 'blockWysiwyg') {
            continue;
        }
        foreach ($row as $key => $value) {
            if (str_ends_with((string) $key, '_contentHtml') && is_string($value)) {
                $excerpt = trim(wp_strip_all_tags($value));
                if (str_word_count($excerpt) >= 20) {
                    update_post_meta($postId, '_phirst_search_excerpt', wp_slash($excerpt));
                    break 2;
                }
            }
        }
    }
    $plain = html_entity_decode(wp_strip_all_tags(implode(' ', $text)), ENT_QUOTES | ENT_HTML5, 'UTF-8');
    update_post_meta($postId, '_phirst_search_text', wp_slash(trim(preg_replace('/\s+/u', ' ', $plain))));
}

add_action('acf/save_post', function ($postId): void {
    if (is_numeric($postId)) {
        phirst_update_search_index((int) $postId);
    }
}, 30);

add_filter('posts_search', function (string $search, WP_Query $query): string {
    if (is_admin() || !$query->is_main_query() || !$query->is_search() || !$query->get('s')) {
        return $search;
    }
    global $wpdb;
    $conditions = [];
    foreach ($query->get('search_terms') ?: [$query->get('s')] as $term) {
        $exclude = str_starts_with($term, '-');
        $term = $exclude ? substr($term, 1) : $term;
        $like = '%' . $wpdb->esc_like($term) . '%';
        $conditions[] = $wpdb->prepare('phirst_search.meta_value ' . ($exclude ? 'NOT LIKE' : 'LIKE') . ' %s', $like);
    }
    $indexed = "EXISTS (SELECT 1 FROM {$wpdb->postmeta} phirst_search WHERE phirst_search.post_id = {$wpdb->posts}.ID AND phirst_search.meta_key = '_phirst_search_text' AND " . implode(' AND ', $conditions) . ')';
    if (!is_user_logged_in()) {
        $indexed .= " AND ({$wpdb->posts}.post_password = '')";
    }
    $default = preg_replace('/^\s*AND\s*/i', '', $search, 1);
    return $default ? " AND (({$default}) OR ({$indexed}))" : " AND ({$indexed})";
}, 20, 2);

add_filter('get_the_excerpt', function (string $excerpt, WP_Post $post): string {
    if ($post->post_excerpt === '') {
        $componentExcerpt = get_post_meta($post->ID, '_phirst_search_excerpt', true);
        if ($componentExcerpt) {
            return wp_trim_words($componentExcerpt, 50);
        }
    }
    return $excerpt;
}, 10, 2);
