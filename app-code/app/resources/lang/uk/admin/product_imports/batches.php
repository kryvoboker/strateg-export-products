<?php

return [
    // Navigation
    'navigation_label' => 'Імпорт товарів',

    // Labels
    'labels' => [
        'model'            => 'Пакет імпорту',
        'plural_model'     => 'Пакети імпорту',
        'excel_file'       => 'EXCEL файл',
        'google_sheets_url'=> 'Посилання на Google Sheets',
        'quantity'         => 'Кількість',
        'price'            => 'Ціна',
    ],

    // Tabs/Sections/Actions
    'tabs' => [
        'excel'         => 'EXCEL файл',
        'google_sheets' => 'Google Sheets',
        'admin_form'    => 'Через адмінку',
    ],
    'sections' => [
        'product_fields' => 'Поля товару',
        'sheets_coordinates' => 'Координати для кожного листа',
    ],
    'actions' => [
        'create' => 'Завантажити нові товари',
    ],

    // Columns
    'columns' => [
        'source_type'     => 'Джерело',
        'source_name'     => 'Назва джерела',
        'status'          => 'Статус',
        'total_items'     => 'Всього',
        'processed_items' => 'Опрацьовано',
        'failed_items'    => 'Помилки',
        'started_at'      => 'Початок',
        'finished_at'     => 'Завершення',
    ],

    // Statuses
    'statuses' => [
        'new'            => 'Новий',
        'processing'     => 'В обробці',
        'completed'      => 'Завершено',
        'failed'         => 'Помилка',
        'partial_failed' => 'Частково з помилками',
        'canceled'       => 'Скасовано',
    ],

    // Messages
    'messages' => [
        'created' => 'Пакет імпорту створено, завдання додано в чергу.'
    ],
];
