FROM php:8.3-cli-alpine3.19

# Установщик расширений берём из Docker-Hub образа автора, а не ADD-ом с GitHub-релизов:
# тот эндпоинт отдавал 504 и валил сборку. Бинарь в образе уже исполняемый — chmod не нужен.
COPY --from=mlocati/php-extension-installer:latest /usr/bin/install-php-extensions /usr/local/bin/

RUN apk add --update zip unzip grep wget bash supervisor

RUN install-php-extensions redis gd intl pcntl pdo_pgsql pdo_mysql sockets zip amqp opcache bz2 gmp

COPY docker/supervisor/conf.d/supervisord.conf /etc/supervisor/supervisord.conf
RUN rm  -rf /tmp/* /var/cache/apk/*

# Copy application code
WORKDIR /app
COPY app /app

# Install composer
COPY --from=composer:2.7.2 /usr/bin/composer /usr/bin/composer
ENV COMPOSER_ALLOW_SUPERUSER=1

# Install dependencies. --no-scripts обязателен (cache:clear в post-install-cmd
# читает .env, которого нет в build-контексте). Отдельный dump-autoload
# триггерит POST_AUTOLOAD_DUMP → symfony/runtime создаёт autoload_runtime.php.
RUN composer install --no-dev --optimize-autoloader --no-interaction --no-scripts
RUN composer dump-autoload --no-dev --optimize --classmap-authoritative

# Create cache directory
RUN mkdir -p /app/var/cache/prod && \
    mkdir -p /app/var/log

# Create supervisor log directory
RUN mkdir -p /var/log/supervisor

# Copy supervisor configuration
COPY docker/supervisor/conf.d/php-worker.conf /etc/supervisor/conf.d/php-worker.conf
COPY docker/supervisor/conf.d/messenger-worker.conf /etc/supervisor/conf.d/messenger-worker.conf

# Set proper permissions
RUN chown -R root:root /app
RUN chmod -R 755 /app

# Supervisor runs as root by default, no additional configuration needed
CMD ["/usr/bin/supervisord"]