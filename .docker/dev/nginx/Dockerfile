FROM nginx:1.29.1

WORKDIR /var/www/html

RUN apt-get update \
    && apt-get -y install tzdata \
    && rm -rf /var/lib/apt/lists/*

ENV TZ=Europe/Kyiv

# set time zone in container
RUN ln -fs /usr/share/zoneinfo/Europe/Kyiv /etc/localtime \
    && dpkg-reconfigure -f noninteractive tzdata

# Import UID and GID
ARG UID=1000
ARG GID=1000
ARG UMASK=0002

ENV UID=${UID}
ENV GID=${GID}
ENV UMASK=${UMASK}

# Create www-data user with proper shell (Debian syntax)
RUN deluser www-data 2>/dev/null || true \
    && delgroup www-data 2>/dev/null || true \
    && groupadd -g ${GID} www-data \
    && useradd -u ${UID} -g www-data -s /bin/bash -m www-data

# Set proper permissions for nginx directories
RUN chown -R www-data:www-data /var/cache/nginx /var/log/nginx /etc/nginx/conf.d \
    && chmod -R 755 /var/cache/nginx /var/log/nginx

# Copy and set permissions for entrypoint script
COPY .docker/dev/nginx/entrypoint.sh /usr/local/bin/entrypoint.sh

RUN chmod +x /usr/local/bin/entrypoint.sh \
    && chown www-data:www-data /usr/local/bin/entrypoint.sh

EXPOSE 80

USER www-data

ENTRYPOINT ["/usr/local/bin/entrypoint.sh"]