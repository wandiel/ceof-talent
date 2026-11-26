#!/usr/bin/env bash
# =============================================================================
# CEOF TALENT — teste de infraestrutura (Fase 2)
#
# Valida, de ponta a ponta, com os containers reais:
#   1. todos os serviços sobem saudáveis e a API responde via Nginx
#   2. dados no PostgreSQL e documentos no storage sobrevivem à recriação
#      completa dos containers (down + up, sem remover volumes)
#   3. backup gera arquivos íntegros e a restauração recupera banco e arquivos
#   4. banco e Redis não estão acessíveis fora da rede Docker (produção)
#
# Uso:
#   ./docker/scripts/test-infrastructure.sh            # ambiente de desenvolvimento
#   ./docker/scripts/test-infrastructure.sh prod       # docker-compose.prod.yml
#
# Seguro: nunca executa "down -v". Usa uma tabela e um arquivo de sonda
# próprios (_ceof_probe) e os remove ao final.
# =============================================================================
set -euo pipefail
cd "$(dirname "$0")/../.."

mode="${1:-dev}"
if [ "$mode" = "prod" ]; then
  compose=(docker compose -f docker-compose.prod.yml)
  base_url="https://127.0.0.1:${HTTPS_PORT:-443}"
  curl_opts=(-k)
else
  compose=(docker compose)
  base_url="http://127.0.0.1:${APP_PORT:-8080}"
  curl_opts=()
fi
compose+=(${COMPOSE_EXTRA_ARGS:-})

db_name="${DB_DATABASE:-ceof_talent}"
db_user="${DB_USERNAME:-ceof_talent}"
probe_value="probe-$(date +%s)-$RANDOM"
probe_file="storage/app/private/_ceof_probe.txt"
failures=0

step() { printf '\n\033[1;34m==> %s\033[0m\n' "$*"; }
pass() { printf '   \033[32m✔ %s\033[0m\n' "$*"; }
fail() { printf '   \033[31m✘ %s\033[0m\n' "$*"; failures=$((failures + 1)); }

psql_app() {
  "${compose[@]}" exec -T database psql -v ON_ERROR_STOP=1 -U "$db_user" -d "$db_name" -tAc "$1"
}

http_status() {
  curl -s "${curl_opts[@]}" --noproxy '*' -o /dev/null -w '%{http_code}' "$base_url$1" || true
}

check_health() {
  local body
  body="$(curl -s "${curl_opts[@]}" --noproxy '*' "$base_url/api/health" || true)"
  if echo "$body" | grep -q '"status":"ok"'; then pass "API /api/health: ok"; else fail "API /api/health: $body"; fi
  [ "$(http_status /)" = "200" ] && pass "Frontend responde em /" || fail "Frontend não respondeu em /"
  [ "$(http_status /up)" = "200" ] && pass "Laravel /up responde" || fail "Laravel /up não respondeu"
}

step "1. Subindo o ambiente ($mode) e aguardando healthchecks"
"${compose[@]}" up -d --wait --wait-timeout 300
"${compose[@]}" ps --format 'table {{.Service}}\t{{.Status}}'
check_health

step "2. Gravando sonda de persistência (banco + documento)"
psql_app "create table if not exists _ceof_probe (value text not null, created_at timestamptz default now());
          insert into _ceof_probe (value) values ('$probe_value');" >/dev/null
"${compose[@]}" exec -T app sh -c "printf '%s' '$probe_value' > $probe_file"
pass "Sonda gravada: $probe_value"

step "3. Recriando TODOS os containers (down sem -v + up)"
"${compose[@]}" down
"${compose[@]}" up -d --wait --wait-timeout 300
check_health
[ "$(psql_app "select count(*) from _ceof_probe where value = '$probe_value'")" = "1" ] \
  && pass "Registro no PostgreSQL preservado" || fail "Registro no PostgreSQL PERDIDO"
[ "$("${compose[@]}" exec -T app cat "$probe_file" 2>/dev/null)" = "$probe_value" ] \
  && pass "Documento no storage preservado" || fail "Documento no storage PERDIDO"

step "4. Backup completo e verificação de integridade"
backup_path="$("${compose[@]}" exec -T backup backup --label infra-test | tail -n 1)"
backup_name="$(basename "$backup_path")"
"${compose[@]}" exec -T backup verify-backup "$backup_name" >/dev/null \
  && pass "Backup $backup_name íntegro (checksums, dump e arquivo de documentos)" || fail "Backup inválido"

step "5. Simulando perda de dados e restaurando"
psql_app "delete from _ceof_probe;" >/dev/null
"${compose[@]}" exec -T app rm -f "$probe_file"
"${compose[@]}" stop app queue scheduler nginx >/dev/null
"${compose[@]}" --profile tools run --rm -T restore "$backup_name" --yes
"${compose[@]}" up -d --wait --wait-timeout 300
[ "$(psql_app "select count(*) from _ceof_probe where value = '$probe_value'")" = "1" ] \
  && pass "Banco restaurado a partir do backup" || fail "Banco NÃO restaurado"
[ "$("${compose[@]}" exec -T app cat "$probe_file" 2>/dev/null)" = "$probe_value" ] \
  && pass "Documentos restaurados a partir do backup" || fail "Documentos NÃO restaurados"
check_health

step "6. Isolamento de rede"
# "docker compose port" imprime ":0" quando a porta não está publicada.
published_port() { local p; p="$("${compose[@]}" port "$1" "$2" 2>/dev/null || true)"; [ "$p" = ":0" ] && p=""; printf '%s' "$p"; }
db_ports="$(published_port database 5432)"
redis_ports="$(published_port redis 6379)"
if [ "$mode" = "prod" ]; then
  [ -z "$db_ports" ] && pass "PostgreSQL sem porta publicada" || fail "PostgreSQL exposto em $db_ports"
  [ -z "$redis_ports" ] && pass "Redis sem porta publicada" || fail "Redis exposto em $redis_ports"
  [ "$(curl -s --noproxy '*' -o /dev/null -w '%{http_code}' "http://127.0.0.1:${HTTP_PORT:-80}/api/health")" = "301" ] \
    && pass "HTTP redireciona para HTTPS" || fail "HTTP não redireciona para HTTPS"
else
  case "$db_ports" in 127.0.0.1:*|"") pass "PostgreSQL (dev) limitado a 127.0.0.1: ${db_ports:-não publicado}" ;; *) fail "PostgreSQL exposto em $db_ports" ;; esac
  [ -z "$redis_ports" ] && pass "Redis sem porta publicada" || fail "Redis exposto em $redis_ports"
fi
for svc in app queue scheduler frontend nginx; do
  user="$("${compose[@]}" exec -T "$svc" id -u 2>/dev/null || echo '?')"
  [ "$user" != "0" ] && pass "$svc executa sem root (uid $user)" || fail "$svc executa como root"
done

step "7. Limpeza da sonda"
psql_app "drop table if exists _ceof_probe;" >/dev/null
"${compose[@]}" exec -T app rm -f "$probe_file"
echo "   O backup de teste foi mantido em backups/$backup_name (remova manualmente se desejar)."

echo
if [ "$failures" -eq 0 ]; then
  printf '\033[1;32mInfraestrutura validada: todos os testes passaram.\033[0m\n'
else
  printf '\033[1;31m%d verificação(ões) falharam.\033[0m\n' "$failures"
  exit 1
fi
