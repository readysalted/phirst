<?php

namespace Flynt\Components\NavigationBurger;

use Flynt\Utils\Asset;
use Flynt\Utils\Options;
use Timber\Timber;

add_action('init', function (): void {
    register_nav_menus([
        'navigation_burger' => __('Navigation Burger', 'flynt')
    ]);
});

add_filter('Flynt/addComponentData?name=NavigationBurger', function (array $data): array {
    $data['menu'] = Timber::get_menu('navigation_burger') ?? Timber::get_pages_menu();
    $data['logo'] = [
        'markSrc' => Asset::requireUrl('assets/images/phirst/nihr-logo.png'),
        'wordmarkSrc' => Asset::requireUrl('assets/images/phirst/phirst-wordmark.png'),
        'alt' => __('Public Health Intervention Responsive Studies Teams (PHIRST)', 'flynt'),
    ];
    $data['searchIconUrl'] = Asset::requireUrl('assets/images/phirst/search.svg');

    return $data;
});

Options::addTranslatable('NavigationBurger', [
    [
        'label' => __('Labels', 'flynt'),
        'name' => 'labelsTab',
        'type' => 'tab',
        'placement' => 'top',
        'endpoint' => 0
    ],
    [
        'label' => '',
        'name' => 'labels',
        'type' => 'group',
        'sub_fields' => [
            [
                'label' => __('Aria Label', 'flynt'),
                'name' => 'ariaLabel',
                'type' => 'text',
                'default_value' => __('Main Navigation', 'flynt'),
                'required' => 1,
                'wrapper' => [
                    'width' => '50',
                ],
            ],
            [
                'label' => __('Toggle Menu', 'flynt'),
                'name' => 'toggleMenu',
                'type' => 'text',
                'default_value' => __('Toggle Menu', 'flynt'),
                'required' => 1,
                'wrapper' => [
                    'width' => '50',
                ],
            ],
        ],
    ],
]);
