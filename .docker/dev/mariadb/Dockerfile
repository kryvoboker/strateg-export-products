FROM mariadb:10.11.5

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

# Create mysql group and user with specific UID/GID if they don't match
RUN groupmod -o -g ${GID} mysql \
    && usermod -o -u ${UID} -g ${GID} mysql

# Ensure proper ownership of MariaDB directories
RUN chown -R mysql:mysql /var/lib/mysql \
    && chown -R mysql:mysql /var/run/mysqld \
    && chown -R mysql:mysql /etc/mysql

# Copy custom entrypoint
COPY .docker/dev/mariadb/entrypoint.sh /usr/local/bin/custom-entrypoint.sh
RUN chmod +x /usr/local/bin/custom-entrypoint.sh

USER mysql

ENTRYPOINT ["/usr/local/bin/custom-entrypoint.sh"]
CMD ["mysqld"]