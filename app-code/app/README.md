# Strateg Export Products

Сервіс для імпорту, обробки, прив'язки, вигрузки та оновлення товарів між локальним каталогом і інтернет-магазинами (через Excel / Google Sheets / API).

## Запуск у dev
```bash
# із кореня проєкту
make build-dev
make up-dev
```

Альтернатива без `make`:
```bash
docker compose -f .docker/dev/docker-compose.yml build
docker compose -f .docker/dev/docker-compose.yml up -d
```

## Запуск у prod
```bash
# із кореня проєкту
make build-prod
make up-prod
```

Якщо використовуються slim-образи:
```bash
make up-prod-slim
```

## Зупинка контейнерів
```bash
make down-dev
make down-prod
```

## Frontend
```bash
# dev watch
make vite

# production build
make vite-build
```

## Тести
```bash
# у php-контейнері
docker compose -f /home/kamaz/www/strateg-projects/strateg-export-products/.docker/dev/docker-compose.yml \
  exec -T dev-strateg-export-products-php-fpm php artisan test --compact
```

Окремий файл тестів:
```bash
docker compose -f /home/kamaz/www/strateg-projects/strateg-export-products/.docker/dev/docker-compose.yml \
  exec -T dev-strateg-export-products-php-fpm php artisan test --compact tests/Feature/SomeTest.php
```
