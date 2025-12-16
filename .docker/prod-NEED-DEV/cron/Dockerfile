# Stage 1: Base image with PHP extensions
FROM php:7.4-cli-alpine3.15 AS base

# Install system dependencies
RUN apk add --no-cache curl ca-certificates unzip zip tzdata dos2unix \
    && rm -rf /var/cache/apk/*

ENV TZ=Europe/Kyiv

# Copy installers from official images
COPY --from=composer:2.8.8 /usr/bin/composer /usr/bin/composer
COPY --from=mlocati/php-extension-installer /usr/bin/install-php-extensions /usr/local/bin/

# Install PHP extensions
RUN install-php-extensions gd zip mysqli pdo pdo_mysql sockets ioncube_loader \
    && rm -f /usr/local/bin/install-php-extensions

# Stage 2: Supercronic installation
FROM base AS supercronic

# Install supercronic (cron for containers)
RUN curl -fsSLO "https://github.com/aptible/supercronic/releases/download/v0.2.33/supercronic-linux-amd64" \
    && chmod +x supercronic-linux-amd64 \
    && mv supercronic-linux-amd64 /usr/local/bin/supercronic

# Stage 3: Final production image
FROM base AS production

# Copy supercronic binary from supercronic stage
COPY --from=supercronic /usr/local/bin/supercronic /usr/local/bin/supercronic

# Import UID and GID
ARG UID=1000
ARG GID=1000
ARG UMASK=0002

ENV UID=${UID}
ENV GID=${GID}
ENV UMASK=${UMASK}

# Create www-data user with proper shell
RUN deluser www-data 2>/dev/null || true \
    && delgroup www-data 2>/dev/null || true \
    && addgroup -g ${GID} www-data \
    && adduser -D -u ${UID} -G www-data -s /bin/sh www-data

# Create crontab directory with proper permissions
RUN mkdir -p /etc/crontabs /var/log/cron \
    && chown www-data:www-data /etc/crontabs \
    && chown -R www-data:www-data /var/log/cron \
    && chmod -R 775 /var/log/cron

# Copy entrypoint script
COPY .docker/prod/cron/entrypoint.sh /usr/local/bin/entrypoint.sh

RUN chmod +x /usr/local/bin/entrypoint.sh

WORKDIR /var/www/html

USER www-data

ENTRYPOINT ["/usr/local/bin/entrypoint.sh"]