<!doctype html>
<html lang="uk">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">

    <title>Технічне обслуговування — {{ str_replace('(' . config('app.env') . ')', '', config('app.name')) }}</title>
    <meta name="robots" content="noindex, nofollow">

    @php
        $retry_after = (int)config('app.maintenance.retry_after');
    @endphp

    {{-- Якщо задано Retry-After у секундах (за бажанням) --}}
    @isset($retry_after)
        <meta http-equiv="refresh" content="{{ (int) $retry_after }}">
    @endisset

    {{-- Якщо у вас є Tailwind через Vite --}}
    @if (function_exists('vite'))
        @vite(['resources/css/app.css', 'resources/js/app.js'])
    @endif

    <style>
        /* Fallback-стилі, якщо Tailwind/Vite не підключені */
        :root {
            color-scheme: light dark;
        }

        body {
            margin: 0;
            font-family: ui-sans-serif, system-ui, -apple-system, Segoe UI, Roboto, Arial, "Noto Sans", "Liberation Sans", sans-serif;
        }

        .wrap {
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 24px;
            background: #0b1220;
            color: #e5e7eb;
        }

        .card {
            width: 100%;
            max-width: 720px;
            border: 1px solid rgba(255, 255, 255, .08);
            background: rgba(255, 255, 255, .04);
            border-radius: 16px;
            padding: 28px;
            box-shadow: 0 10px 30px rgba(0, 0, 0, .35);
        }

        .muted {
            color: rgba(229, 231, 235, .75);
            line-height: 1.6;
        }

        .row {
            display: flex;
            gap: 12px;
            flex-wrap: wrap;
            margin-top: 18px;
        }

        .btn {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 10px;
            padding: 10px 14px;
            border-radius: 12px;
            text-decoration: none;
            cursor: pointer;
            border: 1px solid rgba(255, 255, 255, .14);
            background: rgba(255, 255, 255, .06);
            color: #e5e7eb;
        }

        .btn:hover {
            background: rgba(255, 255, 255, .10);
        }

        .badge {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            padding: 6px 10px;
            border-radius: 999px;
            border: 1px solid rgba(255, 255, 255, .14);
            background: rgba(255, 255, 255, .06);
            font-size: 12px;
        }

        .dot {
            width: 10px;
            height: 10px;
            border-radius: 999px;
            background: #f59e0b;
            box-shadow: 0 0 0 4px rgba(245, 158, 11, .15);
        }

        .footer {
            margin-top: 18px;
            font-size: 12px;
            color: rgba(229, 231, 235, .65);
        }

        .mono {
            font-family: ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, "Liberation Mono", "Courier New", monospace;
        }
    </style>
</head>
<body>
<div class="wrap">
    <main class="card" role="main" aria-labelledby="maintenance-title">
        <div class="badge" aria-label="Статус сервісу">
            <span class="dot" aria-hidden="true"></span>
            <span>Сервіс тимчасово недоступний</span>
        </div>

        <h1 id="maintenance-title" style="margin: 14px 0 8px; font-size: 28px;">
            Технічне обслуговування
        </h1>

        <p class="muted" style="margin: 0;">
            Ми виконуємо планові роботи, щоб покращити стабільність і безпеку сервісу.
            Будь ласка, спробуйте ще раз пізніше.
        </p>

        @php
            $email_support = config('app.maintenance.support_email');
        @endphp

        <div class="row">
            <a class="btn" href="{{ url()->current() }}" rel="nofollow">
                Оновити сторінку
            </a>

            @if (!empty($status_url))
                <a class="btn" href="{{ $status_url }}" rel="nofollow">
                    Перевірити статус
                </a>
            @endif

            @if (!empty($email_support))
                <a class="btn" href="mailto:{{ $email_support }}">
                    Написати в підтримку
                </a>
            @endif
        </div>

        <div class="footer">
            <div>
                Код: <span class="mono">503</span>
                @isset($retry_after)
                    · Автоперезавантаження через <span class="mono">
                        {{ (int) round($retry_after / 60) }}</span> хв
                @endisset
            </div>
            <div style="margin-top: 6px;">
                © {{ date('Y') }} {{ str_replace('(' . config('app.env') . ')', '', config('app.name')) }}
            </div>
        </div>
    </main>
</div>
</body>
</html>
