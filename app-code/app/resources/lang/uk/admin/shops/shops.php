<?php

return [
    // Navigation
    'navigation_label' => 'Магазини',

    // Labels
    'labels' => [
        'model'                      => 'Магазин',
        'plural_model'               => 'Магазини',
        'type'                       => 'Тип магазину',
        'base_url'                   => 'Базовий URL',
        'api_url'                    => 'API URL',
        'part_api_url_login'         => 'Частина API URL для авторизації',
        'part_api_url_export_prods'  => 'Частина API URL для експорту товарів',
        'part_api_url_restore_prods' => 'Частина API URL для відновлення товарів',
        'part_api_url_update_prods'  => 'Частина API URL для оновлення товарів',
        'part_api_url_delete_prods'  => 'Частина API URL для видалення товарів',
        'api_token'                  => 'API Токен',
        'options'                    => 'Опції',
        'add_option'                 => 'Додати опцію',
        'option_key'                 => 'Ключ',
        'option_value'               => 'Значення',
    ],

    // Columns
    'columns' => [
        'type'     => 'Тип',
        'base_url' => 'Базовий URL',
    ],

    // Helpers
    'helpers' => [
        'is_active'                  => 'Увімкнути/вимкнути магазин',
        'options'                    => 'Додаткові налаштування магазину у форматі ключ-значення',
        'api_url'                    => 'URL для API запитів. Якщо не вказати, буде використовуватися базовий URL',
        'part_api_url_login'         => 'Частина URL для API запитів. Необхідно для авторизації в інтернет-магазині',
        'part_api_url_export_prods'  => 'Частина URL для API запитів. Необхідно для вивантаження товарів в інтернет-магазин',
        'part_api_url_restore_prods' => 'Частина URL для API запитів. Необхідно для відновлення товарів в інтернет-магазині',
        'part_api_url_update_prods'  => 'Частина URL для API запитів. Необхідно для оновлення товарів в інтернет-магазині',
        'part_api_url_delete_prods'  => 'Частина URL для API запитів. Необхідно для видалення товарів в інтернет-магазині',
        'api_token'                  => 'Токен для авторизації API запитів',
    ],
];
