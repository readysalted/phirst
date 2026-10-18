<?php

use ACFComposer\ACFComposer;

add_action('Flynt/afterRegisterComponents', function (): void {
    ACFComposer::registerFieldGroup([
        'name' => 'phirstNewsSource',
        'title' => __('PHIRST News Source', 'flynt'),
        'fields' => [
            [
                'name' => 'externalStoryLink',
                'label' => __('Full story URL', 'flynt'),
                'type' => 'url',
                'instructions' => __('Optional. News carousel links to this source instead of the local article. Leave empty for locally authored news.', 'flynt'),
            ],
        ],
        'location' => [[['param' => 'post_type', 'operator' => '==', 'value' => 'post']]],
    ]);
});
