FROM redis:8.6.1-alpine3.23

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

RUN deluser ${USERNAME} 2>/dev/null || true \
  && delgroup ${USERNAME} 2>/dev/null || true \
  && addgroup -g ${GID} ${USERNAME} \
  && adduser -D -u ${UID} -G ${USERNAME} -s /bin/bash ${USERNAME}

RUN mkdir -p /data/ /var/log/ \
  && chown -R ${UID}:${GID} /data/ /var/log/

COPY .docker/dev/redis/redis.conf /usr/local/etc/redis/redis.conf

# Copy custom entrypoint
COPY --chown=${UID}:${GID} .docker/dev/redis/entrypoint.sh /usr/local/bin/custom-entrypoint.sh

RUN chmod +x /usr/local/bin/custom-entrypoint.sh

USER ${USERNAME}

EXPOSE 6379

ENTRYPOINT ["/usr/local/bin/custom-entrypoint.sh"]

CMD ["redis-server", "/usr/local/etc/redis/redis.conf", "--appendonly", "yes", "--save", "60", "1000"]