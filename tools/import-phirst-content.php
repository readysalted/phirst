<?php

/**
 * wp eval-file tools/import-phirst-content.php /absolute/private/source-directory
 * Add reset-components only when intentionally replacing already imported rows.
 */

if (!defined('WP_CLI') || !WP_CLI) {
    exit;
}

$directory = realpath($args[0] ?? '') ?: '';
if ($directory && str_starts_with($directory . '/', get_stylesheet_directory() . '/')) {
    WP_CLI::error('Keep private source exports outside the theme Git repository.');
}
if (!$directory || !is_file($directory . '/legacy-wxr.json') || !is_file($directory . '/manifest.json')) {
    WP_CLI::error('Provide the private source directory containing manifest.json and legacy-wxr.json.');
}
if (!function_exists('update_field')) {
    WP_CLI::error('Activate ACF Pro before importing PHIRST.');
}

require_once __DIR__ . '/PhirstLegacyImporter.php';
$importer = new \Flynt\Migration\PhirstLegacyImporter($directory, in_array('reset-components', $args, true));
$report = $importer->run();
WP_CLI::success(sprintf(
    'Imported %d evaluations, %d pages, %d news posts, %d people and %d theme pages. Home preserved: %s.',
    $report['evaluations'],
    $report['pages'],
    $report['posts'],
    $report['people'],
    $report['themes'],
    $report['homePreserved'] ? 'yes' : 'NO'
));
WP_CLI::log('Media imported in this run: ' . $report['media'] . '; source warnings: ' . count($report['warnings']));
