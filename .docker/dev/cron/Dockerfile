FROM composer:2.9.2 AS composer

FROM php:8.5-cli-alpine3.22 AS php-cli

RUN apk add --no-cache \
  curl \
  ca-certificates \
  unzip \
  zip \
  tzdata \
  logrotate \
  dos2unix \
  fontconfig \
  ttf-dejavu \
  shadow

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

# Install supercronic (cron for containers)
# Latest releases available at https://github.com/aptible/supercronic/releases
ARG TARGETARCH

ENV SUPERCRONIC_VERSION=v0.2.45

RUN set -eux; \
    case "${TARGETARCH}" in \
        amd64) SUPERCRONIC_ARCH="amd64" ;; \
        arm64) SUPERCRONIC_ARCH="arm64" ;; \
        *) echo "Unsupported architecture: ${TARGETARCH}" && exit 1 ;; \
    esac; \
    curl -fsSL \
        "https://github.com/aptible/supercronic/releases/download/${SUPERCRONIC_VERSION}/supercronic-linux-${SUPERCRONIC_ARCH}" \
        -o /usr/local/bin/supercronic; \
    chmod +x /usr/local/bin/supercronic

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

ARG DIR_LOG=/var/log

RUN set -eux; \
    if getent group "${USERNAME}" > /dev/null; then \
        groupmod -o -g "${GID}" "${USERNAME}"; \
    else \
        groupadd -o -g "${GID}" "${USERNAME}"; \
    fi; \
    if id "${USERNAME}" > /dev/null 2>&1; then \
        usermod -o -u "${UID}" -g "${GID}" -d "${HOME}" "${USERNAME}"; \
    else \
        useradd -o -u "${UID}" -g "${GID}" -d "${HOME}" -m -s /bin/sh "${USERNAME}"; \
    fi; \
    mkdir -p "${HOME}"; \
    chown -R "${UID}:${GID}" "${HOME}"

# For delete groupmod, groupadd, usermod and useradd from container
RUN apk del shadow

# Create crontab directory with proper permissions
RUN mkdir -p \
    ${DIR_LOG}/ \
    ${COMPOSER_CACHE_DIR}/ \
  && chown ${UID}:${GID} \
    ${DIR_LOG}/ \
    ${COMPOSER_CACHE_DIR}/ \
  && chmod -R 755 \
    ${DIR_LOG}/ \
    ${COMPOSER_CACHE_DIR}/

# Rebuild the font cache after installing fonts
RUN fc-cache -f -v

ARG APP_LOGROTATE=/etc/logrotate.d/app

COPY --chown=root:root .docker/dev/cron/app.logrotate ${APP_LOGROTATE}

RUN dos2unix ${APP_LOGROTATE} \
  && chmod 644 ${APP_LOGROTATE}

# Copy entrypoint script
COPY --chown=${UID}:${GID} .docker/dev/cron/entrypoint.sh /usr/local/bin/entrypoint.sh

RUN chmod +x /usr/local/bin/entrypoint.sh

WORKDIR /var/webroot/sites/export-products.strateg.ua/app

USER ${USERNAME}

ENTRYPOINT ["/usr/local/bin/entrypoint.sh"]