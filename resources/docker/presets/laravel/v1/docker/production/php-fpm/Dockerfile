FROM php:8.4-fpm-bookworm AS php-base

RUN apt-get update && apt-get install -y --no-install-recommends \
    curl \
    git \
    libfreetype6-dev \
    libicu-dev \
    libjpeg62-turbo-dev \
    libpng-dev \
    libpq-dev \
    libzip-dev \
    unzip \
    && docker-php-ext-configure gd --with-freetype --with-jpeg \
    && docker-php-ext-install -j"$(nproc)" bcmath exif gd intl opcache pcntl pdo_mysql pdo_pgsql zip \
    && apt-get clean \
    && rm -rf /var/lib/apt/lists/*

COPY --from=composer:2 /usr/bin/composer /usr/local/bin/composer

FROM php-base AS composer-builder

ARG COMPOSER_AUTH_JSON
WORKDIR /var/www
COPY . .
RUN COMPOSER_AUTH="${COMPOSER_AUTH_JSON:-{}}" composer install \
    --no-dev \
    --optimize-autoloader \
    --no-interaction \
    --no-progress \
    --prefer-dist

FROM node:22-bookworm-slim AS frontend-builder

WORKDIR /var/www
COPY . .
COPY --from=composer-builder /var/www/vendor ./vendor
RUN corepack enable \
    && if [ -f pnpm-lock.yaml ]; then pnpm install --frozen-lockfile; \
       elif [ -f yarn.lock ]; then yarn install --immutable; \
       elif [ -f package-lock.json ]; then npm ci; \
       else npm install; fi \
    && if [ -f pnpm-lock.yaml ]; then pnpm run build; \
       elif [ -f yarn.lock ]; then yarn build; \
       else npm run build; fi

FROM php-base AS production

WORKDIR /var/www
COPY --from=composer-builder --chown=www-data:www-data /var/www /var/www
COPY --from=frontend-builder --chown=www-data:www-data /var/www/public /var/www/public
COPY docker/production/php-fpm/entrypoint.sh /usr/local/bin/entrypoint.sh
RUN chmod +x /usr/local/bin/entrypoint.sh \
    && mv "$PHP_INI_DIR/php.ini-production" "$PHP_INI_DIR/php.ini" \
    && mkdir -p storage/framework/cache storage/framework/sessions storage/framework/views storage/logs \
    && chown -R www-data:www-data storage bootstrap/cache

USER www-data
EXPOSE 9000
ENTRYPOINT ["/usr/local/bin/entrypoint.sh"]
CMD ["php-fpm"]
