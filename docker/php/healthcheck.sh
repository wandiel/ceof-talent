#!/usr/bin/env bash
# Healthcheck dos containers baseados na imagem PHP.
#   app       -> PHP-FPM responde ao ping via FastCGI
#   queue     -> processo queue:work em execução
#   scheduler -> processo schedule:work em execução
set -euo pipefail

# O papel é deduzido do processo principal (PID 1), iniciado pelo entrypoint.
main_process="$(tr '\0' ' ' < /proc/1/cmdline)"
case "$main_process" in
  *queue:work*)    role=queue ;;
  *schedule:work*) role=scheduler ;;
  *php-fpm*)       role=app ;;
  *)               role=unknown ;;
esac

case "$role" in
  app)
    SCRIPT_NAME=/fpm-ping SCRIPT_FILENAME=/fpm-ping REQUEST_METHOD=GET \
      cgi-fcgi -bind -connect 127.0.0.1:9000 | grep -q pong
    ;;
  queue)
    pgrep -f "artisan queue:work" >/dev/null
    ;;
  scheduler)
    pgrep -f "artisan schedule:work" >/dev/null
    ;;
  *)
    echo "Processo principal não reconhecido: $main_process" >&2
    exit 1
    ;;
esac
