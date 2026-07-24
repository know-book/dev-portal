# Stage 1: Build environment and Composer dependencies
FROM quay.io/hsuan/nthu/builder/php AS builder
ARG COMPOSER_AUTH_JSON
ENV COMPOSER_AUTH=$COMPOSER_AUTH_JSON

# Set the working directory inside the container
WORKDIR /var/www

COPY . /var/www

# Install Composer and dependencies
RUN composer install --no-dev --optimize-autoloader --no-interaction --no-progress --prefer-dist


FROM node:24-bookworm-slim AS frontend_builder

# Install Node.js and build tools
RUN apt-get update && apt-get install -y --no-install-recommends \
    curl \
    && apt-get clean && rm -rf /var/lib/apt/lists/* /tmp/* /var/tmp/*

# Set working directory
WORKDIR /var/www

# Copy Laravel application code
COPY . /var/www

COPY --from=builder /var/www/vendor /var/www/vendor

# Install Node.js dependencies and build assets
RUN corepack yarn && corepack yarn build

# Stage 2: Production environment
FROM php:8.4-fpm AS production

RUN apt-get update && apt-get install -y --no-install-recommends \
    postgresql-common \
    curl \
    ca-certificates \
    && yes | /usr/share/postgresql-common/pgdg/apt.postgresql.org.sh

# Install only runtime libraries needed in production
# libfcgi-bin and procps are required for the php-fpm-healthcheck script
RUN apt-get update && apt-get install -y --no-install-recommends \
    postgresql-client-18 \
    libpq-dev \
    libicu-dev \
    libzip-dev \
    libfcgi-bin \
    libpng-dev \
    libjpeg-dev \
    libfreetype6-dev \
    libgmp-dev \
    procps \
    && apt-get autoremove -y && apt-get clean && rm -rf /var/lib/apt/lists/* /tmp/* /var/tmp/*

# Download and install php-fpm health check script
RUN curl -o /usr/local/bin/php-fpm-healthcheck \
    https://raw.githubusercontent.com/renatomefi/php-fpm-healthcheck/master/php-fpm-healthcheck \
    && chmod +x /usr/local/bin/php-fpm-healthcheck

# Copy the initialization script
COPY ./docker/production/php-fpm/entrypoint.sh /usr/local/bin/entrypoint.sh
RUN chmod +x /usr/local/bin/entrypoint.sh

# Copy the initial storage structure
COPY ./storage /var/www/storage-init

# Copy PHP extensions and libraries from the builder stage
COPY --from=builder /usr/local/lib/php/extensions/ /usr/local/lib/php/extensions/
COPY --from=builder /usr/local/etc/php/conf.d/ /usr/local/etc/php/conf.d/
COPY --from=builder /usr/local/bin/docker-php-ext-* /usr/local/bin/

# Use the recommended production PHP configuration
# -----------------------------------------------------------
# PHP provides development and production configurations.
# Here, we replace the default php.ini with the production
# version to apply settings optimized for performance and
# security in a live environment.
# -----------------------------------------------------------
RUN mv "$PHP_INI_DIR/php.ini-production" "$PHP_INI_DIR/php.ini"

# Enable PHP-FPM status page by modifying zz-docker.conf with sed
RUN { \
    echo '[www]'; \
    echo 'pm.status_path = /status'; \
    echo 'access.log = /proc/self/fd/2'; \
    echo 'access.format = "%{REMOTE_ADDR}e - %u %t \"%m %r%Q%q\" %s %f %{milli}d %{kilo}M %C%%"'; \
} > /usr/local/etc/php-fpm.d/zz-status.conf
# Update the variables_order to include E (for ENV)
#RUN sed -i 's/variables_order = "GPCS"/variables_order = "EGPCS"/' "$PHP_INI_DIR/php.ini"

# Copy the application code and dependencies from the build stage
COPY --from=builder /var/www /var/www

# Copy node
COPY --from=frontend_builder /var/www/public /var/www/public

# Copy mysqldump bin
COPY --from=mysql:8.0-bookworm /usr/bin/mysqldump /usr/bin/mysqldump

# Set working directory
WORKDIR /var/www

# Ensure correct permissions
RUN chown -R www-data:www-data /var/www

# Switch to the non-privileged user to run the application
USER www-data

# Change the default command to run the entrypoint script
ENTRYPOINT ["/usr/local/bin/entrypoint.sh"]

# Expose port 9000 and start php-fpm server
EXPOSE 9000
CMD ["php-fpm"]
