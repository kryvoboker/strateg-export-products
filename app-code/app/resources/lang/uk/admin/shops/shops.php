<?php

return [
    // Navigation
    'navigation_label' => 'Магазини',

    // Labels
    'labels'           => [
        'model'                     => 'Магазин',
        'plural_model'              => 'Магазини',
        'type'                      => 'Тип магазину',
        'base_url'                  => 'Базовий URL',
        'api_url'                   => 'API URL',
        'part_api_url_login'        => 'Частина API URL для авторизації',
        'part_api_url_export_prods' => 'Частина API URL для експорту товарів',
        'api_token'                 => 'API Токен',
        'options'                   => 'Опції',
        'add_option'                => 'Додати опцію',
        'option_key'                => 'Ключ',
        'option_value'              => 'Значення',
    ],

    // Columns
    'columns'          => [
        'type'     => 'Тип',
        'base_url' => 'Базовий URL',
    ],

    // Helpers
    'helpers'          => [
        'is_active'                 => 'Увімкнути/вимкнути магазин',
        'options'                   => 'Додаткові налаштування магазину у форматі ключ-значення',
        'api_url'                   => 'URL для API запитів. Якщо не вказати, буде використовуватися базовий URL',
        'part_api_url_login'        => 'Частина URL для API запитів. Необхідно для авторизації в інтернет-магазині',
        'part_api_url_export_prods' => 'Частина URL для API запитів. Необхідно для вивантаження товарів в інтернет-магазин',
        'api_token'                 => 'Токен для авторизації API запитів',
    ],
];
