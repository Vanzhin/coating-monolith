FROM php:8.3-cli-alpine3.19

ENV COMPOSER_ALLOW_SUPERUSER 1

ADD https://github.com/mlocati/docker-php-extension-installer/releases/latest/download/install-php-extensions /usr/local/bin/
RUN chmod +x /usr/local/bin/install-php-extensions

RUN apk add --update zip unzip grep wget bash git openssh

RUN install-php-extensions redis gd intl pcntl pdo_pgsql pdo_mysql sockets zip amqp opcache bz2 @composer
RUN rm  -rf /tmp/* /var/cache/apk/*

# Copy application code
WORKDIR /app
COPY app /app

# Create production .env file
RUN echo "APP_ENV=prod" > /app/.env && \
    echo "APP_DEBUG=0" >> /app/.env && \
    echo "APP_SECRET=your-secret-key-here" >> /app/.env && \
    echo "LOG_TELEGRAM_BOT_KEY=" >> /app/.env && \
    echo "LOG_TELEGRAM_CHANNEL=" >> /app/.env && \
    echo "DB_HOST=manager_db" >> /app/.env && \
    echo "DB_PORT=5432" >> /app/.env && \
    echo "DB_NAME=coating" >> /app/.env && \
    echo "DB_USER=postgres" >> /app/.env && \
    echo "DB_PASSWORD=password" >> /app/.env && \
    echo "REDIS_URL=redis://manager_redis:6379" >> /app/.env && \
    echo "ELASTIC_DSN=http://app_elastic:9200" >> /app/.env

# Install dependencies
RUN composer install --no-dev --optimize-autoloader --no-interaction --no-scripts

# Create cache directory
RUN mkdir -p /app/var/cache/prod && \
    mkdir -p /app/var/log

# Set proper permissions
RUN chown -R root:root /app
RUN chmod -R 755 /app
