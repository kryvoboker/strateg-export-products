FROM postgres:18.1-alpine3.23

RUN apk add --no-cache tzdata

ENV TZ=Europe/Kyiv

# Set time zone in container
RUN cp /usr/share/zoneinfo/Europe/Kyiv /etc/localtime \
    && echo "Europe/Kyiv" > /etc/timezone

# Import UID and GID from docker compose
ARG UID=1000
ARG GID=1000
ARG UMASK=0002

ENV UID=${UID}
ENV GID=${GID}
ENV UMASK=${UMASK}
ENV USERNAME=postgres

# Create postgres group and user with specific UID/GID if they don't match
RUN deluser ${USERNAME} 2>/dev/null || true \
    && delgroup ${USERNAME} 2>/dev/null || true \
    && addgroup -g ${GID} ${USERNAME} \
    && adduser -D -u ${UID} -G ${USERNAME} -s /bin/bash ${USERNAME}

# Ensure proper ownership of PostgreSQL directories
RUN chown -R ${USERNAME}:${USERNAME} /var/lib/postgresql \
    && chown -R ${USERNAME}:${USERNAME} /var/run/postgresql

# Copy custom entrypoint
COPY .docker/dev/postgresql/entrypoint.sh /usr/local/bin/custom-entrypoint.sh
RUN chmod +x /usr/local/bin/custom-entrypoint.sh

USER ${USERNAME}

ENTRYPOINT ["/usr/local/bin/custom-entrypoint.sh"]
CMD ["postgres"]