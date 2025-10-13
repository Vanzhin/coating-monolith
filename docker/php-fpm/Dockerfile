FROM php:8.3-fpm

#ARG timezone
ARG INSTALL_XDEBUG=false
RUN if [ ${INSTALL_XDEBUG} = true ]; \
    then \
     pecl install xdebug && docker-php-ext-enable xdebug;\
fi;

RUN apt-get update && apt-get install --no-install-recommends --no-install-suggests -y \
		git \
        curl \
        htop \
        libmemcached-dev \
        cron\
        unzip \
        nano \
        libicu-dev \
        zlib1g-dev \
        libssl-dev \
        pkg-config \
        libzip-dev \
        libpq-dev \
        librabbitmq-dev \
        libssh-dev \
        libpng-dev \
    && rm -rf /var/lib/apt/lists/*

RUN set -xe \
    && docker-php-ext-configure \
        intl \
    && docker-php-ext-install \
        intl \
        opcache \
        zip \
        pdo \
        pdo_pgsql \
        bcmath \
        sockets \
        gd \
    && pecl install \
        apcu \
        redis \
        memcached \
        amqp \
        && rm -rf /tmp/pear \
    && docker-php-ext-enable \
        apcu \
        redis \
        memcached \
        amqp

COPY docker/php-fpm/php.ini /usr/local/etc/php/php.ini
#RUN sed -i -e "s#TIMEZONE#$timezone#g" /usr/local/etc/php/php.ini

COPY docker/php-fpm/xdebug.ini /tmp/xdebug.ini
#RUN cat /tmp/xdebug.ini >> /usr/local/etc/php/conf.d/docker-php-ext-xdebug.ini
RUN cat /tmp/xdebug.ini >> /usr/local/etc/php/conf.d/xdebug.ini
RUN rm /tmp/xdebug.ini

COPY --from=composer:2.7.2 /usr/bin/composer /usr/bin/composer
ENV COMPOSER_ALLOW_SUPERUSER=1

RUN mkdir -p /var/www/.composer && chown -R www-data /var/www/.composer

# Copy application code
WORKDIR /app
COPY app /app

# Create production .env file
RUN echo "APP_ENV=prod" > /app/.env && \
    echo "APP_DEBUG=0" >> /app/.env && \
    echo "APP_SECRET=${APP_SECRET}" >> /app/.env && \
    echo "APP_NAME=${APP_NAME}" >> /app/.env && \
    echo "DEFAULT_FROM_ADDR=${DEFAULT_FROM_ADDR}" >> /app/.env && \
    echo "DEFAULT_FROM_NAME=${DEFAULT_FROM_NAME}" >> /app/.env && \
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
    echo "REDIS_HOST=${REDIS_HOST}" >> /app/.env && \
    echo "ELASTIC_DSN=${ELASTIC_DSN}" >> /app/.env && \
    echo "ELASTIC_USERNAME=${ELASTIC_USERNAME}" >> /app/.env && \
    echo "ELASTIC_PASSWORD=${ELASTIC_PASSWORD}" >> /app/.env

# Install dependencies
RUN composer install --no-dev --optimize-autoloader --no-interaction --no-scripts

# Create cache directory
RUN mkdir -p /app/var/cache/prod && \
    mkdir -p /app/var/log

# Set proper permissions
RUN chown -R www-data:www-data /app
RUN chmod -R 755 /app

# выполняю команду генерации закрытого и открытого ключей для JWT
COPY docker/php-fpm/entrypoint.sh /entrypoint.sh
RUN chmod +x /entrypoint.sh
ENTRYPOINT ["/entrypoint.sh"]
CMD ["php-fpm"]
