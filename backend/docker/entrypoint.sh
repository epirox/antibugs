#!/bin/sh
set -e

# Espera a que MySQL y Redis acepten conexiones antes de arrancar (orquestación).
wait_for() {
  host="$1"; port="$2"; name="$3"
  echo "⏳ Esperando a $name ($host:$port)..."
  for i in $(seq 1 60); do
    if nc -z "$host" "$port" 2>/dev/null; then
      echo "✅ $name disponible"
      return 0
    fi
    sleep 1
  done
  echo "❌ $name no respondió a tiempo" >&2
  exit 1
}

[ -n "$DB_HOST" ] && wait_for "$DB_HOST" "${DB_PORT:-3306}" "MySQL"
[ -n "$REDIS_HOST" ] && wait_for "$REDIS_HOST" "${REDIS_PORT:-6379}" "Redis"

if [ -z "$APP_KEY" ]; then
  echo "❌ APP_KEY no está definida. Copiá .env.example a .env en la raíz del proyecto." >&2
  exit 1
fi

# Sólo el contenedor "principal" ejecuta migraciones; los workers las omiten.
if [ "${RUN_MIGRATIONS:-true}" = "true" ]; then
  php artisan migrate --force --no-interaction

  if [ "${SEED_DATABASE:-false}" = "true" ]; then
    php artisan db:seed --force --no-interaction
  fi
fi

php artisan config:cache
php artisan route:cache
php artisan event:cache
php artisan storage:link --force >/dev/null 2>&1 || true

exec "$@"
