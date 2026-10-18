<?php

/**
 * Import the original partner assets bundled with the theme, without hotlinking.
 * Shared by the fresh-site seed and the targeted homepage partner importer.
 */

if (!defined('WP_CLI') || !WP_CLI) {
    exit;
}

/**
 * Return ACF items backed by local Media Library attachments.
 */
function phirstPartnerItems(): array
{
    require_once ABSPATH . 'wp-admin/includes/image.php';
    require_once ABSPATH . 'wp-admin/includes/file.php';
    require_once ABSPATH . 'wp-admin/includes/media.php';

    $directory = get_stylesheet_directory() . '/assets/images/phirst/partners/';
    $partners = json_decode(file_get_contents($directory . 'manifest.json'), true, 512, JSON_THROW_ON_ERROR);
    $items = [];
    foreach ($partners as $partner) {
        $existing = get_posts([
            'post_type' => 'attachment',
            'post_status' => 'inherit',
            'posts_per_page' => 1,
            'meta_key' => '_phirst_partner_source',
            'meta_value' => $partner['source'],
            'fields' => 'ids',
        ]);
        $id = $existing ? (int) $existing[0] : 0;
        if (!$id) {
            $temporary = wp_tempnam($partner['file']);
            if (!$temporary || !copy($directory . $partner['file'], $temporary)) {
                WP_CLI::error('Cannot import partner logo: ' . $partner['file']);
            }
            // Permit SVG only for these bundled, reviewed originals during this
            // import. This does not change the site's normal upload permissions.
            $allowSvg = static fn (array $mimes): array => array_merge($mimes, ['svg' => 'image/svg+xml']);
            add_filter('upload_mimes', $allowSvg);
            try {
                $id = media_handle_sideload(
                    ['name' => 'phirst-partner-' . $partner['file'], 'tmp_name' => $temporary],
                    0,
                    $partner['name']
                );
            } finally {
                remove_filter('upload_mimes', $allowSvg);
            }
            if (is_wp_error($id)) {
                @unlink($temporary);
                WP_CLI::error($id->get_error_message());
            }
            update_post_meta($id, '_phirst_partner_source', $partner['source']);
            update_post_meta($id, '_wp_attachment_image_alt', $partner['name']);
            if (str_ends_with($partner['file'], '.svg')) {
                wp_update_attachment_metadata($id, [
                    'width' => $partner['width'],
                    'height' => $partner['height'],
                    'file' => get_post_meta($id, '_wp_attached_file', true),
                ]);
            }
        }
        $items[] = ['image' => (int) $id];
    }
    return $items;
}
