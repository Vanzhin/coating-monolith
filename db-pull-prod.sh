#!/usr/bin/env bash
#
# Снимает ПОЛНЫЙ дамп продовой БД и раскатывает его на локальную.
# Прод-Postgres наружу не проброшен, поэтому pg_dump гоняется на сервере через
# `docker compose exec`, а поток SQL по SSH льётся сюда и заливается в локальный
# контейнер `manager_db`. На прод только читаем (pg_dump), пишем только в локаль.
#
# Запускать вручную (нужен настроенный SSH-доступ deploy-юзера):
#   ./db-pull-prod.sh                      # снять дамп с прода и раскатать локально
#   ./db-pull-prod.sh --fetch-only         # только снять дамп в файл, не раскатывать
#   ./db-pull-prod.sh --from db-dumps/prod-XXXX.sql   # раскатать из готового файла
#   ./db-pull-prod.sh -y                   # без подтверждения (снос локальной БД)
#
# Переопределяемые переменные окружения:
#   SSH_TARGET   (deploy@1helper.ru)         куда ходить по ssh
#   PROD_DIR     (/var/www/sites/1helper)    каталог с docker-compose.prod.yml на сервере
#   PROD_COMPOSE (docker-compose.prod.yml)   compose-файл прода
#
set -euo pipefail

cd "$(dirname "$0")"

SSH_TARGET="${SSH_TARGET:-deploy@1helper.ru}"
PROD_DIR="${PROD_DIR:-/var/www/sites/1helper}"
PROD_COMPOSE="${PROD_COMPOSE:-docker-compose.prod.yml}"
DB_SERVICE="manager_db"
DUMP_DIR="db-dumps"

FROM_FILE=""
FETCH_ONLY=0
ASSUME_YES=0
DUMP_FILE=""   # fetch_dump кладёт сюда путь к снятому дампу (не через stdout — см. spinner)

while [[ $# -gt 0 ]]; do
  case "$1" in
    --from)       FROM_FILE="${2:?--from требует путь к файлу}"; shift 2 ;;
    --fetch-only) FETCH_ONLY=1; shift ;;
    -y|--yes)     ASSUME_YES=1; shift ;;
    -h|--help)    grep '^#' "$0" | sed 's/^# \{0,1\}//'; exit 0 ;;
    *)            echo "Неизвестный аргумент: $1" >&2; exit 2 ;;
  esac
done

# pg_dump с --clean --if-exists делает раскат идемпотентным (DROP перед CREATE),
# --no-owner/--no-privileges убирают привязку к прод-роли (локальный юзер другой).
PG_DUMP_ARGS='--clean --if-exists --no-owner --no-privileges'

# ── индикатор выполнения ──────────────────────────────────────────────────────
# Крутилка с таймером (mm:ss) и, если передан файл, его текущим размером.
# Работает на голом bash — крутится, пока жив процесс $1.
spinner() {
  local pid="$1" label="$2" watch="${3:-}"
  local frames='|/-\' i=0 start="$SECONDS"
  # tput пишет управляющие коды в stdout — уводим в stderr, где вся UI спиннера,
  # иначе они попадут в stdout и испортят значения, снимаемые через $(...).
  [[ -t 2 ]] && tput civis >&2 2>/dev/null || true
  while kill -0 "$pid" 2>/dev/null; do
    local el=$((SECONDS - start)) sz=""
    [[ -n "$watch" && -f "$watch" ]] && sz="  ($(du -h "$watch" 2>/dev/null | cut -f1))"
    printf '\r  %s %s  %02d:%02d%s   ' "${frames:i++%4:1}" "$label" $((el/60)) $((el%60)) "$sz" >&2
    sleep 0.25
  done
  [[ -t 2 ]] && tput cnorm >&2 2>/dev/null || true
  printf '\r\033[K' >&2
}

HAS_PV=0; command -v pv >/dev/null 2>&1 && HAS_PV=1

fetch_dump() {
  mkdir -p "$DUMP_DIR"
  local out="$DUMP_DIR/prod-$(date +%Y%m%d-%H%M%S).sql"
  echo ">> Снимаю дамп с прода ($SSH_TARGET) ..." >&2

  (
    ssh "$SSH_TARGET" bash -s <<REMOTE
set -euo pipefail
cd "$PROD_DIR"
docker compose -f "$PROD_COMPOSE" exec -T "$DB_SERVICE" \
  sh -c 'pg_dump $PG_DUMP_ARGS -U "\$POSTGRES_USER" -d "\$POSTGRES_DB"'
REMOTE
  ) > "$out" &
  local pid=$!
  spinner "$pid" "дамп с прода" "$out"
  wait "$pid" || { echo "!! ssh/pg_dump упал — раскат отменён. Проверь SSH-доступ и статус прода." >&2; rm -f "$out"; exit 1; }

  if [[ ! -s "$out" ]]; then
    echo "!! Дамп пустой — раскат отменён." >&2; rm -f "$out"; exit 1
  fi
  echo ">> Дамп сохранён: $out ($(du -h "$out" | cut -f1))" >&2
  DUMP_FILE="$out"
}

restore_dump() {
  local file="$1"
  [[ -s "$file" ]] || { echo "!! Файл дампа не найден/пустой: $file" >&2; exit 1; }

  if [[ "$ASSUME_YES" -ne 1 ]]; then
    read -r -p "Локальная БД будет ПЕРЕЗАПИСАНА из $file. Продолжить? [y/N] " ans
    [[ "$ans" =~ ^[yY]$ ]] || { echo "Отменено."; exit 0; }
  fi

  echo ">> Поднимаю локальный $DB_SERVICE ..." >&2
  docker compose up -d "$DB_SERVICE" >/dev/null
  for _ in $(seq 1 30); do
    docker compose exec -T "$DB_SERVICE" sh -c 'pg_isready -U "$POSTGRES_USER" -d "$POSTGRES_DB"' >/dev/null 2>&1 && break
    sleep 1
  done

  # болтовню psql (NOTICE от DROP ... IF EXISTS и т.п.) уводим в лог, на экране — только индикатор
  local log="$DUMP_DIR/restore-$(date +%Y%m%d-%H%M%S).log"
  local psql='psql -q -v ON_ERROR_STOP=0 -U "$POSTGRES_USER" -d "$POSTGRES_DB"'
  echo ">> Раскатываю дамп на локальную БД ..." >&2

  if [[ "$HAS_PV" -eq 1 ]]; then
    # pv знает размер файла → полоса с процентами и ETA
    pv "$file" | docker compose exec -T "$DB_SERVICE" sh -c "$psql" >"$log" 2>&1
  else
    docker compose exec -T "$DB_SERVICE" sh -c "$psql" <"$file" >"$log" 2>&1 &
    local pid=$!
    spinner "$pid" "раскат дампа" ""
    wait "$pid" || true
    echo "   (поставь pv для полосы прогресса: brew install pv)" >&2
  fi

  local errs; errs=$(grep -c '^ERROR' "$log" 2>/dev/null || true)
  if [[ "${errs:-0}" -gt 0 ]]; then
    echo ">> Готово, но с ошибками: $errs (см. $log)" >&2
  else
    echo ">> Готово, ошибок нет." >&2
    rm -f "$log"
  fi
}

if [[ -n "$FROM_FILE" ]]; then
  restore_dump "$FROM_FILE"
else
  fetch_dump   # кладёт путь в глобальный $DUMP_FILE
  if [[ "$FETCH_ONLY" -eq 1 ]]; then
    echo ">> --fetch-only: раскат пропущен." >&2
  else
    restore_dump "$DUMP_FILE"
  fi
fi
