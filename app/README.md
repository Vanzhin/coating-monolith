# Приложение на Симфони (первичная авторизация)

Репозиторий содержит контейнеры для запуска приложения.
Умеет создавать пользователя в консоли, отдавать JWT и refresh token

## 1. Термины

- Symfony — фреймворк основного контейнера (php-fpm). Можно почитать [здесь](https://symfony.com/doc/current/index.html)

## 2. Используемые технологии

Основные технологии: PHP, Redis, PostgreSQL
Код пишем на PHP

### 2.1 Общие технологии

| Технология | Версия          | Описание                           | Ссылка                     |
|------------|-----------------|------------------------------------|----------------------------|
| PHP        | 8.3             | PHP                                | https://www.php.net        |
| PostgreSQL | 16.0-alpine3.17 | Реляционная БД                     | https://www.postgresql.org |
| Nginx      | 1.25.4          | Прокси-сервер                      | https://www.nginx.com      |
| Redis      | 7.2.1           | быстрая БД для временного хранения | https://redis.io           |

## 3. Подготовка окружения для запуска

1. Проверить, что установлен Git
    ```shell
    git -v
    ```
2. Установить [Docker-compose](https://docs.docker.com/compose/install/linux/#install-the-plugin-manually).

3. Проверить, что установлен php
    ```shell
    php -v
    ```
   если нет установить [php](https://www.php.net/downloads).

4. Проверить, что установлен composer
   ```shell
   composer
   ```
   если нет установить [composer](https://getcomposer.org/download/).

## 4. Установка

1. Склонировать репозиторий в текущую директорию
    ```shell
    git clone git@github.com:Vanzhin/auth.git
    ```
2. Создать файлы .env путем их копирования из .env.example в директориях docker и app, установить значение переменных
    ```shell
    cd ./docker
    ```
3. Перейти в директорию с файлом docker-compose.yaml
    ```shell
    cd ./docker
    ```

## 5. Запуск

   ```shell
   docker compose up -d
   ```

## 6. Инфо для разработки

a. Проверить код на соответствие стилю

   ```shell
    vendor/bin/php-cs-fixer fix --dry-run --diff
   ```

b. Исправить код

   ```shell
    vendor/bin/php-cs-fixer fix --diff
   ```

c. Проверить соответствие зависимостей

   ```shell
    vendor/bin/deptrac analyse --config-file=deptrac-modules.yaml
   ```

   ```shell
    vendor/bin/deptrac analyse --config-file=deptrac-layers.yaml
   ```

После чего, в браузере можно открыть страницу https://localhost

*Profit !*