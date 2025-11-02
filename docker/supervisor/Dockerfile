FROM php:8.3-cli-alpine3.19

ADD https://github.com/mlocati/docker-php-extension-installer/releases/latest/download/install-php-extensions /usr/local/bin/
RUN chmod +x /usr/local/bin/install-php-extensions

RUN apk add --update zip unzip grep wget bash supervisor

RUN install-php-extensions redis gd intl pcntl pdo_pgsql pdo_mysql sockets zip amqp opcache bz2

COPY docker/supervisor/conf.d/supervisord.conf /etc/supervisor/supervisord.conf
RUN rm  -rf /tmp/* /var/cache/apk/*

# Copy application code
WORKDIR /app
COPY app /app

# Create production .env file


# Install composer
COPY --from=composer:2.7.2 /usr/bin/composer /usr/bin/composer
ENV COMPOSER_ALLOW_SUPERUSER=1

# Install dependencies
RUN composer install --no-dev --optimize-autoloader --no-interaction --no-scripts

# Create cache directory
RUN mkdir -p /app/var/cache/prod && \
    mkdir -p /app/var/log

# Create supervisor log directory
RUN mkdir -p /var/log/supervisor

# Copy supervisor configuration
COPY docker/supervisor/conf.d/php-worker.conf /etc/supervisor/conf.d/php-worker.conf

# Set proper permissions
RUN chown -R root:root /app
RUN chmod -R 755 /app

# Supervisor runs as root by default, no additional configuration needed
CMD ["/usr/bin/supervisord"]