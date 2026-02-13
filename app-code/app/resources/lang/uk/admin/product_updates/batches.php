<?php

return [
    'navigation_label' => 'Оновлення товарів',

    'labels' => [
        'model' => 'Пакет оновлення',
        'plural_model' => 'Пакети оновлення',
    ],

    'actions' => [
        'create' => 'Завантажити товари на оновлення',
        'open_result' => 'Переглянути результат',
        'download_error_log' => 'Завантажити лог помилок',
        'delete_error_log' => 'Видалити лог помилок',
        'update_products_to_shops' => 'Масово оновити товари в магазинах',
        'update_product_to_shops' => 'Оновити товар в магазинах',
        'retry_failed_updates' => 'Повторити невдалі оновлення',
    ],

    'columns' => [
        'update_status' => 'Статус оновлення',
    ],

    'statuses' => [
        'new' => 'Новий',
        'processing' => 'В обробці',
        'updating_api' => 'Оновлення через API',
        'completed' => 'Завершено',
        'failed' => 'Помилка',
        'partial_failed' => 'Частково з помилками',
        'canceled' => 'Скасовано',
    ],

    'item_statuses' => [
        'new' => 'Новий',
        'processing' => 'В обробці',
        'normalized' => 'Нормалізовано',
        'successed' => 'Успішно',
        'failed' => 'Помилка',
        'not_queued' => 'Не в черзі',
    ],

    'messages' => [
        'processing_row_locked' => 'Запис в обробці. Дочекайтесь завершення.',
        'delete_error_log_confirmation' => 'Ви дійсно хочете видалити файл логу помилок?',
        'only_owner_can_delete_log' => 'Видаляти лог може лише користувач, який створив імпорт.',
        'error_log_deleted' => 'Лог помилок видалено.',
        'bulk_update_select_shops' => 'Оберіть один або кілька магазинів для оновлення товарів із вибраних черг.',
        'bulk_update_queued' => 'Завдання на оновлення поставлено в чергу.',
        'bulk_update_no_shops' => 'Оберіть хоча б один магазин.',
        'bulk_update_result' => 'Вибрано черг: :batches_selected; пропущено (в обробці): :batches_skipped_processing; товарів до оновлення: :products_total; задач оновлення створено: :updates_queued; вже невдалих: :already_failed; вже в черзі або оновлено: :already_queued_or_exported; не прив\'язані до магазину: :skipped_not_bound; без external_product_id: :skipped_without_external_id; помилок: :errors.',
        'bulk_update_items_result' => 'Вибрано товарів: :items_selected; пропущено (в обробці): :items_skipped_processing; без product_id: :items_skipped_without_product; товарів до оновлення: :products_total; задач оновлення створено: :updates_queued; вже невдалих: :already_failed; вже в черзі або оновлено: :already_queued_or_exported; не прив\'язані до магазину: :skipped_not_bound; без external_product_id: :skipped_without_external_id; помилок: :errors.',
        'bulk_retry_queued' => 'Повторні завдання на оновлення поставлено в чергу.',
        'bulk_retry_result' => 'Знайдено невдалих оновлень: :failed_found; поставлено в чергу повторно: :queued.',
        'item_update_queued' => 'Товар поставлено в чергу на оновлення.',
        'item_update_result' => 'Товарів до оновлення: :products_total; задач оновлення створено: :updates_queued; вже невдалих: :already_failed; вже в черзі або оновлено: :already_queued_or_exported; не прив\'язані до магазину: :skipped_not_bound; без external_product_id: :skipped_without_external_id; помилок: :errors.',
        'item_update_needs_binding' => 'Товар не прив\'язаний до магазину. Спочатку виконайте прив\'язку товару до магазину.',
        'item_retry_queued' => 'Повторні завдання для товару поставлено в чергу.',
        'item_retry_result' => 'Знайдено невдалих оновлень: :failed_found; поставлено в чергу повторно: :queued.',
    ],
];
