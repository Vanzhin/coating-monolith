FROM php:8.5-fpm

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

# Расширения ставим через install-php-extensions (как в alpine-образах проекта): он знает, какие
# расширения на PHP 8.5 уже в ядре, и подбирает совместимые pecl-версии (apcu/redis/memcached/amqp).
# Ручной docker-php-ext-install+pecl ломался на 8.5/trixie: pdo стал частью ядра → пустой modules/*.
COPY --from=mlocati/php-extension-installer:latest /usr/bin/install-php-extensions /usr/local/bin/
RUN install-php-extensions intl opcache zip pdo_pgsql bcmath sockets gd apcu redis memcached amqp

COPY docker/php-fpm/php.ini /usr/local/etc/php/php.ini
#RUN sed -i -e "s#TIMEZONE#$timezone#g" /usr/local/etc/php/php.ini

COPY docker/php-fpm/xdebug.ini /tmp/xdebug.ini
#RUN cat /tmp/xdebug.ini >> /usr/local/etc/php/conf.d/docker-php-ext-xdebug.ini
RUN cat /tmp/xdebug.ini >> /usr/local/etc/php/conf.d/xdebug.ini
RUN rm /tmp/xdebug.ini

# Copy application code
WORKDIR /app
COPY app /app

# Install composer
COPY --from=composer:2.8 /usr/bin/composer /usr/bin/composer

# Разрешаем composer'у от root'а активировать plugins. Без этой переменной
# composer 2.x в non-interactive сессии молча отключает ВСЕ plugins
# ("plugins have been disabled for safety") — тогда symfony/runtime не
# подписывается на POST_AUTOLOAD_DUMP и vendor/autoload_runtime.php не
# создаётся (index.php падает с "Failed to open stream"). Локально проблема
# не воспроизводится, потому что composer видит host-user, а не root.
ENV COMPOSER_ALLOW_SUPERUSER=1

# Install dependencies. --no-scripts тут ОБЯЗАТЕЛЕН: post-install-cmd → auto-scripts
# → cache:clear читает .env, а .env в git не коммитится → в build-контексте его нет.
# Отдельным шагом composer dump-autoload триггерит POST_AUTOLOAD_DUMP → symfony/runtime
# plugin создаёт vendor/autoload_runtime.php (нужный /app/public/index.php).
# dump-autoload НЕ вызывает post-install-cmd, значит cache:clear не побежит.
RUN composer install --no-dev --optimize-autoloader --no-interaction --no-scripts
RUN composer dump-autoload --no-dev --optimize --classmap-authoritative

# Create cache directory
RUN mkdir -p /app/var/cache/prod && \
    mkdir -p /app/var/log

# Set proper permissions
RUN chown -R www-data:www-data /app
RUN chmod -R 755 /app

RUN chmod -R 777 /app/var/log

# выполняю команду генерации закрытого и открытого ключей для JWT
COPY docker/php-fpm/entrypoint.sh /entrypoint.sh
RUN chmod +x /entrypoint.sh
ENTRYPOINT ["/entrypoint.sh"]
CMD ["php-fpm"]
