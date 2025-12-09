FROM php:8.4-cli-alpine3.22

RUN apk add --no-cache curl ca-certificates unzip zip tzdata \
    && rm -rf /var/cache/apk/*

ENV TZ=Europe/Kyiv

# Install supercronic (cron for containers)
RUN curl -fsSLO "https://github.com/aptible/supercronic/releases/download/v0.2.33/supercronic-linux-amd64" \
    && chmod +x supercronic-linux-amd64 \
    && mv supercronic-linux-amd64 /usr/local/bin/supercronic

COPY --from=composer:2.8.8 /usr/bin/composer /usr/bin/composer
COPY --from=mlocati/php-extension-installer /usr/bin/install-php-extensions /usr/local/bin/

RUN install-php-extensions gd zip pdo pdo_pgsql pgsql sockets intl bcmath apcu

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
COPY .docker/dev/cron/entrypoint.sh /usr/local/bin/entrypoint.sh

RUN chmod +x /usr/local/bin/entrypoint.sh

WORKDIR /var/www/html

USER www-data

ENTRYPOINT ["/usr/local/bin/entrypoint.sh"]