FROM php:8.3-cli-alpine3.19

# Build arguments
ARG APP_SECRET
ARG APP_NAME
ARG DEFAULT_FROM_ADDR
ARG DEFAULT_FROM_NAME
ARG MAILER_DSN
ARG JWT_SECRET_KEY
ARG JWT_PUBLIC_KEY
ARG JWT_PASSPHRASE
ARG LOG_TELEGRAM_BOT_KEY
ARG LOG_TELEGRAM_CHANNEL
ARG DB_HOST
ARG DB_PORT
ARG DB_NAME
ARG DB_USER
ARG DB_PASSWORD
ARG REDIS_HOST
ARG ELASTIC_DSN
ARG ELASTIC_USERNAME
ARG ELASTIC_PASSWORD

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
RUN echo "APP_ENV=prod" > /app/.env && \
    echo "APP_DEBUG=0" >> /app/.env && \
    echo "APP_SECRET=${APP_SECRET}" >> /app/.env && \
    echo "APP_NAME=\"${APP_NAME}\"" >> /app/.env && \
    echo "DEFAULT_FROM_ADDR=\"${DEFAULT_FROM_ADDR}\"" >> /app/.env && \
    echo "DEFAULT_FROM_NAME=\"${DEFAULT_FROM_NAME}\"" >> /app/.env && \
    echo "MAILER_DSN=${MAILER_DSN}" >> /app/.env && \
    echo "JWT_SECRET_KEY=${JWT_SECRET_KEY}" >> /app/.env && \
    echo "JWT_PUBLIC_KEY=${JWT_PUBLIC_KEY}" >> /app/.env && \
    echo "JWT_PASSPHRASE=${JWT_PASSPHRASE}" >> /app/.env && \
    echo "LOG_TELEGRAM_BOT_KEY=${LOG_TELEGRAM_BOT_KEY}" >> /app/.env && \
    echo "LOG_TELEGRAM_CHANNEL=${LOG_TELEGRAM_CHANNEL}" >> /app/.env && \
    echo "DB_HOST=${DB_HOST}" >> /app/.env && \
    echo "DB_PORT=${DB_PORT}" >> /app/.env && \
    echo "DB_NAME=${DB_NAME}" >> /app/.env && \
    echo "DB_USER=${DB_USER}" >> /app/.env && \
    echo "DB_PASSWORD=${DB_PASSWORD}" >> /app/.env && \
    echo "DATABASE_URL=postgresql://${DB_USER}:${DB_PASSWORD}@${DB_HOST}:${DB_PORT}/${DB_NAME}" >> /app/.env && \
    echo "REDIS_HOST=${REDIS_HOST}" >> /app/.env && \
    echo "ELASTIC_DSN=${ELASTIC_DSN}" >> /app/.env && \
    echo "ELASTIC_USERNAME=${ELASTIC_USERNAME}" >> /app/.env && \
    echo "ELASTIC_PASSWORD=${ELASTIC_PASSWORD}" >> /app/.env

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

# Set proper permissions
RUN chown -R root:root /app
RUN chmod -R 755 /app

# Supervisor runs as root by default, no additional configuration needed
CMD ["/usr/bin/supervisord"]