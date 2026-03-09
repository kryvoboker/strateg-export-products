FROM composer:2.9.2 AS composer

FROM php:8.5-cli-alpine3.22 AS php-cli

RUN apk add --no-cache \
  curl \
  ca-certificates \
  unzip \
  zip \
  tzdata \
  logrotate \
  dos2unix

ENV TZ=Europe/Kyiv

# Set time zone in container
RUN cp /usr/share/zoneinfo/Europe/Kyiv /etc/localtime \
  && echo "Europe/Kyiv" > /etc/timezone

RUN echo "date.timezone=${TZ}" > /usr/local/etc/php/conf.d/99-timezone.ini

COPY --from=mlocati/php-extension-installer /usr/bin/install-php-extensions /usr/local/bin/

RUN install-php-extensions gd \
    zip \
    pdo \
    pdo_pgsql \
    pgsql \
    sockets \
    intl \
    bcmath \
    apcu \
    pcntl \
    redis \
  && rm -f /usr/local/bin/install-php-extensions

FROM php-cli AS supercronic

ARG TARGETARCH

# Install supercronic (cron for containers)
# Latest releases available at https://github.com/aptible/supercronic/releases
ENV SUPERCRONIC_URL=https://github.com/aptible/supercronic/releases/download/v0.2.43/supercronic-linux-${TARGETARCH} \
    SUPERCRONIC_SHA1SUM=f97b92132b61a8f827c3faf67106dc0e4467ccf2 \
    SUPERCRONIC=supercronic-linux-${TARGETARCH}

RUN curl -fsSLO "$SUPERCRONIC_URL" \
  && echo "${SUPERCRONIC_SHA1SUM}  ${SUPERCRONIC}" | sha1sum -c - \
  && chmod +x "$SUPERCRONIC" \
  && mv "$SUPERCRONIC" "/usr/local/bin/${SUPERCRONIC}" \
  && ln -s "/usr/local/bin/${SUPERCRONIC}" /usr/local/bin/supercronic

FROM php-cli AS development

# Copy supercronic binary from supercronic stage and Composer from composer stage
COPY --from=supercronic /usr/local/bin/supercronic /usr/local/bin/supercronic
COPY --from=composer /usr/bin/composer /usr/bin/composer

# Import UID and GID
ARG UID
ARG GID
ARG UMASK
ARG USERNAME

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
RUN mkdir -p /etc/crontabs/ /var/log/cron/ ${COMPOSER_CACHE_DIR}/ /var/lib/ \
  && chown -R ${UID}:${GID} /etc/crontabs/ /var/log/cron/ ${COMPOSER_CACHE_DIR}/ /var/lib/ \
  && chmod -R 755 /var/log/cron/ ${COMPOSER_CACHE_DIR}/ \
  && touch /var/lib/logrotate.status \
  && chmod 600 /var/lib/logrotate.status \
  && chown ${UID}:${GID} /var/lib/logrotate.status

COPY --chown=root:root .docker/dev/cron/redis.logrotate /etc/logrotate.d/redis

RUN dos2unix /etc/logrotate.d/redis \
  && chmod 644 /etc/logrotate.d/redis

# Copy entrypoint script
COPY --chown=${UID}:${GID} .docker/dev/cron/entrypoint.sh /usr/local/bin/entrypoint.sh

RUN chmod +x /usr/local/bin/entrypoint.sh

WORKDIR /var/webroot/sites/export-products.strateg.ua/app

USER ${USERNAME}

ENTRYPOINT ["/usr/local/bin/entrypoint.sh"]