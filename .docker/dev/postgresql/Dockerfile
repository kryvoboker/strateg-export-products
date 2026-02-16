FROM postgres:18.1-alpine3.23

WORKDIR /var/webroot/sites/export-products.strateg.ua/app

RUN apk add --no-cache tzdata

ENV TZ=Europe/Kyiv

# Set time zone in container
RUN cp /usr/share/zoneinfo/Europe/Kyiv /etc/localtime \
  && echo "Europe/Kyiv" > /etc/timezone

# Import UID and GID from docker compose
ARG UID
ARG GID
ARG UMASK
ARG USERNAME

# Create postgres group and user with specific UID/GID if they don't match
RUN deluser ${USERNAME} 2>/dev/null || true \
  && delgroup ${USERNAME} 2>/dev/null || true \
  && addgroup -g ${GID} ${USERNAME} \
  && adduser -D -u ${UID} -G ${USERNAME} -s /bin/bash ${USERNAME}

# Ensure proper ownership of PostgreSQL directories
RUN chown -R ${UID}:${GID} /var/lib/postgresql/ \
  && chown -R ${UID}:${GID} /var/run/postgresql/

# Copy custom entrypoint
COPY --chown=${UID}:${GID} .docker/dev/postgresql/entrypoint.sh /usr/local/bin/custom-entrypoint.sh

RUN chmod +x /usr/local/bin/custom-entrypoint.sh

USER ${USERNAME}

ENTRYPOINT ["/usr/local/bin/custom-entrypoint.sh"]

CMD ["postgres"]