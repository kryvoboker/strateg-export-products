FROM postgres:14.0

RUN apt-get update \
    && apt-get -y install tzdata \
    && rm -rf /var/lib/apt/lists/*

ENV TZ=Europe/Kyiv

# set time zone in container
RUN ln -fs /usr/share/zoneinfo/Europe/Kyiv /etc/localtime \
    && dpkg-reconfigure -f noninteractive tzdata

# Import UID and GID from docker compose
ARG UID=1000
ARG GID=1000
ARG UMASK=0002

ENV UID=${UID}
ENV GID=${GID}
ENV UMASK=${UMASK}

# Create postgres group and user with specific UID/GID if they don't match
RUN groupmod -o -g ${GID} postgres \
    && usermod -o -u ${UID} -g ${GID} postgres

# Ensure proper ownership of PostgreSQL directories
RUN chown -R postgres:postgres /var/lib/postgresql \
    && chown -R postgres:postgres /var/run/postgresql

# Copy custom entrypoint
COPY .docker/dev/postgresql/entrypoint.sh /usr/local/bin/custom-entrypoint.sh
RUN chmod +x /usr/local/bin/custom-entrypoint.sh

USER postgres

ENTRYPOINT ["/usr/local/bin/custom-entrypoint.sh"]
CMD ["postgres"]