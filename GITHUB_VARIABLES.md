# GitHub Variables Configuration

## 📋 Список переменных для настройки в GitHub

Перейдите в настройки репозитория: `Settings` → `Secrets and variables` → `Actions` → `Variables`

### 🔧 Инфраструктурные переменные
| Переменная | Значение | Описание |
|------------|----------|----------|
| `REGISTRY` | `ghcr.io` | Docker registry |
| `IMAGE_NAME` | `vanzhin/coating-monolith` | Имя образа |
| `DOMAIN` | `1helper.ru` | Домен приложения |
| `HOST_NGINX` | `172.20.0.10` | IP адрес Nginx в Docker сети |
| `NETWORK` | `172.20.0.0/16` | Docker сеть |

### 🗄️ База данных
| Переменная | Значение | Описание |
|------------|----------|----------|
| `DB_HOST` | `manager_db` | Хост базы данных |
| `DB_PORT` | `5432` | Порт базы данных |
| `DB_NAME` | `coating` | Имя базы данных |
| `DB_USER` | `postgres` | Пользователь БД |
| `DB_PASSWORD` | `password` | Пароль БД |

### 📧 Email настройки
| Переменная | Значение | Описание |
|------------|----------|----------|
| `APP_NAME` | `Coating Monolith` | Название приложения |
| `DEFAULT_FROM_ADDR` | `noreply@1helper.ru` | Email отправителя |
| `DEFAULT_FROM_NAME` | `1Helper` | Имя отправителя |
| `MAILER_DSN` | `smtp://localhost:1025` | SMTP настройки |

### 🔐 JWT настройки
| Переменная | Значение | Описание |
|------------|----------|----------|
| `JWT_SECRET_KEY` | `%kernel.project_dir%/config/jwt/private.pem` | Приватный ключ JWT |
| `JWT_PUBLIC_KEY` | `%kernel.project_dir%/config/jwt/public.pem` | Публичный ключ JWT |
| `JWT_PASSPHRASE` | `your-jwt-passphrase` | Пароль для JWT ключей |

### 📱 Telegram уведомления
| Переменная | Значение | Описание |
|------------|----------|----------|
| `LOG_TELEGRAM_BOT_KEY` | `` | API ключ Telegram бота |
| `LOG_TELEGRAM_CHANNEL` | `` | ID канала Telegram |

### 🔍 Elasticsearch
| Переменная | Значение | Описание |
|------------|----------|----------|
| `ELASTIC_DSN` | `http://app_elastic:9200` | URL Elasticsearch |
| `ELASTIC_USERNAME` | `` | Пользователь Elasticsearch |
| `ELASTIC_PASSWORD` | `` | Пароль Elasticsearch |

### ⚡ Redis
| Переменная | Значение | Описание |
|------------|----------|----------|
| `REDIS_HOST` | `manager_redis:6379` | Хост Redis |

### 🔑 Приложение
| Переменная | Значение | Описание |
|------------|----------|----------|
| `APP_SECRET` | `your-secret-key-here` | Секретный ключ приложения |

## 🔒 Безопасность

- **Secrets** - для чувствительных данных (пароли, ключи API)
- **Variables** - для публичных настроек (URL, имена сервисов)

## 📝 Примечания

1. Все переменные теперь управляются через GitHub Variables
2. Dockerfile использует переменные окружения вместо хардкода
3. GitHub Actions передает переменные в Docker build context
4. Конфигурация стала более гибкой и безопасной
