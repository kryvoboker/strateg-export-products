<?php

return [
    'navigation_label' => 'Товари',

    'labels' => [
        'model'        => 'Товар',
        'plural_model' => 'Товари',
    ],

    'columns' => [
        'status'         => 'Статус',
        'name'           => 'Назва',
        'sku'            => 'SKU',
        'model'          => 'Модель',
        'ean'            => 'EAN',
        'quantity'       => 'Кількість',
        'minimum'        => 'Мінімум',
        'price'          => 'Ціна',
        'date_available' => 'Дата доступності',
        'shops'          => 'Інтернет-магазини',
        'batch_ids'      => 'Batch ID',
        'is_processed'   => 'Оброблено',
        'is_exported'    => 'Вивантажено',
        'date_added'     => 'Дата додавання',
        'no_bound_shops' => 'Немає прив\'язаних інтернет-магазинів',
        'no_batch'       => 'Немає прив\'язки до batch',
    ],

    'filters' => [
        'status'        => 'Статус',
        'shop'          => 'Інтернет-магазин',
        'category'      => 'Категорія',
        'attribute'     => 'Атрибут',
        'is_processed'  => 'Оброблено',
        'is_exported'   => 'Вивантажено',
        'search_fields' => 'Пошук за полями',
    ],

    'actions' => [
        'bind_products_to_shops'    => 'Масово прив\'язати товари до магазинів',
        'export_products_to_shops'  => 'Масово вигрузити товари в магазини',
        'update_product_to_shops'   => 'Оновити товар в магазинах',
        'restore_product_in_shops'  => 'Відновити товар в магазинах',
        'delete_product_from_shops' => 'Видалити товар з магазинів',
        'update_product_via_api'    => 'Оновити по API в інтернет-магазині',
        'update_products_to_shops'  => 'Масово оновити товари в магазинах',
        'restore_products_in_shops' => 'Масово відновити товари в магазинах',
        'delete_products_from_shops'=> 'Масово видалити товари з магазинів',
    ],

    'statuses' => [
        'active'   => 'Активний',
        'inactive' => 'Неактивний',
    ],

    'messages' => [
        'select_shops_required'  => 'Оберіть хоча б один інтернет-магазин.',
        'bulk_bind_queued'       => 'Прив\'язку товарів поставлено в чергу.',
        'bulk_bind_result'       => 'Товарів: :products_total; магазинів: :shops_total; задач у черзі: :jobs_queued.',
        'bulk_export_queued'     => 'Вивантаження товарів поставлено в чергу.',
        'bulk_export_result'     => 'Товарів: :products_total; вивантажень у черзі: :exports_queued; уже в черзі/вивантажено: :already_queued_or_exported; раніше не вдалося: :already_failed; не прив\'язані до магазину: :skipped_not_bound; помилки: :errors.',
        'item_update_queued'     => 'Оновлення товару поставлено в чергу.',
        'item_update_not_queued' => 'Оновлення по API не поставлено в чергу.',
        'item_update_result'     => 'Товарів до оновлення: :products_total; задач оновлення створено: :updates_queued; уже невдалих: :already_failed; уже в черзі/оновлено: :already_queued_or_exported; не прив\'язані: :skipped_not_bound; без external_product_id: :skipped_without_external_id; створено failed: :failed_created; помилок: :errors.',
        'bulk_update_queued'     => 'Оновлення товарів поставлено в чергу.',
        'bulk_update_result'     => 'Товарів: :products_total; магазинів: :shops_total; задач оновлення створено: :updates_queued; уже невдалих: :already_failed; уже в черзі/оновлено: :already_queued_or_exported; не прив\'язані: :skipped_not_bound; без external_product_id: :skipped_without_external_id; створено failed: :failed_created; помилок: :errors.',
        'item_restore_queued'    => 'Відновлення товару поставлено в чергу.',
        'item_restore_result'    => 'Товарів: :products_total; магазинів: :shops_total; задач відновлення в черзі: :restore_batches_queued; помилок: :errors.',
        'bulk_restore_queued'    => 'Відновлення товарів поставлено в чергу.',
        'bulk_restore_result'    => 'Товарів: :products_total; магазинів: :shops_total; задач відновлення в черзі: :restore_batches_queued; помилок: :errors.',
        'item_delete_queued'     => 'Видалення товару поставлено в чергу.',
        'item_delete_result'     => 'Товарів: :products_total; магазинів: :shops_total; задач видалення створено: :deletes_queued; уже failed: :already_failed; уже в черзі/видалено: :already_queued_or_deleted; не привʼязані: :skipped_not_bound; без external_product_id: :skipped_without_external_id; помилок: :errors.',
        'bulk_delete_queued'     => 'Видалення товарів поставлено в чергу.',
        'bulk_delete_result'     => 'Товарів: :products_total; магазинів: :shops_total; задач видалення створено: :deletes_queued; уже failed: :already_failed; уже в черзі/видалено: :already_queued_or_deleted; не привʼязані: :skipped_not_bound; без external_product_id: :skipped_without_external_id; помилок: :errors.',
    ],

    'api_update' => [
        'tab_label'   => 'Налаштування API-оновлення',
        'mode_helper' => 'Оберіть режим: пропустити, видалити або оновити.',
        'actions'     => [
            'skip'   => 'Пропустити',
            'delete' => 'Видалити',
            'update' => 'Оновити',
        ],
        'sections' => [
            'product' => 'Товар',
        ],
    ],

    'errors' => [
        'product_save_before_update_by_api' => 'Помилка оновлення',
        'product_update'                    => 'Помилка оновлення товару',
    ],
];
