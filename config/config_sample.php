<?php
// config/config.php
return [
    'openai' => [
        'api_key' => 'your-actual-openai-key-here',
        'caption_model' => 'text-davinci-003',
        'image_model' => 'dall-e-2' // or 'dall-e-3' if you have access
    ],
    'app' => [
        'routine_data' => __DIR__ . '/../data/routine.json',
        'cache_dir' => __DIR__ . '/../cache/'
    ]
];