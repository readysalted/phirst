<?php

namespace Flynt\Components\BlockHero;

add_filter('Flynt/addComponentData?name=BlockHero', function (array $data): array {
    if (($data['displayStyle'] ?? '') === 'phirstInterior') {
        $data['breadcrumbs'] = phirst_content_breadcrumbs((int) get_queried_object_id());
    }
    return $data;
});

function getACFLayout(): array
{
    return [
        'name' => 'BlockHero',
        'label' => __('Block: Hero', 'flynt'),
        'sub_fields' => [
            [
                'label' => __('Display Style', 'flynt'),
                'name' => 'displayStyle',
                'type' => 'select',
                'choices' => ['default' => __('Default', 'flynt'), 'phirstInterior' => __('PHIRST interior hero', 'flynt')],
                'default_value' => 'default',
            ],
            [
                'label' => __('Title', 'flynt'),
                'name' => 'title',
                'type' => 'text',
                'required' => 1,
            ],
            [
                'label' => __('Text', 'flynt'),
                'name' => 'contentHtml',
                'type' => 'wysiwyg',
                'delay' => 0,
                'media_upload' => 0,
                'required' => 0,
                'wrapper' => [
                    'width' => '70',
                ],
            ],
            [
                'label' => __('Image', 'flynt'),
                'instructions' => __('Image-Format: JPG, PNG, SVG, WebP.', 'flynt'),
                'name' => 'image',
                'type' => 'image',
                'preview_size' => 'medium',
                'required' => 0,
                'mime_types' => 'jpg,jpeg,png,svg,webp',
                'wrapper' => [
                    'width' => '30',
                ],
            ],
        ]
    ];
}
