<?php

declare(strict_types=1);

return [
    'navigation_label' => 'Торгові марки',

    'labels' => [
        'model'        => 'Торгова марка',
        'plural_model' => 'Торгові марки',
        'name'         => 'Назва торгової марки',
        'sort_order'   => 'Порядок сортування',
        'shops'        => 'Магазини',
    ],

    'columns' => [
        'name'       => 'Назва',
        'shops'      => 'Магазини',
        'sort_order' => 'Порядок',
        'no_shops'   => 'Не прив\'язано до магазинів',
    ],

    'filters' => [
        'shop'   => 'Магазин',
        'status' => 'Статус',
    ],

    'statuses' => [
        'active'   => 'Активна',
        'inactive' => 'Неактивна',
    ],

    'actions' => [
        'bind_brands_to_shops' => 'Прив\'язати торгові марки до магазинів',
    ],

    'messages' => [
        'select_shops_required' => 'Оберіть хоча б один магазин',
        'bulk_bind_completed'   => 'Прив\'язку виконано',
        'bulk_bind_result'      => 'Оброблено торгових марок: :brands_total. Обрано магазинів: :shops_total.',
    ],
];
