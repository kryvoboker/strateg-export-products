<?php

return [
    'navigation_label' => 'Товари',

    'labels' => [
        'model' => 'Товар',
        'plural_model' => 'Товари',
    ],

    'columns' => [
        'status' => 'Статус',
        'name' => 'Назва',
        'sku' => 'SKU',
        'model' => 'Модель',
        'ean' => 'EAN',
        'quantity' => 'Кількість',
        'minimum' => 'Мінімум',
        'price' => 'Ціна',
        'date_available' => 'Дата доступності',
        'shops' => 'Інтернет-магазини',
        'batch_ids' => 'Batch ID',
        'is_processed' => 'Оброблено',
        'is_exported' => 'Вивантажено',
        'date_added' => 'Дата додавання',
        'no_bound_shops' => 'Немає прив\'язаних інтернет-магазинів',
        'no_batch' => 'Немає прив\'язки до batch',
    ],

    'filters' => [
        'status' => 'Статус',
        'shop' => 'Інтернет-магазин',
        'category' => 'Категорія',
        'attribute' => 'Атрибут',
        'is_processed' => 'Оброблено',
        'is_exported' => 'Вивантажено',
        'search_fields' => 'Пошук за полями',
    ],

    'actions' => [
        'bind_products_to_shops' => 'Масово прив\'язати товари до магазинів',
        'export_products_to_shops' => 'Масово вигрузити товари в магазини',
    ],

    'statuses' => [
        'active' => 'Активний',
        'inactive' => 'Неактивний',
    ],

    'messages' => [
        'select_shops_required' => 'Оберіть хоча б один інтернет-магазин.',
        'bulk_bind_queued' => 'Прив\'язку товарів поставлено в чергу.',
        'bulk_bind_result' => 'Товарів: :products_total; магазинів: :shops_total; задач у черзі: :jobs_queued.',
        'bulk_export_queued' => 'Вивантаження товарів поставлено в чергу.',
        'bulk_export_result' => 'Товарів: :products_total; вивантажень у черзі: :exports_queued; уже в черзі/вивантажено: :already_queued_or_exported; раніше не вдалося: :already_failed; не прив\'язані до магазину: :skipped_not_bound; помилки: :errors.',
    ],
];
