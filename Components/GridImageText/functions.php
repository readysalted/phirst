<?php

namespace Flynt\Components\GridImageText;

use Flynt\FieldVariables;
use Timber\Timber;

add_filter('Flynt/addComponentData?name=GridImageText', function ($data) {
    $source = $data['dataSource'] ?? 'manual';
    if ($source !== 'manual') {
        $query = ['post_type' => 'page', 'post_status' => 'publish', 'posts_per_page' => -1, 'orderby' => 'title', 'order' => 'ASC'];
        if ($source === 'evaluationThemes') {
            $query['meta_key'] = 'phirstPageKind';
            $query['meta_value'] = 'theme';
        } elseif ($source === 'evaluationsInTheme') {
            $theme = (int) (($data['evaluationTheme'] ?? 0) ?: get_post_meta(get_queried_object_id(), 'evaluationThemeTerm', true));
            $query['meta_key'] = 'phirstPageKind';
            $query['meta_value'] = 'evaluation';
            $query['tax_query'] = [['taxonomy' => 'evaluation_theme', 'field' => 'term_id', 'terms' => [$theme]]];
        } elseif ($source === 'childPages') {
            $query['post_parent'] = (int) get_queried_object_id();
        } elseif ($source === 'news') {
            $query['post_type'] = 'post';
            $query['orderby'] = 'date';
            $query['order'] = 'DESC';
        }
        $data['items'] = [];
        foreach (get_posts($query) as $entry) {
            $data['items'][] = [
                'image' => Timber::get_image(get_post_thumbnail_id($entry)),
                'contentHtml' => '<h2>' . esc_html($entry->post_title) . '</h2>',
                'link' => ['url' => get_permalink($entry), 'title' => $entry->post_title, 'target' => ''],
            ];
        }
    }
    if (isset($data['options']['maxColumns'])) {
        $data['maxColumns'] = (string) $data['options']['maxColumns'];
    } else {
        $data['maxColumns'] = '3';
    }

    return $data;
});

function getACFLayout(): array
{
    return [
        'name' => 'gridImageText',
        'label' => __('Grid: Image Text', 'flynt'),
        'sub_fields' => [
            [
                'label' => __('Content source', 'flynt'),
                'name' => 'dataSource',
                'type' => 'select',
                'choices' => [
                    'manual' => __('Manual cards', 'flynt'),
                    'evaluationThemes' => __('All evaluation themes', 'flynt'),
                    'evaluationsInTheme' => __('Evaluations in this theme', 'flynt'),
                    'childPages' => __('Child pages', 'flynt'),
                    'news' => __('All news posts', 'flynt'),
                ],
                'default_value' => 'manual',
                'instructions' => __('Automatic lists reflect published content and theme assignments. There are no visitor filters.', 'flynt'),
            ],
            [
                'label' => __('Evaluation theme', 'flynt'),
                'name' => 'evaluationTheme',
                'type' => 'taxonomy',
                'taxonomy' => 'evaluation_theme',
                'field_type' => 'select',
                'allow_null' => 1,
                'return_format' => 'id',
                'save_terms' => 0,
                'load_terms' => 0,
                'instructions' => __('Leave empty to use the current theme landing page.', 'flynt'),
            ],
            [
                'label' => __('Content', 'flynt'),
                'name' => 'contentTab',
                'type' => 'tab',
                'placement' => 'top',
                'endpoint' => 0
            ],
            [
                'label' => __('Title Alignment', 'flynt'),
                'name' => 'titleAlignment',
                'type' => 'button_group',
                'choices' => [
                    'left' => sprintf('<i class="dashicons dashicons-editor-alignleft" title="%1$s"></i>', __('Align items left', 'flynt')),
                    'center' => sprintf('<i class="dashicons dashicons-editor-aligncenter" title="%1$s"></i>', __('Align items center', 'flynt'))
                ],
                'default_value' => 'left'
            ],
            [
                'label' => __('Title', 'flynt'),
                'instructions' => __('Want to add a headline? And a paragraph? Go ahead! Or just leave it empty and nothing will be shown.', 'flynt'),
                'name' => 'preContentHtml',
                'type' => 'wysiwyg',
                'tabs' => 'visual,text',
                'media_upload' => 0,
                'delay' => 0,
            ],
            [
                'label' => __('Items', 'flynt'),
                'name' => 'items',
                'type' => 'repeater',
                'collapsed' => '',
                'layout' => 'block',
                'button_label' => __('Add Item', 'flynt'),
                'sub_fields' => [
                    [
                        'label' => __('Image (Desktop)', 'flynt'),
                        'instructions' => __('Image-Format: JPG, PNG, SVG, WebP. Aspect Ratio: 16:9', 'flynt'),
                        'name' => 'image',
                        'type' => 'image',
                        'preview_size' => 'medium',
                        'mime_types' => 'jpg,jpeg,png,svg,webp',
                        'required' => 0,
                    ],
                    [
                        'label' => __('Link', 'flynt'),
                        'name' => 'link',
                        'type' => 'link',
                        'return_format' => 'array',
                        'required' => 0,
                    ],
                    [
                        'label' => __('Image (Mobile)', 'flynt'),
                        'instructions' => __('Only for single column layout. Aspect Ratio: 4:3', 'flynt'),
                        'name' => 'imageMobile',
                        'type' => 'image',
                        'preview_size' => 'medium',
                        'mime_types' => 'jpg,jpeg,png,svg,webp',
                        'required' => 0,
                    ],
                    [
                        'label' => __('Text', 'flynt'),
                        'name' => 'contentHtml',
                        'type' => 'wysiwyg',
                        'delay' => 0,
                        'media_upload' => 0,
                        'required' => 1,
                        'wrapper' => [
                            'width' => 60
                        ],
                    ],
                ]
            ],
            [
                'label' => __('Button', 'flynt'),
                'name' => 'button',
                'type' => 'link',
                'return_format' => 'array',
                'instructions' => __('Optional button shown below the grid.', 'flynt'),
            ],
            [
                'label' => __('Options', 'flynt'),
                'name' => 'optionsTab',
                'type' => 'tab',
                'placement' => 'top',
                'endpoint' => 0
            ],
            [
                'label' => '',
                'name' => 'options',
                'type' => 'group',
                'layout' => 'row',
                'sub_fields' => [
                    FieldVariables\getTheme(),
                    [
                        'label' => __('Max Columns', 'flynt'),
                        'name' => 'maxColumns',
                        'type' => 'number',
                        'default_value' => 3,
                        'min' => 1,
                        'max' => 4,
                        'step' => 1
                    ],
                    [
                        'label' => __('Show as Card', 'flynt'),
                        'name' => 'card',
                        'type' => 'true_false',
                        'default_value' => 0,
                        'ui' => 1
                    ],
                    [
                        'label' => __('Display Style', 'flynt'),
                        'name' => 'displayStyle',
                        'type' => 'select',
                        'choices' => [
                            'default' => __('Default', 'flynt'),
                            'phirstCards' => __('PHIRST Audience Cards', 'flynt'),
                            'phirstTiles' => __('PHIRST interior image tiles', 'flynt'),
                            'phirstDocuments' => __('PHIRST document cards', 'flynt'),
                            'homepageFeatures' => __('Homepage Feature Cards', 'flynt'),
                            'homepageProjects' => __('Homepage Project Cards', 'flynt'),
                            'resourceLinks' => __('Resources Link Cards', 'flynt'),
                        ],
                        'default_value' => 'default',
                        'instructions' => __('Use named styles for their matching content. Document cards use five columns on wide screens and stack on phones.', 'flynt'),
                    ]
                ]
            ]
        ]
    ];
}
