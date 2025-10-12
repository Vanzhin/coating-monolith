# 🚀 Deployment Guide

## GitHub Actions Deployment

### 1. Настройка GitHub Secrets

В настройках репозитория (`Settings` → `Secrets and variables` → `Actions`) добавьте:

#### Обязательные секреты:
- `HOST` - IP адрес или домен сервера
- `USERNAME` - пользователь для SSH подключения
- `SSH_KEY` - приватный SSH ключ для подключения к серверу

#### Опциональные секреты:
- `TELEGRAM_BOT_TOKEN` - токен Telegram бота
- `SMS_API_KEY` - API ключ SMS провайдера
- `MAILER_PASSWORD` - пароль для SMTP

### 2. Настройка сервера

#### Установка Docker и Docker Compose:
```bash
# Ubuntu/Debian
curl -fsSL https://get.docker.com -o get-docker.sh
sh get-docker.sh
sudo usermod -aG docker $USER

# Docker Compose
sudo curl -L "https://github.com/docker/compose/releases/latest/download/docker-compose-$(uname -s)-$(uname -m)" -o /usr/local/bin/docker-compose
sudo chmod +x /usr/local/bin/docker-compose
```

#### Создание директории проекта:
```bash
mkdir -p /var/www/sites/1helper
cd /var/www/sites/1helper
```

#### Клонирование репозитория:
```bash
git clone https://github.com/Vanzhin/coating-monolith.git .
```

#### Настройка переменных окружения:
```bash
cp .env.prod.example .env.prod
nano .env.prod  # Заполните необходимые переменные
```

### 3. Настройка SSL (опционально)

#### Установка Certbot:
```bash
sudo apt update
sudo apt install certbot python3-certbot-nginx
```

#### Получение SSL сертификата:
```bash
sudo certbot --nginx -d yourdomain.com
```

### 4. Автоматический деплой

#### Push в main ветку:
```bash
git add .
git commit -m "Deploy to production"
git push origin main
```

GitHub Actions автоматически:
1. Запустит тесты
2. Соберет Docker образы
3. Загрузит их в GitHub Container Registry
4. Развернет на сервере

### 5. Ручной деплой

#### На сервере:
```bash
cd /var/www/sites/1helper
./deploy.sh
```

## Структура деплоя

### GitHub Actions Workflows:

1. **`.github/workflows/deploy.yml`** - Основной workflow:
   - Тестирование
   - Сборка и загрузка образов
   - Деплой на сервер

2. **`.github/workflows/docker-build.yml`** - Сборка Docker образов:
   - PHP-FPM
   - PHP-CLI
   - Supervisor

### Docker конфигурация:

1. **`Dockerfile`** - Основной образ приложения
2. **`docker-compose.prod.yml`** - Production конфигурация
3. **`deploy.sh`** - Скрипт ручного деплоя

## Мониторинг

### Проверка статуса:
```bash
cd /var/www/sites/1helper
docker-compose -f docker-compose.prod.yml ps
docker-compose -f docker-compose.prod.yml logs -f
```

### Просмотр логов:
```bash
# Логи приложения
docker-compose -f docker-compose.prod.yml logs manager_php-fpm

# Логи базы данных
docker-compose -f docker-compose.prod.yml logs manager_db

# Логи Nginx
docker-compose -f docker-compose.prod.yml logs manager_nginx
```

## Откат (Rollback)

### Откат к предыдущей версии:
```bash
# Получить список образов
docker images | grep Vanzhin/coating-monolith

# Остановить текущие контейнеры
docker-compose -f docker-compose.prod.yml down

# Запустить с предыдущим образом
export IMAGE_TAG=previous-tag
docker-compose -f docker-compose.prod.yml up -d
```

## Troubleshooting

### Проблемы с базой данных:
```bash
# Проверка подключения
docker-compose -f docker-compose.prod.yml exec manager_db psql -U coating_user -d coating_prod

# Сброс миграций
docker-compose -f docker-compose.prod.yml exec manager_php-cli php bin/console doctrine:migrations:migrate --no-interaction --force
```

### Проблемы с кэшем:
```bash
# Очистка кэша
docker-compose -f docker-compose.prod.yml exec manager_php-cli php bin/console cache:clear --env=prod

# Прогрев кэша
docker-compose -f docker-compose.prod.yml exec manager_php-cli php bin/console cache:warmup --env=prod
```

### Проблемы с правами:
```bash
# Исправление прав
sudo chown -R www-data:www-data /var/www/sites/1helper/app/var
sudo chmod -R 755 /var/www/sites/1helper/app/var
```

## Безопасность

### Рекомендации:
1. Используйте сильные пароли для базы данных
2. Настройте firewall (откройте только порты 80, 443, 22)
3. Регулярно обновляйте Docker образы
4. Используйте HTTPS в продакшене
5. Настройте мониторинг и алерты

### Backup:
```bash
# Backup базы данных
docker-compose -f docker-compose.prod.yml exec manager_db pg_dump -U coating_user coating_prod > backup_$(date +%Y%m%d_%H%M%S).sql

# Backup файлов
tar -czf files_backup_$(date +%Y%m%d_%H%M%S).tar.gz app/public/uploads/
```
