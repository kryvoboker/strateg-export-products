FROM composer:2.9.2 AS composer

FROM php:8.5-fpm-bookworm AS php-fpm

ARG DEBIAN_FRONTEND=noninteractive
ENV TZ=Europe/Kyiv

RUN apt-get update \
  && apt-get -y install --no-install-recommends  \
    curl  \
    ca-certificates  \
    unzip  \
    zip  \
    tzdata  \
    nano \
  && rm -rf /var/lib/apt/lists/*

RUN ln -fs /usr/share/zoneinfo/${TZ} /etc/localtime \
  && echo "date.timezone=${TZ}" > /usr/local/etc/php/conf.d/99-timezone.ini

COPY --from=mlocati/php-extension-installer /usr/bin/install-php-extensions /usr/local/bin/

RUN install-php-extensions gd zip pdo pdo_pgsql pgsql xdebug xhprof sockets intl bcmath apcu \
  && rm -f /usr/local/bin/install-php-extensions

RUN echo "xhprof.output_dir = /tmp/xhprof" >> /usr/local/etc/php/conf.d/docker-php-ext-xhprof.ini \
  && echo "xhprof.collect_additional_info = 1" >> /usr/local/etc/php/conf.d/docker-php-ext-xhprof.ini \
  && echo "xdebug.mode=debug" >> /usr/local/etc/php/conf.d/docker-php-ext-xdebug.ini \
  && echo "xdebug.client_host = host.docker.internal" >> /usr/local/etc/php/conf.d/docker-php-ext-xdebug.ini

RUN docker-php-ext-enable xdebug xhprof

FROM node:24.11.0-bookworm-slim AS nodejs

FROM php-fpm AS development

# Copy Node.js binaries from nodejs stage
COPY --from=nodejs /usr/local/bin/node /usr/local/bin/node
COPY --from=nodejs /usr/local/lib/node_modules /usr/local/lib/node_modules

# Re-create symlinks exactly like in official node image
RUN ln -sf /usr/local/lib/node_modules/npm/bin/npm-cli.js /usr/local/bin/npm \
  && ln -sf /usr/local/lib/node_modules/npm/bin/npx-cli.js /usr/local/bin/npx

# Composer from composer stage
COPY --from=composer /usr/bin/composer /usr/bin/composer

# Import UID and GID from docker compose
ARG UID
ARG GID
ARG UMASK
ARG USERNAME=www-data

# Configure composer cache directory
ENV HOME=/home/${USERNAME}
ENV COMPOSER_HOME=${HOME}/.composer
ENV COMPOSER_CACHE_DIR=${COMPOSER_HOME}/cache

# Configure npm prefix for processes in the image and ensure PATH includes it
ENV NPM_CONFIG_PREFIX=${HOME}/.npm-global
ENV NPM_CONFIG_CACHE=${HOME}/.npm
ENV PATH="${NPM_CONFIG_PREFIX}/bin:${PATH}"

RUN groupmod -o -g ${GID} ${USERNAME} \
  && usermod  -o -u ${UID} -g ${GID} ${USERNAME}

# Create npm global directory for www-data and set ownership
RUN mkdir -p ${NPM_CONFIG_PREFIX}/ ${NPM_CONFIG_CACHE}/ ${COMPOSER_CACHE_DIR}/ \
  && chown -R ${UID}:${GID} ${COMPOSER_HOME}/ ${NPM_CONFIG_PREFIX}/ ${NPM_CONFIG_CACHE}/ \
  && chmod -R 775 ${COMPOSER_HOME}/ ${NPM_CONFIG_CACHE}/ ${NPM_CONFIG_PREFIX}/

# Copy and set permissions for entrypoint script
COPY .docker/dev/php/entrypoint.sh /usr/local/bin/entrypoint.sh

RUN chmod +x /usr/local/bin/entrypoint.sh \
  && chown ${UID}:${GID} /usr/local/bin/entrypoint.sh

WORKDIR /var/webroot/sites/export-products.strateg.ua/app

EXPOSE 9000

USER ${USERNAME}

ENTRYPOINT ["/usr/local/bin/entrypoint.sh"]