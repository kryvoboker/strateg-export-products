FROM memcached:1.6.39-alpine3.23 AS base

# Ensure we run as root for package installation
USER root

RUN apk add --no-cache tzdata

ENV TZ=Europe/Kyiv

# set time zone in container
RUN ln -fs /usr/share/zoneinfo/Europe/Kyiv /etc/localtime

# Stage 2: Final production image
FROM base AS production

# Import UID and GID from docker compose
ARG UID=1000
ARG GID=1000
ARG UMASK=0002

ENV UID=${UID}
ENV GID=${GID}
ENV UMASK=${UMASK}

# Create memcache group and user with specific UID/GID if they don't match
RUN addgroup -g ${GID} -S memcache || true \
    && adduser -u ${UID} -S -G memcache memcache || true

# Copy custom entrypoint
COPY .docker/prod/memcached/entrypoint.sh /usr/local/bin/custom-entrypoint.sh
RUN chmod +x /usr/local/bin/custom-entrypoint.sh

USER memcache

ENTRYPOINT ["/usr/local/bin/custom-entrypoint.sh"]
CMD ["memcached"]