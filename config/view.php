<?php

return [

    'paths' => ['resources/views', 'resources/views/**'],
    'compiled' => env(
        'VIEW_COMPILED_PATH',
        realpath(storage_path('framework/views'))
    ),

];
