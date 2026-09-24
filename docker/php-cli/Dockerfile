FROM php:8.5-cli-alpine3.24

ENV COMPOSER_ALLOW_SUPERUSER=1

# Установщик расширений берём из Docker-Hub образа автора, а не ADD-ом с GitHub-релизов:
# тот эндпоинт отдавал 504 и валил сборку. Бинарь в образе уже исполняемый — chmod не нужен.
COPY --from=mlocati/php-extension-installer:latest /usr/bin/install-php-extensions /usr/local/bin/

RUN apk add --update zip unzip grep wget bash git openssh

RUN install-php-extensions redis gd intl pcntl pdo_pgsql pdo_mysql sockets zip amqp opcache bz2 gmp @composer
RUN rm  -rf /tmp/* /var/cache/apk/*

# Copy application code
WORKDIR /app
COPY app /app

# Install dependencies. --no-scripts обязателен (cache:clear в post-install-cmd
# читает .env, которого нет в build-контексте). Отдельный dump-autoload
# триггерит POST_AUTOLOAD_DUMP → symfony/runtime создаёт autoload_runtime.php.
RUN composer install --no-dev --optimize-autoloader --no-interaction --no-scripts
RUN composer dump-autoload --no-dev --optimize --classmap-authoritative

# Create cache directory
RUN mkdir -p /app/var/cache/prod && \
    mkdir -p /app/var/log

# Set proper permissions
RUN chown -R root:root /app
RUN chmod -R 755 /app
