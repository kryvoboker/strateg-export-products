FROM postgres:18.1-alpine3.23

WORKDIR /var/webroot/sites/export-products.strateg.ua/app

RUN apk add --no-cache \
    tzdata \
    shadow

ENV TZ=Europe/Kyiv
ENV HOME=/home/${USERNAME}

# Set time zone in container
RUN cp /usr/share/zoneinfo/Europe/Kyiv /etc/localtime \
  && echo "Europe/Kyiv" > /etc/timezone

# Import UID and GID from docker compose
ARG UID
ARG GID
ARG UMASK
ARG USERNAME

# Create postgres group and user with specific UID/GID if they don't match
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

# Ensure proper ownership of PostgreSQL directories
RUN chown -R ${UID}:${GID} /var/lib/postgresql/ \
  && chown -R ${UID}:${GID} /var/run/postgresql/

# Copy custom entrypoint
COPY --chown=${UID}:${GID} .docker/dev/postgresql/entrypoint.sh /usr/local/bin/custom-entrypoint.sh

RUN chmod +x /usr/local/bin/custom-entrypoint.sh

USER ${USERNAME}

EXPOSE 5432

ENTRYPOINT ["/usr/local/bin/custom-entrypoint.sh"]

CMD ["postgres"]