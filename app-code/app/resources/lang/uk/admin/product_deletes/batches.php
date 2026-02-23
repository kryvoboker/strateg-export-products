<?php

return [
    'navigation_label' => 'Видалення товарів',

    'labels' => [
        'model'        => 'Пакет видалення',
        'plural_model' => 'Пакети видалення',
    ],

    'actions' => [
        'create'                     => 'Завантажити товари на видалення',
        'open_result'                => 'Переглянути результат',
        'download_error_log'         => 'Завантажити лог помилок',
        'delete_products_from_shops' => 'Масово видалити товари з магазинів',
        'delete_product_from_shop'   => 'Видалити товар з магазину',
        'retry_failed_deletes'       => 'Повторити невдалі видалення',
    ],

    'statuses' => [
        'new'            => 'Новий',
        'processing'     => 'В обробці',
        'completed'      => 'Завершено',
        'failed'         => 'Помилка',
        'partial_failed' => 'Частково з помилками',
        'canceled'       => 'Скасовано',
    ],

    'source_types' => [
        'excel_file'     => 'Excel файл',
        'google_sheet'   => 'Google Sheet',
        'admin_panel'    => 'Адмінка',
        'local_products' => 'Локальні товари',
    ],

    'item_statuses' => [
        'new'        => 'Новий',
        'processing' => 'В обробці',
        'deleted'    => 'Видалено',
        'failed'     => 'Помилка',
    ],

    'titles' => [
        'items' => 'Позиції видалення',
    ],

    'messages' => [
        'bulk_delete_queued'       => 'Завдання на видалення поставлено в чергу.',
        'bulk_delete_result'       => 'Вибрано черг: :batches_selected; пропущено (в обробці): :batches_skipped_processing; елементів до видалення: :items_total; задач поставлено в чергу: :deletes_queued; уже failed: :already_failed; уже в черзі/видалено: :already_queued_or_deleted; пропущено через неповний payload: :skipped_missing_payload; помилок: :errors.',
        'bulk_delete_items_result' => 'Вибрано елементів: :items_total; задач поставлено в чергу: :deletes_queued; уже failed: :already_failed; уже в черзі/видалено: :already_queued_or_deleted; пропущено через неповний payload: :skipped_missing_payload; помилок: :errors.',
        'bulk_retry_queued'        => 'Повторні завдання на видалення поставлено в чергу.',
        'bulk_retry_result'        => 'Знайдено невдалих видалень: :failed_found; поставлено в чергу повторно: :queued; помилок: :errors.',
        'item_delete_queued'       => 'Товар поставлено в чергу на видалення.',
        'item_delete_result'       => 'Поставлено в чергу: :deletes_queued; уже failed: :already_failed; уже в черзі/видалено: :already_queued_or_deleted; пропущено через неповний payload: :skipped_missing_payload; помилок: :errors.',
        'item_retry_queued'        => 'Повторне видалення поставлено в чергу.',
    ],
];
