# Tackle - a local-first WordPress hook index.
# This image bakes in every PHP extension Tackle needs (pdo_sqlite for storage,
# zip for installing plugins; curl + openssl ship with the base image), so there
# is no php.ini fiddling.

FROM php:8.3-cli

# System libraries required to build the pdo_sqlite and zip extensions.
RUN apt-get update \
    && apt-get install -y --no-install-recommends libzip-dev libsqlite3-dev \
    && docker-php-ext-install -j"$(nproc)" pdo_sqlite zip \
    && rm -rf /var/lib/apt/lists/*

# Allow large plugin/theme zip uploads through the Browse > Upload feature.
RUN { \
        echo 'upload_max_filesize=128M'; \
        echo 'post_max_size=128M'; \
    } > "$PHP_INI_DIR/conf.d/tackle.ini"

WORKDIR /app
COPY . /app

# The hook database and any downloaded plugins/themes persist in volumes.
VOLUME ["/app/data", "/app/wordpress"]

EXPOSE 8000

# PHP's built-in server, with index.php as the front controller/router.
CMD ["php", "-S", "0.0.0.0:8000", "index.php"]
