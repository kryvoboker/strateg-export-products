<?php

return [
    'navigation_label' => 'Атрибути',

    'labels' => [
        'model'        => 'Атрибут',
        'plural_model' => 'Атрибути',
        'name'         => 'Назва атрибута',
        'sort_order'   => 'Порядок сортування',
        'shops'        => 'Магазини',
    ],

    'columns' => [
        'name'           => 'Атрибут',
        'shops'          => 'Магазини',
        'children_count' => 'Дочірні',
        'sort_order'     => 'Сортування',
        'no_shops'       => 'Не прив\'язано до магазинів',
    ],

    'actions' => [
        'view_children_tree'       => 'Показати дочірні атрибути',
        'children_of'              => 'Дочірні атрибути: :name',
        'bind_attributes_to_shops' => 'Масово прив\'язати атрибути до магазинів',
    ],

    'filters' => [
        'shop'   => 'Магазин',
        'status' => 'Статус',
    ],

    'statuses' => [
        'active'   => 'Активний',
        'inactive' => 'Неактивний',
    ],

    'messages' => [
        'no_children'           => 'У цього атрибута немає дочірніх атрибутів.',
        'select_shops_required' => 'Оберіть хоча б один магазин.',
        'bulk_bind_completed'   => 'Масова прив\'язка атрибутів завершена.',
        'bulk_bind_result'      => 'Оброблено атрибутів: :attributes_total; магазинів для прив\'язки: :shops_total.',
    ],
];
