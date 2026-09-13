FROM php:8.3-cli

# pdo_sqlite is bundled with php:cli images. Add pdo_mysql and pdo_pgsql so
# any of the three DB_DRIVER options in config.php work out of the box.
RUN apt-get update \
    && apt-get install -y --no-install-recommends libpq-dev \
    && docker-php-ext-install pdo_mysql pgsql pdo_pgsql \
    && rm -rf /var/lib/apt/lists/*

WORKDIR /var/www/html
COPY . .

# Render sets $PORT at runtime; default to 8000 for local `docker run`.
ENV PORT=8000
EXPOSE 8000

CMD php -S 0.0.0.0:${PORT}
