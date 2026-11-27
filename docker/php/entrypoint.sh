#!/usr/bin/env bash
# =============================================================================
# CEOF TALENT — entrypoint da imagem PHP
#
# Uso: ceof-entrypoint <papel> | <comando>
#   app        PHP-FPM (executa migrations quando RUN_MIGRATIONS=true)
#   queue      worker de filas (Redis)
#   scheduler  agendador do Laravel
#   outro      executa o comando informado (ex.: php artisan test)
#
# Este script NUNCA cria usuários ou dados de produção. Seeders de dados de
# referência só rodam quando SEED_REFERENCE_DATA=true.
# =============================================================================
set -euo pipefail

APP_DIR="${APP_DIR:-/var/www/html}"
cd "$APP_DIR"

log() { printf '[ceof-entrypoint] %s\n' "$*" >&2; }

is_production() { [ "${APP_ENV:-production}" = "production" ]; }

ensure_dependencies() {
  # Reinstala quando vendor/ não existe ou quando o composer.lock mudou
  # (ex.: atualização do código que adicionou pacotes).
  local marker=vendor/.composer-lock.sha256
  local current
  current="$(sha256sum composer.lock | cut -d' ' -f1)"

  if [ -f vendor/autoload.php ] && [ -f "$marker" ] && [ "$(cat "$marker")" = "$current" ]; then
    return
  fi

  if command -v composer >/dev/null 2>&1; then
    log "Dependências PHP ausentes ou desatualizadas: executando composer install"
    composer install --no-interaction --prefer-dist --no-progress
    echo "$current" > "$marker"
  elif [ ! -f vendor/autoload.php ]; then
    log "ERRO: vendor/ ausente e Composer indisponível nesta imagem."
    exit 1
  fi
}

ensure_storage_layout() {
  mkdir -p storage/app/private storage/app/public \
           storage/framework/cache/data storage/framework/sessions \
           storage/framework/views storage/framework/testing \
           storage/logs bootstrap/cache
}

ensure_app_key() {
  if [ -n "${APP_KEY:-}" ]; then
    return
  fi

  if is_production; then
    log "ERRO: APP_KEY não definida. Gere os segredos com: ./docker/scripts/generate-secrets.sh"
    exit 1
  fi

  # Desenvolvimento: gera uma chave uma única vez e a mantém no volume de storage,
  # para que sessões e dados criptografados sobrevivam à recriação dos containers.
  local key_file="storage/app/.dev_app_key"
  if [ ! -s "$key_file" ]; then
    log "APP_KEY vazia em desenvolvimento: gerando chave local em $key_file"
    php -r 'echo "base64:".base64_encode(random_bytes(32));' > "$key_file"
    chmod 600 "$key_file"
  fi
  APP_KEY="$(cat "$key_file")"
  export APP_KEY
}

wait_for_database() {
  local timeout="${DB_WAIT_TIMEOUT:-60}"
  log "Aguardando PostgreSQL em ${DB_HOST:-database}:${DB_PORT:-5432} (até ${timeout}s)"
  for _ in $(seq 1 "$timeout"); do
    if php -r '
      try {
        new PDO(
          sprintf("pgsql:host=%s;port=%s;dbname=%s", getenv("DB_HOST") ?: "database", getenv("DB_PORT") ?: "5432", getenv("DB_DATABASE") ?: "ceof_talent"),
          getenv("DB_USERNAME") ?: "", getenv("DB_PASSWORD") ?: "",
          [PDO::ATTR_TIMEOUT => 2]
        );
        exit(0);
      } catch (Throwable $e) { exit(1); }
    ' 2>/dev/null; then
      return 0
    fi
    sleep 1
  done
  log "ERRO: PostgreSQL indisponível após ${timeout}s"
  exit 1
}

prepare_application() {
  if is_production; then
    php artisan optimize --no-interaction
  else
    php artisan optimize:clear --no-interaction >/dev/null
  fi

  if [ "${RUN_MIGRATIONS:-false}" = "true" ]; then
    log "Executando migrations"
    php artisan migrate --force --no-interaction
  fi

  if [ "${SEED_REFERENCE_DATA:-false}" = "true" ] && [ -f database/seeders/ReferenceDataSeeder.php ]; then
    log "Carregando dados de referência (perfis, permissões, catálogos)"
    php artisan db:seed --class='Database\Seeders\ReferenceDataSeeder' --force --no-interaction
  fi
}

role="${1:-app}"
export CONTAINER_ROLE="$role"

case "$role" in
  app|queue|scheduler)
    ensure_storage_layout
    [ "$role" = "app" ] && ensure_dependencies
    ensure_app_key
    wait_for_database
    ;;
esac

case "$role" in
  app)
    prepare_application
    log "Iniciando PHP-FPM"
    exec php-fpm8.3 --nodaemonize --fpm-config /etc/php/8.3/fpm/php-fpm.conf
    ;;
  queue)
    log "Iniciando worker de filas"
    exec php artisan queue:work "${QUEUE_CONNECTION:-redis}" \
      --queue="${QUEUE_NAMES:-default}" \
      --sleep=3 --tries="${QUEUE_TRIES:-3}" --timeout="${QUEUE_TIMEOUT:-120}" \
      --max-time=3600 --no-interaction
    ;;
  scheduler)
    log "Iniciando agendador"
    exec php artisan schedule:work --no-interaction
    ;;
  *)
    is_production || ensure_app_key
    exec "$@"
    ;;
esac
