<?php

/**
 * Replace only the Home partner logos with the original legacy-site assets.
 * wp eval-file tools/import-phirst-partners.php
 */

if (!defined('WP_CLI') || !WP_CLI) {
    exit;
}

if (!function_exists('get_field') || !function_exists('update_sub_field')) {
    WP_CLI::error('Activate Advanced Custom Fields Pro before importing partners.');
}

require_once __DIR__ . '/phirst-partner-assets.php';

$home = (int) get_option('page_on_front');
$rows = get_field('pageComponents', $home) ?: [];
foreach ($rows as $index => $row) {
    if ($row['acf_fc_layout'] !== 'listLogos' || ($row['options']['displayStyle'] ?? '') !== 'homepagePartners') {
        continue;
    }
    $items = phirstPartnerItems();
    update_sub_field(['pageComponents', $index + 1, 'items'], $items, $home);
    WP_CLI::success('Imported ' . count($items) . ' individual partner logos. Other Home sections preserved.');
    return;
}

WP_CLI::error('No homepagePartners ListLogos row found. Run seed-phirst-site.php first.');
