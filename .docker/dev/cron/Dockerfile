FROM composer:2.9.2 AS composer

FROM php:8.4-cli-alpine3.22 AS php-cli

RUN apk add --no-cache \
  curl \
  ca-certificates \
  unzip \
  zip \
  tzdata \
  dos2unix

ENV TZ=Europe/Kyiv

# Set time zone in container
RUN cp /usr/share/zoneinfo/Europe/Kyiv /etc/localtime \
  && echo "Europe/Kyiv" > /etc/timezone

RUN echo "date.timezone=${TZ}" > /usr/local/etc/php/conf.d/99-timezone.ini

COPY --from=mlocati/php-extension-installer /usr/bin/install-php-extensions /usr/local/bin/

RUN install-php-extensions gd zip pdo pdo_pgsql pgsql sockets intl bcmath apcu \
  && rm -f /usr/local/bin/install-php-extensions

FROM php-cli AS supercronic

# Install supercronic (cron for containers)
RUN curl -fsSLO "https://github.com/aptible/supercronic/releases/download/v0.2.33/supercronic-linux-amd64" \
  && chmod +x supercronic-linux-amd64 \
  && mv supercronic-linux-amd64 /usr/local/bin/supercronic

FROM php-cli AS development

# Copy supercronic binary from supercronic stage and Composer from composer stage
COPY --from=supercronic /usr/local/bin/supercronic /usr/local/bin/supercronic
COPY --from=composer /usr/bin/composer /usr/bin/composer

# Import UID and GID
ARG UID=1000
ARG GID=1000
ARG UMASK=0002

ENV UID=${UID}
ENV GID=${GID}
ENV UMASK=${UMASK}
ENV USERNAME=www-data

# Configure composer cache directory
ENV HOME=/home/${USERNAME}
ENV COMPOSER_HOME=${HOME}/.composer
ENV COMPOSER_CACHE_DIR=${COMPOSER_HOME}/cache

# Create www-data user with proper shell
RUN deluser ${USERNAME} 2>/dev/null || true \
  && delgroup ${USERNAME} 2>/dev/null || true \
  && addgroup -g ${GID} ${USERNAME} \
  && adduser -D -u ${UID} -G ${USERNAME} -s /bin/sh ${USERNAME}

# Create crontab directory with proper permissions
RUN mkdir -p /etc/crontabs/ /var/log/cron/ ${COMPOSER_CACHE_DIR}/ \
  && chown ${UID}:${GID} /etc/crontabs/ /var/log/cron/ ${COMPOSER_CACHE_DIR}/ \
  && chmod -R 775 /var/log/cron/ ${COMPOSER_CACHE_DIR}/

# Copy entrypoint script
COPY .docker/dev/cron/entrypoint.sh /usr/local/bin/entrypoint.sh

RUN chmod +x /usr/local/bin/entrypoint.sh

WORKDIR /var/webroot/sites/export-products.strateg.ua/app

USER ${USERNAME}

ENTRYPOINT ["/usr/local/bin/entrypoint.sh"]