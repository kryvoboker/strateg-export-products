<?php

declare(strict_types=1);

return [
    'navigation_label' => 'Категорії',

    'labels' => [
        'model'                => 'Категорія',
        'plural_model'         => 'Категорії',
        'name'                 => 'Назва категорії',
        'parent'               => 'Батьківська категорія',
        'sort_order'           => 'Порядок сортування',
        'shops'                => 'Магазини',
        'shop'                 => 'Магазин',
        'external_category_id' => 'ID категорії у зовнішньому магазині',
    ],

    'columns' => [
        'name'           => 'Категорія',
        'shops'          => 'Магазини',
        'children_count' => 'Дочірні',
        'sort_order'     => 'Сортування',
        'no_shops'       => 'Не прив\'язано до магазинів',
    ],

    'actions' => [
        'add_shop_binding'         => 'Додати магазин',
        'view_children_tree'       => 'Показати дочірні категорії',
        'children_of'              => 'Дочірні категорії: :name',
        'show_all_categories'      => 'Показати всі категорії',
        'bind_categories_to_shops' => 'Масово прив\'язати категорії до магазинів',
    ],

    'messages' => [
        'no_children'           => 'У цієї категорії немає дочірніх категорій.',
        'select_shops_required' => 'Оберіть хоча б один магазин.',
        'bulk_bind_completed'   => 'Масова прив\'язка категорій завершена.',
        'bulk_bind_result'      => 'Оброблено категорій: :categories_total; магазинів для прив\'язки: :shops_total.',
    ],

    'filters' => [
        'shop'   => 'Магазин',
        'status' => 'Статус',
    ],

    'statuses' => [
        'active'   => 'Активна',
        'inactive' => 'Неактивна',
    ],

    'delete' => [
        'modal_heading'     => 'Підтвердження видалення категорії',
        'modal_description' => 'Буде видалено вибрану категорію та її дочірні категорії (:children_count). Дія незворотна.',
        'modal_confirm'     => 'Видалити категорію',
    ],
];
