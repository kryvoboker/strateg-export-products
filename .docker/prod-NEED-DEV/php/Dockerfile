# Stage 1: Base image with PHP extensions
FROM php:8.4-fpm AS base

WORKDIR /var/www/html

# Install system dependencies and PHP extensions in one layer
RUN apt-get update \
    && apt-get -y install --no-install-recommends \
        curl \
        ca-certificates \
        unzip \
        zip \
        tzdata \
        ffmpeg \
    && rm -rf /var/lib/apt/lists/*

ENV TZ=Europe/Kyiv

# Set time zone in container
RUN ln -fs /usr/share/zoneinfo/Europe/Kyiv /etc/localtime \
    && dpkg-reconfigure -f noninteractive tzdata

# Copy installers from official images
COPY --from=composer:2.8.8 /usr/bin/composer /usr/bin/composer
COPY --from=mlocati/php-extension-installer /usr/bin/install-php-extensions /usr/local/bin/

# Install PHP extensions
RUN install-php-extensions gd zip mysqli apcu memcached pdo pdo_mysql sockets bcmath \
    && rm -f /usr/local/bin/install-php-extensions

# Stage 2: Node.js installation
FROM base AS nodejs

# Install Node.js 24.11.0 from official tarball
RUN apt-get update \
    && apt-get install -y --no-install-recommends curl xz-utils ca-certificates \
    && export NODE_VERSION=24.11.0 \
    && curl -fsSLO https://nodejs.org/dist/v${NODE_VERSION}/node-v${NODE_VERSION}-linux-x64.tar.xz \
    && tar -xJf node-v${NODE_VERSION}-linux-x64.tar.xz -C /usr/local --strip-components=1 \
    && rm node-v${NODE_VERSION}-linux-x64.tar.xz \
    && apt-get purge -y --auto-remove xz-utils \
    && rm -rf /var/lib/apt/lists/*

# Stage 3: Final production image
FROM base AS production

# Copy Node.js binaries from nodejs stage
COPY --from=nodejs /usr/local/bin/node /usr/local/bin/node
COPY --from=nodejs /usr/local/bin/npm /usr/local/bin/npm
COPY --from=nodejs /usr/local/bin/npx /usr/local/bin/npx
COPY --from=nodejs /usr/local/lib/node_modules /usr/local/lib/node_modules

# Import UID and GID from docker compose
ARG UID=1000
ARG GID=1000
ARG UMASK=0002

ENV UID=${UID}
ENV GID=${GID}
ENV UMASK=${UMASK}

# Create www-data user
RUN deluser www-data 2>/dev/null || true \
    && delgroup www-data 2>/dev/null || true \
    && groupadd -g ${GID} www-data \
    && useradd -u ${UID} -g www-data -s /bin/bash -m www-data

# Create and configure directories with proper permissions
RUN mkdir -p /home/www-data/.npm-global /var/www/.npm /home/www-data/.composer/cache \
    && chown -R www-data:www-data /home/www-data/.npm-global /var/www/.npm /home/www-data/.composer \
    && chmod -R 775 /home/www-data/.composer

# Configure npm and composer
ENV NPM_CONFIG_PREFIX=/home/www-data/.npm-global
ENV PATH="/home/www-data/.npm-global/bin:${PATH}"
ENV COMPOSER_CACHE_DIR=/home/www-data/.composer/cache

# Copy and set permissions for entrypoint script
COPY .docker/prod/php/entrypoint.sh /usr/local/bin/entrypoint.sh

RUN chmod +x /usr/local/bin/entrypoint.sh \
    && chown www-data:www-data /usr/local/bin/entrypoint.sh

USER www-data

ENTRYPOINT ["/usr/local/bin/entrypoint.sh"]