FROM php:8.4-fpm

WORKDIR /var/www/html

RUN apt-get update \
    && apt-get -y install curl ca-certificates unzip zip tzdata nano \
    && rm -rf /var/lib/apt/lists/*

ENV TZ=Europe/Kyiv

# set time zone in container
RUN ln -fs /usr/share/zoneinfo/Europe/Kyiv /etc/localtime \
    && dpkg-reconfigure -f noninteractive tzdata

COPY --from=composer:2.8.8 /usr/bin/composer /usr/bin/composer
COPY --from=mlocati/php-extension-installer /usr/bin/install-php-extensions /usr/local/bin/

# Configure composer cache directory
ENV COMPOSER_CACHE_DIR=/home/www-data/.composer/cache

RUN install-php-extensions gd zip pdo pdo_pgsql pgsql xdebug xhprof sockets intl bcmath apcu

RUN echo "xhprof.output_dir = /tmp/xhprof" >> /usr/local/etc/php/conf.d/docker-php-ext-xhprof.ini \
    && echo "xhprof.collect_additional_info = 1" >> /usr/local/etc/php/conf.d/docker-php-ext-xhprof.ini \
    && echo "xdebug.mode=debug" >> /usr/local/etc/php/conf.d/docker-php-ext-xdebug.ini \
    && echo "xdebug.client_host = host.docker.internal" >> /usr/local/etc/php/conf.d/docker-php-ext-xdebug.ini

RUN docker-php-ext-enable xdebug xhprof

# Install exact Node.js 24.11.0 from official tarball
# This installs node and npm system-wide so www-data can run them
RUN apt-get update \
    && apt-get install -y curl xz-utils ca-certificates \
    && export NODE_VERSION=24.11.0 \
    && curl -fsSLO https://nodejs.org/dist/v${NODE_VERSION}/node-v${NODE_VERSION}-linux-x64.tar.xz \
    && tar -xJf node-v${NODE_VERSION}-linux-x64.tar.xz -C /usr/local --strip-components=1 \
    && rm node-v${NODE_VERSION}-linux-x64.tar.xz \
    && apt-get purge -y --auto-remove xz-utils \
    && rm -rf /var/lib/apt/lists/*

# Configure npm prefix for processes in the image and ensure PATH includes it
ENV NPM_CONFIG_PREFIX=/home/www-data/.npm-global
ENV PATH="/home/www-data/.npm-global/bin:${PATH}"

# Import UID and GID from docker compose
ARG UID=1000
ARG GID=1000
ARG UMASK=0002

ENV UID=${UID}
ENV GID=${GID}
ENV UMASK=${UMASK}

RUN groupmod -o -g ${GID} www-data \
  && usermod  -o -u ${UID} -g ${GID} www-data

# Create npm global directory for www-data and set ownership
RUN mkdir -p /home/www-data/.npm-global /var/www/.npm \
    && chown -R www-data:www-data /home/www-data/.npm-global \
    && chown -R www-data:www-data /var/www/.npm

RUN mkdir -p /home/www-data/.composer/cache \
    && chown -R www-data:www-data /home/www-data/.composer \
    && chmod -R 775 /home/www-data/.composer

# Copy and set permissions for entrypoint script
COPY .docker/dev/php/entrypoint.sh /usr/local/bin/entrypoint.sh

RUN chmod +x /usr/local/bin/entrypoint.sh \
    && chown www-data:www-data /usr/local/bin/entrypoint.sh

USER www-data

ENTRYPOINT ["/usr/local/bin/entrypoint.sh"]