<?php

declare(strict_types=1);

return [
    'navigation_label' => 'Виробники',

    'labels' => [
        'model'        => 'Виробник',
        'plural_model' => 'Виробники',
        'name'         => 'Назва виробника',
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
        'active'   => 'Активний',
        'inactive' => 'Неактивний',
    ],

    'actions' => [
        'bind_manufacturers_to_shops' => 'Прив\'язати виробників до магазинів',
    ],

    'messages' => [
        'select_shops_required' => 'Оберіть хоча б один магазин',
        'bulk_bind_completed'   => 'Прив\'язку виконано',
        'bulk_bind_result'      => 'Оброблено виробників: :manufacturers_total. Обрано магазинів: :shops_total.',
    ],
];
