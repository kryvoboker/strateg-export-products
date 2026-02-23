<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Application Name
    |--------------------------------------------------------------------------
    |
    | This value is the name of your application, which will be used when the
    | framework needs to place the application's name in a notification or
    | other UI elements where an application name needs to be displayed.
    |
    */

    'name' => env('APP_NAME', 'Laravel'),

    /*
    |--------------------------------------------------------------------------
    | Application Environment
    |--------------------------------------------------------------------------
    |
    | This value determines the "environment" your application is currently
    | running in. This may determine how you prefer to configure various
    | services the application utilizes. Set this in your ".env" file.
    |
    */

    'env' => env('APP_ENV', 'production'),

    /*
    |--------------------------------------------------------------------------
    | Application Debug Mode
    |--------------------------------------------------------------------------
    |
    | When your application is in debug mode, detailed error messages with
    | stack traces will be shown on every error that occurs within your
    | application. If disabled, a simple generic error page is shown.
    |
    */

    'debug'                 => (bool) env('APP_DEBUG', false),
    'enable_xdebug_session' => (bool) env('ENABLE_XDEBUG_SESSION', false),

    /*
    |--------------------------------------------------------------------------
    | Application URL
    |--------------------------------------------------------------------------
    |
    | This URL is used by the console to properly generate URLs when using
    | the Artisan command line tool. You should set this to the root of
    | the application so that it's available within Artisan commands.
    |
    */

    'url' => env('APP_URL', 'http://localhost'),

    /*
    |--------------------------------------------------------------------------
    | Application Timezone
    |--------------------------------------------------------------------------
    |
    | Here you may specify the default timezone for your application, which
    | will be used by the PHP date and date-time functions. The timezone
    | is set to "UTC" by default as it is suitable for most use cases.
    |
    */

    'timezone' => env('APP_TIMEZONE', 'UTC'),

    'date_format'     => env('APP_DATE_FORMAT', 'Y-m-d'),
    'time_format'     => env('APP_TIME_FORMAT', 'H:i:s'),
    'datetime_format' => env('APP_DATETIME_FORMAT', 'Y-m-d H:i:s'),

    /*
    |--------------------------------------------------------------------------
    | Application Locale Configuration
    |--------------------------------------------------------------------------
    |
    | The application locale determines the default locale that will be used
    | by Laravel's translation / localization methods. This option can be
    | set to any locale for which you plan to have translation strings.
    |
    */

    'locale' => env('APP_LOCALE', 'en'),

    'fallback_locale' => env('APP_FALLBACK_LOCALE', 'en'),

    'faker_locale' => env('APP_FAKER_LOCALE', 'en_US'),

    'ai_translation_enabled' => (bool) env('AI_TRANSLATION_ENABLED', true),
    'product_backups_max_per_scope' => (int) env('PRODUCT_BACKUPS_MAX_PER_SCOPE', 15),

    /*
    |--------------------------------------------------------------------------
    | Encryption Key
    |--------------------------------------------------------------------------
    |
    | This key is utilized by Laravel's encryption services and should be set
    | to a random, 32 character string to ensure that all encrypted values
    | are secure. You should do this prior to deploying the application.
    |
    */

    'cipher' => 'AES-256-CBC',

    'key' => env('APP_KEY'),

    'previous_keys' => [
        ...array_filter(
            explode(',', (string) env('APP_PREVIOUS_KEYS', ''))
        ),
    ],

    /*
    |--------------------------------------------------------------------------
    | Maintenance Mode Driver
    |--------------------------------------------------------------------------
    |
    | These configuration options determine the driver used to determine and
    | manage Laravel's "maintenance mode" status. The "cache" driver will
    | allow maintenance mode to be controlled across multiple machines.
    |
    | Supported drivers: "file", "cache"
    |
    */

    'maintenance' => [
        'driver'        => env('APP_MAINTENANCE_DRIVER', 'file'),
        'store'         => env('APP_MAINTENANCE_STORE', 'database'),
        'support_email' => env('APP_MAINTENANCE_SUPPORT_EMAIL'),
        'retry_after'   => (int) env('APP_MAINTENANCE_RETRY_AFTER'),
    ],

    'allowed_projects_types' => [
        'opencart' => explode(',', env('ALLOWED_OPENCART_PROJECTS_TYPES', '')),
    ],
    'denied_delete_emails' => [
        'fast.kamaz@gmail.com',
    ],
    'regex_validate_conditions' => [
        'email'     => '/^((?!\.)[\w\-_.]*[^.])(@\w+)(\.\w+(\.\w+)?[^.\W])$/',
        'telephone' => '/(^((\+?\d{2,}\s?)|(.*))\(?\d{3,}\)?\s?\d{3,}-?\d{2,}-?\d{2,}$)/',
        'password'  => '/^(?=.*\d)(?=.*[A-Z])(?=.*[a-z])(?=.*[^\w\s:])(\S)+$/',
    ],
    'images' => [
        'product' => [
            'upload' => [
                'max_size_kb' => (int) env('MAX_UPLOAD_PRODUCT_IMAGE_SIZE_KB', 5120), // 5 MB,
            ],
            'no_image'                 => env('DEFAULT_PRODUCT_NO_IMAGE_PATH'),
            'preview_in_list_in_admin' => [
                'width'  => (int) env('PRODUCT_IMAGE_PREVIEW_IN_LIST_IN_ADMIN_WIDTH', 100),
                'height' => (int) env('PRODUCT_IMAGE_PREVIEW_IN_LIST_IN_ADMIN_HEIGHT', 100),
            ],
            'preview_in_page_in_admin' => [
                'width'  => (int) env('PRODUCT_IMAGE_PREVIEW_IN_PAGE_IN_ADMIN_WIDTH', 500),
                'height' => (int) env('PRODUCT_IMAGE_PREVIEW_IN_PAGE_IN_ADMIN_HEIGHT', 500),
            ],
            'image_path' => env('PRODUCTS_IMAGES_PATH', 'images/products').'/'.date('Y/m'),
        ],
        'user' => [
            'upload' => [
                'max_size_kb' => (int) env('MAX_UPLOAD_USER_IMAGE_SIZE_KB', 5120), // 5 MB,
            ],
            'no_image'                 => env('DEFAULT_USER_NO_AVATAR_PATH'),
            'preview_in_list_in_admin' => [
                'width'  => (int) env('USER_AVATAR_PREVIEW_IN_LIST_IN_ADMIN_WIDTH', 100),
                'height' => (int) env('USER_AVATAR_PREVIEW_IN_LIST_IN_ADMIN_HEIGHT', 100),
            ],
            'preview_in_page_in_admin' => [
                'width'  => (int) env('USER_AVATAR_PREVIEW_IN_PAGE_IN_ADMIN_WIDTH', 500),
                'height' => (int) env('USER_AVATAR_PREVIEW_IN_PAGE_IN_ADMIN_HEIGHT', 500),
            ],
            'image_path' => env('AVATARS_PATH').'/'.date('Y/m'),
        ],
    ],
    'sheets' => [
        'upload' => [
            'max_size_kb' => (int) env('MAX_UPLOAD_PRODUCT_IMAGE_SIZE_KB', 5120), // 5 MB,
        ],
        'sheet_path' => env('SHEETS_PATH').'/'.date('Y/m'),
        'defs'       => [
            'products'               => 'Products',
            'product_images'         => 'Product Images',
            'product_descriptions'   => 'Product Descriptions',
            'product_discounts'      => 'Product Discounts',
            'product_specials'       => 'Product Specials',
            'seo_urls'               => 'SEO URLs',
            'attributes'             => 'Attributes',
            'attribute_descriptions' => 'Attribute Descriptions',
            'product_to_attributes'  => 'Product to Attributes',
            'categories'             => 'Categories',
            'category_descriptions'  => 'Category Descriptions',
        ],
    ],

];
