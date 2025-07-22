<?php
// config/config.php
return [
    'openai' => [
        'api_key' => 'your-actual-openai-key-here',
        'caption_model' => 'gpt-4.1',
        'image_model' => 'gpt-image-1' // or 'dall-e-3' if you have access
    ],
    'app' => [
        'routine_data' => __DIR__ . '/../data/routine.json',
        'cache_dir' => __DIR__ . '/../cache/'
    ]
];