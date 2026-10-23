<?php

/** Keep the previous local navigation URLs working after restoring source slugs. */

namespace Flynt\Redirects;

add_action('template_redirect', function (): void {
    if (is_admin() || wp_doing_ajax() || is_preview()) {
        return;
    }
    $requestPath = wp_parse_url(wp_unslash($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH);
    $redirects = get_option('phirst_legacy_redirects', []);
    $id = (int) ($redirects[$requestPath] ?? 0);
    if ($id && get_post_status($id) === 'publish') {
        $destination = get_permalink($id);
        if (wp_parse_url($destination, PHP_URL_PATH) !== $requestPath) {
            wp_safe_redirect($destination, 301, 'PHIRST content migration');
            exit;
        }
    }
}, 1);
