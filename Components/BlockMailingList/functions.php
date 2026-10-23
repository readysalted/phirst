<?php

namespace Flynt\Components\BlockMailingList;

use Flynt\FieldVariables;
use Flynt\Utils\Options;

add_filter('Flynt/addComponentData?name=BlockMailingList', function (array $data): array {
    $data['formId'] = 'phirst-newsletter-' . wp_generate_uuid4();
    $actionHost = wp_parse_url($data['signupForm']['action'] ?? '', PHP_URL_HOST);
    $data['hasSignupForm'] = is_string($actionHost) && preg_match('/(^|\.)list-manage\.com$/i', $actionHost);
    if (!empty($data['isGlobal'])) {
        if (empty($data['imagePosition'])) {
            $data['imagePosition'] = 'right';
        }

        if (empty($data['backgroundColor'])) {
            $data['backgroundColor'] = '#c4c5ff';
        }
    }

    return $data;
});

function getACFLayout(): array
{
    return [
        'name' => 'blockMailingList',
        'label' => __('Block: Mailing List', 'flynt'),
        'sub_fields' => [
            [
                'label' => __('Mailchimp signup form', 'flynt'),
                'name' => 'signupForm',
                'type' => 'group',
                'sub_fields' => [
                    ['name' => 'action', 'label' => __('Form action URL', 'flynt'), 'type' => 'url'],
                    ['name' => 'honeypotName', 'label' => __('Mailchimp honeypot field', 'flynt'), 'type' => 'text'],
                    ['name' => 'consentName', 'label' => __('Mailchimp consent field', 'flynt'), 'type' => 'text'],
                    ['name' => 'consentLabel', 'label' => __('Consent checkbox label', 'flynt'), 'type' => 'text'],
                    ['name' => 'consentHtml', 'label' => __('Consent and privacy text', 'flynt'), 'type' => 'wysiwyg', 'media_upload' => 0],
                    [
                        'name' => 'fields',
                        'label' => __('Form fields', 'flynt'),
                        'type' => 'repeater',
                        'sub_fields' => [
                            ['name' => 'label', 'label' => __('Label', 'flynt'), 'type' => 'text'],
                            ['name' => 'name', 'label' => __('Mailchimp field name', 'flynt'), 'type' => 'text'],
                            ['name' => 'type', 'label' => __('Type', 'flynt'), 'type' => 'select', 'choices' => ['email' => 'Email', 'text' => 'Text', 'select' => 'Select']],
                            ['name' => 'required', 'label' => __('Required', 'flynt'), 'type' => 'true_false'],
                            ['name' => 'choicesText', 'label' => __('Select options, one per line', 'flynt'), 'type' => 'textarea'],
                        ],
                    ],
                ],
            ],
            [
                'label' => __('Content', 'flynt'),
                'name' => 'contentTab',
                'type' => 'tab',
                'placement' => 'top',
                'endpoint' => 0,
            ],
            [
                'label' => __('Image Position', 'flynt'),
                'name' => 'imagePosition',
                'type' => 'button_group',
                'choices' => [
                    'left' => sprintf('<i class=\'dashicons dashicons-align-left\' title=\'%1$s\'></i>', __('Image on the left', 'flynt')),
                    'right' => sprintf('<i class=\'dashicons dashicons-align-right\' title=\'%1$s\'></i>', __('Image on the right', 'flynt'))
                ]
            ],
            [
                'label' => __('Image', 'flynt'),
                'instructions' => __('Image-Format: JPG, PNG, SVG, WebP.', 'flynt'),
                'name' => 'image',
                'type' => 'image',
                'preview_size' => 'medium',
                'required' => 0,
                'mime_types' => 'jpg,jpeg,png,svg,webp',
            ],
            [
                'label' => __('Text', 'flynt'),
                'name' => 'contentHtml',
                'type' => 'wysiwyg',
                'delay' => 0,
                'media_upload' => 0,
                'required' => 1,
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
                    [
                        'label' => __('Background Color', 'flynt'),
                        'name' => 'backgroundColor',
                        'type' => 'color_picker',
                        'default_value' => '#5B5CA0',
                        'enable_opacity' => true,
                        'return_format' => 'string'
                    ],
                    FieldVariables\getTheme(),
                    [
                        'label' => __('Display Style', 'flynt'),
                        'name' => 'displayStyle',
                        'type' => 'select',
                        'choices' => [
                            'default' => __('Default', 'flynt'),
                            'homepage' => __('Homepage Newsletter', 'flynt'),
                            'signupForm' => __('PHIRST newsletter signup form', 'flynt'),
                        ],
                        'default_value' => 'default',
                    ],
                ]
            ]
        ]
    ];
}

Options::addGlobal('BlockMailingList', [
    [
        'label' => __('Content', 'flynt'),
        'name' => 'contentTab',
        'type' => 'tab',
        'placement' => 'top',
        'endpoint' => 0,
    ],
    [
        'label' => __('Image Position', 'flynt'),
        'name' => 'globalImagePosition',
        'type' => 'button_group',
        'choices' => [
            'left' => sprintf('<i class=\'dashicons dashicons-align-left\' title=\'%1$s\'></i>', __('Image on the left', 'flynt')),
            'right' => sprintf('<i class=\'dashicons dashicons-align-right\' title=\'%1$s\'></i>', __('Image on the right', 'flynt'))
        ]
    ],
    [
        'label' => __('Image', 'flynt'),
        'instructions' => __('Image-Format: JPG, PNG, SVG, WebP.', 'flynt'),
        'name' => 'globalImage',
        'type' => 'image',
        'preview_size' => 'medium',
        'required' => 1,
        'mime_types' => 'jpg,jpeg,png,svg,webp',
    ],
    [
        'label' => __('Text', 'flynt'),
        'name' => 'globalContentHtml',
        'type' => 'wysiwyg',
        'delay' => 0,
        'media_upload' => 0,
        'required' => 1,
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
        'name' => 'globalMailingOptions',
        'type' => 'group',
        'layout' => 'row',
        'sub_fields' => [
            [
                'label' => __('Background Color', 'flynt'),
                'name' => 'backgroundColor',
                'type' => 'color_picker',
                'default_value' => '#5B5CA0',
                'enable_opacity' => true,
                'return_format' => 'string'
            ],
            FieldVariables\getTheme()
        ]
    ]
]);
