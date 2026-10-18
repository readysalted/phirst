<?php

/**
 * Set up a fresh PHIRST development site: wp eval-file tools/seed-phirst-site.php.
 * Existing seeded content is preserved; pass reset-home to restore the design.
 */

if (!defined('WP_CLI') || !WP_CLI) {
    exit;
}

if (!function_exists('update_field') || !function_exists('acf_add_local_field_group')) {
    WP_CLI::error('Activate Advanced Custom Fields Pro before seeding PHIRST.');
}

require_once ABSPATH . 'wp-admin/includes/image.php';
require_once ABSPATH . 'wp-admin/includes/file.php';
require_once ABSPATH . 'wp-admin/includes/media.php';

function phirstSeedPage(string $slug, string $title): int
{
    $page = get_page_by_path($slug, OBJECT, 'page');
    if ($page) {
        if ($page->post_status === 'draft' && $slug === 'privacy-policy') {
            wp_update_post(['ID' => $page->ID, 'post_status' => 'publish', 'post_name' => $slug, 'post_content' => '']);
        }
        return (int) $page->ID;
    }
    $id = wp_insert_post([
        'post_type' => 'page',
        'post_status' => 'publish',
        'post_name' => $slug,
        'post_title' => $title,
        'post_content' => '',
    ], true);
    if (is_wp_error($id)) {
        WP_CLI::error($id->get_error_message());
    }
    update_field('show_block', 0, $id);
    return (int) $id;
}

function phirstSeedImage(string $file, string $alt): int
{
    $existing = get_posts([
        'post_type' => 'attachment',
        'post_status' => 'inherit',
        'posts_per_page' => 1,
        'meta_key' => '_phirst_design_asset',
        'meta_value' => $file,
        'fields' => 'ids',
    ]);
    if ($existing) {
        return (int) $existing[0];
    }
    $source = get_stylesheet_directory() . '/assets/images/phirst/' . $file;
    $temporary = wp_tempnam($file);
    if (!$temporary || !copy($source, $temporary)) {
        WP_CLI::error('Cannot import design asset: ' . $file);
    }
    $id = media_handle_sideload(['name' => 'phirst-' . $file, 'tmp_name' => $temporary], 0, $alt);
    if (is_wp_error($id)) {
        @unlink($temporary);
        WP_CLI::error($id->get_error_message());
    }
    update_post_meta($id, '_phirst_design_asset', $file);
    update_post_meta($id, '_wp_attachment_image_alt', $alt);
    return (int) $id;
}

function phirstSeedOptions(string $scope, array $fields): void
{
    foreach ($fields as $name => $value) {
        update_field('translatable_' . $scope . '_' . $name, $value, 'option');
    }
}

$pages = [];
foreach (
    [
    'home' => 'Home',
    'evaluations' => 'Evaluations',
    'health-care-professionals' => 'Health & Care Professionals',
    'public-partnerships' => 'Public Partnerships',
    'impact' => 'Impact',
    'about-us' => 'About us',
    'contact' => 'Contact',
    'news-events' => 'News & Events',
    'site-map' => 'Site Map',
    'privacy-policy' => 'Privacy Policy',
    'cookie-policy' => 'Cookie Policy',
    'terms' => 'Terms',
    'accessibility' => 'Accessibility',
    ] as $slug => $title
) {
    $pages[$slug] = phirstSeedPage($slug, $title);
}

$home = $pages['home'];
if (get_post_meta($home, '_phirst_seeded', true) && !in_array('reset-home', $args ?? [], true)) {
    WP_CLI::success('PHIRST is already seeded. Existing content preserved.');
    return;
}

$imageSpecs = [
    'hero.png' => 'Public health researchers discussing their work at a meeting',
    'webinar.png' => 'Economic evaluation: balancing health, travel and public investment',
    'fuse.png' => 'Fuse – Centre for Translational Research in Public Health',
    'newcastle.png' => 'Newcastle University',
    'bristol.png' => 'University of Bristol',
    'lilac.png' => 'PHIRST LiLaC',
    'lsbu.png' => 'London South Bank University',
    'video-poster.png' => 'We are NIHR PHIRST – watch our introduction',
    'professionals.png' => 'Health and care professionals discussing research',
    'partnerships.png' => 'Members of the public sharing their views with researchers',
    'news-webinar.png' => 'A speaker presenting to a small group',
    'newsletter.png' => 'NIHR newsletter displayed on a mobile phone',
];
$images = [];
foreach ($imageSpecs as $file => $alt) {
    $images[$file] = phirstSeedImage($file, $alt);
}

$link = static fn (string $slug, string $title): array => [
    'title' => $title,
    'url' => get_permalink($pages[$slug]),
    'target' => '',
];
$lorem = 'Lorem ipsum dolor sit amet, consectetur adipiscing elit, sed do eiusmod tempor incididunt ut labore';
$newsTitle = 'PHIRST Webinar, Meaningful and pragmatic health economics in PHIRST evaluations';
$newsBody = 'Meaningful and pragmatic health economics in PHIRST evaluations. Join our next webinar on Thursday 5 November, 2pm to 3.30pm on Teams. This introductory session explores how economic evaluation can be applied to local government evaluations, drawing on three projects from the PHIRST portfolio. Reserve your place on Eventbrite.';
$news = get_posts(['post_type' => 'post', 'name' => 'phirst-webinar-economic-evaluation', 'numberposts' => 1]);
$newsId = $news ? (int) $news[0]->ID : wp_insert_post([
    'post_type' => 'post',
    'post_status' => 'publish',
    'post_name' => 'phirst-webinar-economic-evaluation',
    'post_title' => $newsTitle,
    'post_content' => '<p>' . $newsBody . '</p>',
    'post_excerpt' => $newsBody,
]);
set_post_thumbnail($newsId, $images['news-webinar.png']);

$rows = [
    [
        'acf_fc_layout' => 'BlockImageTextHero',
        'contentHtml' => '<h1>Public Health Intervention Responsive Studies Teams (PHIRST)</h1><p>Public Health Intervention Responsive Studies Teams provide timely and accessible evaluations of public health interventions to local authorities.</p>',
        'image' => $images['hero.png'],
        'options' => ['displayStyle' => 'homepage', 'theme' => 'navy'],
    ],
    [
        'acf_fc_layout' => 'blockFeedbackBanner',
        'message' => 'We would love to hear your feedback to help us shape our website.',
        'link' => $link('contact', 'Click here'),
    ],
    [
        'acf_fc_layout' => 'blockImageText',
        'imagePosition' => 'left',
        'image' => $images['webinar.png'],
        'contentHtml' => '<p class="eyebrow">Latest</p><h2>Webinar, Economic Evaluation</h2><p>' . $lorem . ' et dolore magna aliqua. Ut enim ad minim veniam, quis nostrud exercitation ullamco laboris nisi ut aliquip ex ea commodo consequat.</p><p><a class="button" href="https://www.eventbrite.co.uk/e/meaningful-and-pragmatic-health-economics-in-phirst-evaluations-tickets-2001873710156">Register Now</a></p>',
        'options' => ['displayStyle' => 'phirstLatest', 'theme' => 'glove-blue'],
    ],
    [
        'acf_fc_layout' => 'blockWysiwyg',
        'contentHtml' => '<h2>PHIRST Evaluations</h2><p>PHIRST teams have evaluated a range of public health initiatives, from ways to address gambling harms through to active travel schemes. Exploring the impact of weight management programmes and moving the delivery of drug and alcohol services from in-person to online. Find out more on our <a href="' . esc_url(get_permalink($pages['evaluations'])) . '">evaluations page.</a></p>',
        'options' => ['displayStyle' => 'phirstIntro', 'size' => 'medium', 'align' => 'center', 'textAlign' => 'left'],
    ],
    [
        'acf_fc_layout' => 'listLogos',
        'items' => array_map(static fn (string $file): array => ['image' => $images[$file]], ['fuse.png', 'newcastle.png', 'bristol.png', 'lilac.png', 'lsbu.png']),
        'options' => ['displayStyle' => 'homepagePartners', 'card' => 0],
    ],
    [
        'acf_fc_layout' => 'blockVideoOembed',
        'posterImage' => $images['video-poster.png'],
        'oembed' => 'https://www.youtube.com/watch?v=ZfOqd7JJCrY',
        'options' => ['displayStyle' => 'phirstVideo', 'size' => 'wide'],
    ],
    [
        'acf_fc_layout' => 'gridImageText',
        'items' => [
            ['image' => $images['professionals.png'], 'contentHtml' => '<h2>Health &amp; Care Professionals</h2><p>' . $lorem . '</p>', 'link' => $link('health-care-professionals', 'Health & Care Professionals')],
            ['image' => $images['partnerships.png'], 'contentHtml' => '<h2>Public Partnerships</h2><p>' . $lorem . '</p>', 'link' => $link('public-partnerships', 'Public Partnerships')],
        ],
        'options' => ['displayStyle' => 'phirstCards', 'maxColumns' => 2, 'card' => 1, 'theme' => 'glove-blue'],
    ],
    [
        'acf_fc_layout' => 'BlockPostTabs',
        'contentType' => 0,
        'quotes' => [
            ['quoteHtml' => '<p>Local authorities have huge opportunities to influence health and health inequalities but without an evidence-base they cannot maximise these opportunities</p><p>Dr Helen Walters, Public Health Consultant Advisor, NIHR</p>'],
        ],
        'options' => ['displayStyle' => 'phirstQuotes'],
    ],
    [
        'acf_fc_layout' => 'BlockPostTabs',
        'text' => 'Latest News',
        'contentType' => 1,
        'pullLatestFromCategory' => 0,
        'relationship' => [$newsId],
        'buttonLabel' => 'Read the full news story',
        'options' => ['displayStyle' => 'homepage', 'theme' => 'putty'],
    ],
    [
        'acf_fc_layout' => 'blockMailingList',
        'imagePosition' => 'right',
        'image' => $images['newsletter.png'],
        'contentHtml' => '<h2>Sign up to our newsletter</h2><p>Sign up to receive updates, opportunities and news from the NIHR PHIRST lorem ipsum dolor</p><p><a class="button" href="https://phirst.nihr.ac.uk/newsletter-sign-up/">Click here to sign up</a></p>',
        'options' => ['displayStyle' => 'homepage', 'theme' => 'uniform-lilac'],
    ],
];

$group = acf_get_local_field_group('group_pageComponents');
$fields = $group ? acf_get_fields($group) : [];
$selector = $fields[0]['key'] ?? 'pageComponents';
update_field($selector, $rows, $home);
update_field('show_block', 0, $home);
update_post_meta($home, '_phirst_seeded', '1.0.0');

$menu = wp_get_nav_menu_object('PHIRST Main');
$menuId = $menu ? (int) $menu->term_id : wp_create_nav_menu('PHIRST Main');
$existingItems = wp_get_nav_menu_items($menuId) ?: [];
$existingByPage = [];
foreach ($existingItems as $item) {
    $existingByPage[(int) $item->object_id] = (int) $item->ID;
}
foreach (['evaluations', 'health-care-professionals', 'public-partnerships', 'impact', 'about-us'] as $position => $slug) {
    wp_update_nav_menu_item($menuId, $existingByPage[$pages[$slug]] ?? 0, [
        'menu-item-object-id' => $pages[$slug],
        'menu-item-object' => 'page',
        'menu-item-type' => 'post_type',
        'menu-item-status' => 'publish',
        'menu-item-position' => $position + 1,
    ]);
}
set_theme_mod('nav_menu_locations', ['navigation_main' => $menuId, 'navigation_burger' => $menuId]);
update_field('show_header_cta', 1, 'option');
update_field('header_cta', $link('contact', 'Contact'), 'option');

phirstSeedOptions('NavigationFooter', [
    'text' => 'Public Health Intervention Responsive Studies Teams (PHIRST)',
    'contentHtml' => '<h4>Contact us</h4><p>Lorem ipsum dolor sit amet<br>Lorem ipsum<br>Lorem ipsum</p><p><br><strong>01234567890</strong><br><a href="mailto:PHIRST@NIHR.ac.uk">PHIRST@NIHR.ac.uk</a></p>',
    'column_two_title' => 'Explore',
    'column_two' => array_map(static fn (string $slug): array => ['link' => $link($slug, get_the_title($pages[$slug]))], ['evaluations', 'health-care-professionals', 'public-partnerships']),
    'column_three_title' => 'Information',
    'column_three' => array_map(static fn (string $slug): array => ['link' => $link($slug, get_the_title($pages[$slug]))], ['impact', 'news-events', 'about-us', 'contact']),
    'column_social_title' => 'Social',
    'column_social' => [
        ['link' => ['url' => 'https://www.youtube.com/channel/UC_xxXUcZ8_zXHkP5ePhD7nQ', 'title' => 'YouTube', 'target' => '_blank']],
        ['link' => ['url' => 'https://www.linkedin.com/company/nihr-phirst', 'title' => 'LinkedIn', 'target' => '_blank']],
    ],
    'funded_by_text' => '<p>' . $lorem . ' et dolore magna aliqua. Ut enim ad minim veniam, quis nostrud exercitation ullamco laboris nisi ut aliquip ex ea commodo consequat.</p><p>' . $lorem . ' et dolore magna aliqua. Ut enim ad minim veniam, quis nostrud exercitation ullamco laboris nisi ut aliquip ex ea commodo consequat.</p>',
    'copyright_text' => 'Public Health Intervention Responsive Studies Teams (PHIRST) © ' . wp_date('Y'),
    'copyright_menu' => array_map(static fn (string $slug): array => ['link' => $link($slug, get_the_title($pages[$slug]))], ['site-map', 'privacy-policy', 'cookie-policy', 'terms', 'accessibility']),
]);

update_option('blogname', 'NIHR PHIRST');
update_option('blogdescription', 'Public Health Intervention Responsive Studies Teams');
update_option('show_on_front', 'page');
update_option('page_on_front', $home);
update_option('permalink_structure', '/%postname%/');
update_option('timezone_string', 'Europe/London');
update_option('blog_public', 0);
flush_rewrite_rules();
WP_CLI::success(sprintf('PHIRST created: Home #%d, %d components, %d design images, %d pages.', $home, count($rows), count($images), count($pages)));
