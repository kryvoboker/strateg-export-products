FROM nginx:1.29.5-alpine3.23-slim

RUN apk add --no-cache \
    tzdata

ENV TZ=Europe/Kyiv

# Set time zone in container
RUN cp /usr/share/zoneinfo/Europe/Kyiv /etc/localtime \
  && echo "Europe/Kyiv" > /etc/timezone

# Import UID and GID
ARG UID
ARG GID
ARG UMASK
ARG USERNAME

# Create www-data user with proper shell (Debian syntax)
RUN deluser ${USERNAME} 2>/dev/null || true \
  && delgroup ${USERNAME} 2>/dev/null || true \
  && addgroup -g ${GID} ${USERNAME} \
  && adduser -D -u ${UID} -G ${USERNAME} -s /bin/sh ${USERNAME}

# Set proper permissions for nginx directories
RUN mkdir -p /var/cache/nginx/ /var/log/nginx/ /var/run/nginx/ \
  && chown -R ${UID}:${GID} /var/cache/nginx/ /var/log/nginx/ /var/run/nginx/ /etc/nginx/conf.d/ \
  && chmod -R 755 /var/cache/nginx/ /var/log/nginx/ /var/run/nginx/

# Copy and set permissions for entrypoint script
COPY --chown=${UID}:${GID} .docker/dev/nginx/entrypoint.sh /usr/local/bin/entrypoint.sh

RUN chmod +x /usr/local/bin/entrypoint.sh

WORKDIR /var/webroot/sites/export-products.strateg.ua/app

EXPOSE 80

USER ${USERNAME}

ENTRYPOINT ["/usr/local/bin/entrypoint.sh"]