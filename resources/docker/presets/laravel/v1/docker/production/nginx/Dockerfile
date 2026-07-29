FROM php:8.4-cli-bookworm AS composer-builder

ARG COMPOSER_AUTH_JSON
RUN apt-get update && apt-get install -y --no-install-recommends git unzip \
    && rm -rf /var/lib/apt/lists/*
COPY --from=composer:2 /usr/bin/composer /usr/local/bin/composer
WORKDIR /var/www
COPY . .
RUN COMPOSER_AUTH="${COMPOSER_AUTH_JSON:-{}}" composer install \
    --no-dev \
    --optimize-autoloader \
    --no-interaction \
    --no-progress \
    --prefer-dist \
    --ignore-platform-reqs

FROM node:22-bookworm-slim AS frontend-builder

WORKDIR /var/www
COPY . .
COPY --from=composer-builder /var/www/vendor ./vendor
RUN corepack enable \
    && if [ -f pnpm-lock.yaml ]; then pnpm install --frozen-lockfile; \
       elif [ -f yarn.lock ]; then yarn install --immutable; \
       else npm ci; fi \
    && if [ -f pnpm-lock.yaml ]; then pnpm run build; \
       elif [ -f yarn.lock ]; then yarn build; \
       else npm run build; fi

FROM nginx:1.27-alpine AS production

COPY docker/production/nginx/nginx.conf /etc/nginx/nginx.conf
COPY --from=frontend-builder /var/www/public /var/www/public
WORKDIR /var/www/public
EXPOSE 80
CMD ["nginx", "-g", "daemon off;"]
