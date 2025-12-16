# Stage 1: Base image with timezone setup
FROM nginx:1.29.1-alpine3.22 AS base

WORKDIR /var/www/html

# Install timezone data
RUN apk add --no-cache tzdata \
    && rm -rf /var/cache/apk/*

ENV TZ=Europe/Kyiv

# Set time zone in container
RUN cp /usr/share/zoneinfo/Europe/Kyiv /etc/localtime \
    && echo "Europe/Kyiv" > /etc/timezone

# Stage 2: Final production image
FROM base AS production

# Import UID and GID
ARG UID=1000
ARG GID=1000
ARG UMASK=0002

ENV UID=${UID}
ENV GID=${GID}
ENV UMASK=${UMASK}

# Create www-data user with proper shell (Alpine syntax)
RUN deluser www-data 2>/dev/null || true \
    && delgroup www-data 2>/dev/null || true \
    && addgroup -g ${GID} www-data \
    && adduser -D -u ${UID} -G www-data -s /bin/sh www-data

# Create nginx runtime directories
RUN mkdir -p /var/run /var/cache/nginx \
    && touch /var/run/nginx.pid

# Set proper permissions for nginx directories
RUN chown -R www-data:www-data /var/cache/nginx /var/log/nginx /etc/nginx/conf.d \
    && chmod -R 755 /var/cache/nginx /var/log/nginx

# Copy and set permissions for entrypoint script
COPY .docker/prod/nginx/entrypoint.sh /usr/local/bin/entrypoint.sh

RUN chmod +x /usr/local/bin/entrypoint.sh \
    && chown www-data:www-data /usr/local/bin/entrypoint.sh

EXPOSE 80

USER www-data

ENTRYPOINT ["/usr/local/bin/entrypoint.sh"]