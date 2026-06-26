# План тестирования деплоя

## Этап 1: Подготовка (перед тестированием)

### 1.1 Проверка GitHub Variables
Убедитесь, что все переменные настроены в **Settings → Secrets and variables → Actions → Variables**:

**Обязательные переменные:**
- `REGISTRY` = `ghcr.io`
- `IMAGE_NAME` = `vanzhin/coating-monolith`
- `DOMAIN` = `1helper.ru`
- `DB_HOST` = `manager_db`
- `DB_PORT` = `5432`
- `DB_NAME` = `coating`
- `DB_USER` = `coating`
- `DB_EXTERNAL_PORT` = `5432`
- `INSTALL_XDEBUG` = `false`
- `REDIS_HOST` = `manager_redis`
- `APP_NAME` = (ваше значение)
- `DEFAULT_FROM_ADDR` = (ваше значение)
- `DEFAULT_FROM_NAME` = (ваше значение)
- `MAILER_DSN` = (ваше значение)
- `JWT_SECRET_KEY` = (ваше значение)
- `JWT_PUBLIC_KEY` = (ваше значение)
- `LOG_TELEGRAM_CHANNEL` = (ваше значение)

### 1.2 Проверка GitHub Secrets
Убедитесь, что все секреты настроены в **Settings → Secrets and variables → Actions → Secrets**:

**Обязательные секреты:**
- `SSH_HOST` - хост сервера (например, `vm-3de9a8a3.netangels.ru`)
- `SSH_USER` - пользователь SSH (например, `deploy`)
- `SSH_KEY` - приватный SSH-ключ для подключения к серверу
- `DB_PASSWORD` - пароль базы данных
- `APP_SECRET` - секретный ключ Symfony
- `JWT_PASSPHRASE` - парольная фраза для JWT
- `LOG_TELEGRAM_BOT_KEY` - ключ Telegram бота

### 1.3 Проверка .env.example файлов
Убедитесь, что существуют:
- `.env.example` в корне (для docker-compose)
- `app/.env.example` в папке app (для Symfony)

### 1.4 Проверка SSH-подключения
Протестируйте SSH-подключение к серверу:
```bash
ssh -i ~/.ssh/your_key root@your-server
```

## Этап 2: Тестирование build.yml (сборка образов)

### 2.1 Тест на push в develop/main
1. Создайте коммит в ветке `develop` или `main`
2. Запушьте изменения: `git push origin develop`
3. Перейдите в **GitHub → Actions**
4. Проверьте, что workflow `Build and Push Docker Images` запустился
5. Проверьте, что все 3 образа собрались успешно:
   - `php-fpm`
   - `php-cli`
   - `supervisor`
6. Проверьте в **GitHub → Packages**, что образы опубликованы

### 2.2 Тест на создание тега
1. Создайте тег: `git tag v1.0.0-test`
2. Запушьте тег: `git push origin v1.0.0-test`
3. Проверьте, что workflow запустился для тега
4. Проверьте теги образов в **GitHub → Packages**

## Этап 3: Тестирование deploy.yml (деплой)

### 3.1 Подготовка на сервере
Перед деплоем проверьте на сервере:
```bash
cd /var/www/sites/1helper

# Проверьте, что директория существует
pwd

# Проверьте, что docker-compose доступен
docker compose version

# Проверьте, что папки для данных существуют
ls -la docker/db/data/
```

### 3.2 Первый тестовый деплой
1. Создайте тег для тестирования: `git tag v1.0.0-test-deploy`
2. Запушьте тег: `git push origin v1.0.0-test-deploy`
3. Перейдите в **GitHub → Actions**
4. Проверьте, что:
   - `Build and Push Docker Images` успешно завершился
   - `Deploy to Production` запустился
   - Все шаги деплоя выполнились без ошибок

### 3.3 Проверка на сервере после деплоя
Подключитесь к серверу и проверьте:
```bash
cd /var/www/sites/1helper

# Проверьте .env файлы
ls -la .env app/.env

# Проверьте содержимое .env (docker-compose)
cat .env | grep -E "^REGISTRY|^IMAGE_NAME|^TAG"

# Проверьте содержимое app/.env (Symfony)
cat app/.env | grep -E "^APP_ENV|^APP_SECRET|^DATABASE_URL"

# Проверьте статус контейнеров
docker compose -f docker-compose.prod.yml ps

# Проверьте логи
docker compose -f docker-compose.prod.yml logs --tail=50 manager_php-fpm
docker compose -f docker-compose.prod.yml logs --tail=50 caddy

# Проверьте, что сайт работает
curl -I http://1helper.ru/
```

### 3.4 Проверка работоспособности
1. Откройте сайт в браузере: `http://1helper.ru/`
2. Проверьте, что страница загружается без ошибок
3. Проверьте, что фронтенд-ассеты загружаются (DevTools → Network)
4. Проверьте, что API работает (если есть)

## Этап 4: Откат (rollback)

Если что-то пошло не так:

### 4.1 Откат к предыдущему тегу
1. Найдите предыдущий рабочий тег
2. Вручную на сервере:
```bash
cd /var/www/sites/1helper

# Обновите TAG в .env
sed -i 's/TAG=.*/TAG=v1.0.0-previous/' .env

# Перезапустите контейнеры
docker compose -f docker-compose.prod.yml pull
docker compose -f docker-compose.prod.yml up -d
```

## Этап 5: Production деплой

После успешного тестирования:

1. Создайте production тег: `git tag v1.0.0`
2. Запушьте тег: `git push origin v1.0.0`
3. Мониторьте деплой в **GitHub → Actions**
4. Проверьте сайт после деплоя

## Частые проблемы

### Ошибка: "The SSH_PRIVATE_KEY variable is not set"
→ Проверьте, что секрет `SSH_PRIVATE_KEY` настроен в GitHub Secrets

### Ошибка: "Cannot connect to server"
→ Проверьте SSH-подключение вручную и что SSH-ключ правильный

### Ошибка: "docker context create failed"
→ Проверьте, что на сервере установлен Docker и SSH доступен

### Ошибка: "Cannot pull images"
→ Проверьте, что GitHub Container Registry доступен и образы опубликованы

### Ошибка: "Container failed to start"
→ Проверьте логи контейнера: `docker compose -f docker-compose.prod.yml logs <service_name>`

