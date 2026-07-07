FROM php:8.3-cli-alpine3.19

ADD https://github.com/mlocati/docker-php-extension-installer/releases/latest/download/install-php-extensions /usr/local/bin/
RUN chmod +x /usr/local/bin/install-php-extensions

# Переставляем UID/GID www-data с alpine-дефолта (82) на 33 (совпадает с debian
# php-fpm image, куда идёт тот же bind-mount /app). Иначе workers'ы под alpine
# www-data:82 не могут писать в директории, чьим владельцем init-контейнер
# сделал UID 33 → «Permission denied» на /app/var/cache/prod и /app/var/log.
#
# Через sed на /etc/passwd и /etc/group — не через deluser/adduser, потому что
# deluser НЕ удаляет одноимённую группу, а addgroup потом падает «group in use».
RUN sed -i 's/^www-data:x:82:82:/www-data:x:33:33:/' /etc/passwd && \
    sed -i 's/^www-data:x:82:/www-data:x:33:/' /etc/group

RUN apk add --update zip unzip grep wget bash supervisor

RUN install-php-extensions redis gd intl pcntl pdo_pgsql pdo_mysql sockets zip amqp opcache bz2

COPY docker/supervisor/conf.d/supervisord.conf /etc/supervisor/supervisord.conf
RUN rm  -rf /tmp/* /var/cache/apk/*

# Copy application code
WORKDIR /app
COPY app /app

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

# Copy supervisor configuration. php-worker (cache:warmup) удалён — init-контейнер
# manager_php-cli это уже делает, дублирование только создаёт риск race condition
# на var/cache/prod при рестарте supervisor'а.
COPY docker/supervisor/conf.d/messenger-worker.conf /etc/supervisor/conf.d/messenger-worker.conf

# Set proper permissions
RUN chown -R root:root /app
RUN chmod -R 755 /app

# Supervisor runs as root by default, no additional configuration needed
CMD ["/usr/bin/supervisord"]