<?php

/**
 * Complete navigation and replace Figma placeholder copy with source content.
 * Run after import-phirst-content.php. Existing editorial work is preserved.
 */

if (!defined('WP_CLI') || !WP_CLI) {
    exit;
}

$sourceDirectory = realpath($args[0] ?? '') ?: '';
if (!$sourceDirectory || !is_file($sourceDirectory . '/legacy-wxr.json') || str_starts_with($sourceDirectory . '/', get_stylesheet_directory() . '/')) {
    WP_CLI::error('Provide a private source directory outside the theme repository.');
}
$source = json_decode(file_get_contents($sourceDirectory . '/legacy-wxr.json'), true, 512, JSON_THROW_ON_ERROR);
$items = array_column($source['items'], null, 'id');
$posts = get_posts(['post_type' => ['page', 'post', 'person'], 'post_status' => 'publish', 'posts_per_page' => -1, 'meta_key' => '_phirst_source_id']);
$map = [];
foreach ($posts as $post) {
    $map[(int) get_post_meta($post->ID, '_phirst_source_id', true)] = $post->ID;
}
$header = (int) get_post_thumbnail_id($map[5171]);
$hero = static fn (string $title): array => ['acf_fc_layout' => 'BlockHero', 'title' => $title, 'image' => $header, 'displayStyle' => 'phirstInterior'];
$text = static fn (string $html): array => ['acf_fc_layout' => 'blockWysiwyg', 'contentHtml' => $html, 'options' => ['displayStyle' => 'phirstArticle', 'theme' => 'white', 'size' => 'medium']];
$link = static fn (int $id, string $title = ''): array => ['title' => $title ?: get_the_title($id), 'url' => get_permalink($id), 'target' => ''];
$footer = static function (string $name, mixed $value): void {
    update_field('translatable_NavigationFooter_' . $name, $value, 'option');
};

// Restore actual source navigation targets after keeping the source page slugs.
$footer('column_two', array_map(static fn (int $id): array => ['link' => $link($map[$id])], [5171, 5221, 5225]));
$footer('column_three', array_map(static fn (int $id): array => ['link' => $link($id)], [get_page_by_path('impact')->ID, get_page_by_path('news-events')->ID, $map[5251], $map[3139]]));
$footer('copyright_menu', array_map(static fn (int $id): array => ['link' => $link($id)], [get_page_by_path('site-map')->ID, $map[6780], get_page_by_path('cookie-policy')->ID, $map[6777], $map[6767]]));
update_field('header_cta', $link($map[3139], 'Contact'), 'option');
$footer('contentHtml', '<h4>Contact us</h4><p>NIHR Public Health Intervention Responsive Studies Teams (PHIRST)</p><p><a href="mailto:PHIRST@NIHR.ac.uk">PHIRST@NIHR.ac.uk</a></p>');
$footer('funded_by_text', '<p>The National Institute for Health and Care Research (NIHR) funds, enables and delivers world-leading health and social care research that improves people’s health and wellbeing and promotes economic growth.</p><p>The PHIRST scheme connects academic teams with local government organisations to evaluate public health interventions that are already happening across the UK.</p>');
$footer('column_social', [
    ['link' => ['url' => 'https://www.youtube.com/playlist?list=PLcyA3BKfuus0zAMXSkUjqTApoqwwlhSDM', 'title' => 'YouTube', 'target' => '_blank']],
    ['link' => ['url' => 'https://www.linkedin.com/company/nihr-phirst', 'title' => 'LinkedIn', 'target' => '_blank']],
]);

// Keep every homepage layout and image; replace only placeholder prose/old URLs.
$home = (int) get_option('page_on_front');
$rows = get_field('pageComponents', $home, false);
$sourceLinks = [];
foreach ($map as $sourceId => $localId) {
    $sourceLinks[$items[$sourceId]['link']] = get_permalink($localId);
}
$redirects = get_option('phirst_legacy_redirects', []);
$rewrite = static function (mixed $value) use (&$rewrite, $sourceLinks, $redirects): mixed {
    if (is_array($value)) {
        return array_map($rewrite, $value);
    }
    if (!is_string($value)) {
        return $value;
    }
    $value = strtr($value, $sourceLinks);
    foreach ($redirects as $path => $id) {
        $value = str_replace(home_url($path), get_permalink($id), $value);
    }
    return $value;
};
$beforeLayouts = array_column($rows, 'acf_fc_layout');
$rows = $rewrite($rows);
foreach ($rows as &$row) {
    if ($row['acf_fc_layout'] === 'blockImageText') {
        foreach ($row as $key => &$value) {
            if (str_ends_with($key, '_contentHtml') && is_string($value) && stripos($value, 'lorem ipsum') !== false) {
                $value = preg_replace('/Lorem ipsum.*?(?=<a class="button")/si', '<p>Economic Evaluation (EE) is increasingly something that is requested during PHIRST evaluations. This webinar is designed to be an introductory session for those who would like to understand how to apply key principles to local government evaluations.</p>', $value);
            }
        }
        unset($value);
    }
    if ($row['acf_fc_layout'] === 'gridImageText') {
        foreach ($row as $key => &$cards) {
            if (str_ends_with($key, '_items') && is_array($cards)) {
                foreach ($cards as &$card) {
                    foreach ($card as $cardKey => &$value) {
                        if (str_ends_with($cardKey, '_contentHtml') && is_string($value) && stripos($value, 'lorem ipsum') !== false) {
                            $sourceId = str_contains($value, 'Health &amp; Care') ? 5221 : 5225;
                            $value = preg_replace('/Lorem ipsum.*/si', '<p>' . esc_html($items[$sourceId]['meta']['short_description']) . '</p>', $value);
                        }
                    }
                    unset($value);
                }
                unset($card);
            }
        }
        unset($cards);
    }
    if ($row['acf_fc_layout'] === 'blockMailingList') {
        foreach ($row as $key => &$value) {
            if (str_ends_with($key, '_contentHtml') && is_string($value)) {
                $value = str_ireplace('Sign up to receive updates, opportunities and news from the NIHR PHIRST lorem ipsum dolor', 'Sign up to the PHIRST mailing list to receive regular updates.', $value);
            }
        }
        unset($value);
    }
}
unset($row);
update_field('pageComponents', $rows, $home);
if ($beforeLayouts !== array_column(get_field('pageComponents', $home, false), 'acf_fc_layout')) {
    WP_CLI::error('Homepage layout changed unexpectedly.');
}

// The source contact form shortcode is already broken on the source website.
// Use its published email address without adding a new mail service or recipient.
if (!get_post_meta($map[3139], '_phirst_finalized', true)) {
    update_field('pageComponents', [$hero('Contact us'), $text('<h2>Contact PHIRST</h2><p>NIHR Public Health Intervention Responsive Studies Teams (PHIRST)</p><p><a href="mailto:PHIRST@NIHR.ac.uk">PHIRST@NIHR.ac.uk</a></p>')], $map[3139]);
    update_post_meta($map[3139], '_phirst_finalized', 1);
}

// The source publications shortcode has no output. Surface real project reports
// instead, grouped by their evaluation, using the existing accordion component.
if (!get_post_meta($map[9616], '_phirst_finalized', true)) {
    $panels = [];
    foreach ($items as $sourceId => $item) {
        if (($item['meta']['_wp_page_template'] ?? '') !== 'templates/evaluating.php' || !isset($map[$sourceId])) {
            continue;
        }
        $components = get_field('pageComponents', $map[$sourceId], false);
        $html = wp_json_encode($components, JSON_UNESCAPED_SLASHES);
        preg_match_all('#https?://[^\s"<>]+\.(?:pdf|docx?|pptx?|xlsx?)(?:\?[^\s"<>]*)?#i', $html, $matches);
        $urls = array_unique($matches[0]);
        if (!$urls) {
            continue;
        }
        $list = '<ul>';
        foreach ($urls as $url) {
            $label = rawurldecode(basename(wp_parse_url($url, PHP_URL_PATH)));
            $label = trim(preg_replace('/[-_]+/', ' ', $label));
            $list .= '<li><a href="' . esc_url($url) . '">' . esc_html($label) . '</a></li>';
        }
        $list .= '</ul><p><a href="' . esc_url(get_permalink($map[$sourceId])) . '">View evaluation</a></p>';
        $panels[] = ['title' => wp_strip_all_tags($item['title']), 'contentHtml' => $list];
    }
    update_field('pageComponents', [$hero('Publications'), $text('<h2>Evaluation reports and publications</h2><p>Reports and other published outputs from PHIRST evaluations.</p>'), ['acf_fc_layout' => 'accordionDefault', 'accordionPanels' => $panels, 'options' => ['displayStyle' => 'phirstOutputs', 'theme' => 'white']]], $map[9616]);
    update_post_meta($map[9616], '_phirst_finalized', 1);
    WP_CLI::log('Publication groups: ' . count($panels));
}

$impact = get_page_by_path('impact');
if (!get_post_meta($impact->ID, '_phirst_finalized', true)) {
    $cards = [];
    foreach ([5171, 9616, 9001] as $sourceId) {
        $cards[] = ['image' => get_post_thumbnail_id($map[$sourceId]), 'contentHtml' => '<h2>' . esc_html(get_the_title($map[$sourceId])) . '</h2>', 'link' => $link($map[$sourceId])];
    }
    update_field('pageComponents', [$hero('Impact'), $text('<h2>Learning from PHIRST evaluations</h2><p>We work to mobilise the knowledge from our projects to add to the wider public health evidence base. We undertake post-evaluation reviews to explore how recommendations are being taken forward and what impact the evaluation has had.</p>'), ['acf_fc_layout' => 'gridImageText', 'dataSource' => 'manual', 'items' => $cards, 'options' => ['displayStyle' => 'phirstTiles', 'theme' => 'white', 'maxColumns' => 3, 'card' => 1]]], $impact->ID);
    update_field('show_block', 1, $impact->ID);
    update_post_meta($impact->ID, '_phirst_finalized', 1);
}
$cookie = get_page_by_path('cookie-policy');
if (!get_post_meta($cookie->ID, '_phirst_finalized', true)) {
    $privacy = $items[6780]['content'];
    preg_match('#<h3>Cookies</h3>(.*?)<h3>Google Analytics</h3>#si', $privacy, $match);
    update_field('pageComponents', [$hero('Cookie Policy'), $text('<h2>Cookies</h2>' . ($match[1] ?? '') . '<p><a href="' . esc_url(get_permalink($map[6780])) . '">Read the full Privacy Policy</a></p>')], $cookie->ID);
    update_post_meta($cookie->ID, '_phirst_finalized', 1);
}
$siteMap = get_page_by_path('site-map');
if (!get_post_meta($siteMap->ID, '_phirst_finalized', true)) {
    $pages = get_posts(['post_type' => 'page', 'post_status' => 'publish', 'posts_per_page' => -1, 'orderby' => 'title', 'order' => 'ASC']);
    $list = '<h2>Pages</h2><ul>';
    foreach ($pages as $page) {
        if ($page->ID === $siteMap->ID || get_post_meta($page->ID, 'phirstPageKind', true) === 'evaluation') {
            continue;
        }
        $list .= '<li><a href="' . esc_url(get_permalink($page)) . '">' . esc_html($page->post_title) . '</a></li>';
    }
    $list .= '</ul><p>Individual evaluations are listed within their <a href="' . esc_url(get_permalink($map[5171])) . '">evaluation themes</a>.</p>';
    update_field('pageComponents', [$hero('Site Map'), $text($list)], $siteMap->ID);
    update_post_meta($siteMap->ID, '_phirst_finalized', 1);
}
foreach (get_posts(['post_type' => ['page', 'post', 'person'], 'post_status' => 'publish', 'posts_per_page' => -1, 'fields' => 'ids']) as $postId) {
    $components = get_field('pageComponents', $postId, false) ?: [];
    $changed = false;
    $sourceId = (int) get_post_meta($postId, '_phirst_source_id', true);
    $featured = (int) get_post_thumbnail_id($postId);
    if ($sourceId && $sourceId !== 5171 && $featured && $featured !== $header && ($components[0]['acf_fc_layout'] ?? '') === 'BlockHero') {
        foreach ($components[0] as $key => &$value) {
            if (str_ends_with((string) $key, '_image') && (int) $value === $header) {
                $value = $featured;
                $changed = true;
            }
        }
        unset($value);
    }
    foreach ($components as &$component) {
        if (($component['acf_fc_layout'] ?? '') !== 'meetTheTeam') {
            continue;
        }
        foreach ($component as $key => &$value) {
            if (str_ends_with((string) $key, '_preContentHtml') && is_string($value) && $value && !preg_match('/<h[1-6]\b/i', $value)) {
                $value = '<h2>' . esc_html(trim(wp_strip_all_tags($value))) . '</h2>';
                $changed = true;
            }
        }
        unset($value);
    }
    unset($component);
    if ($changed) {
        update_field('pageComponents', $components, $postId);
    }
    phirst_update_search_index((int) $postId);
}
WP_CLI::success('Source copy, navigation, contact, publications and remaining pages completed. Homepage layout preserved.');
