#!/usr/bin/env bash
# =============================================================================
# CEOF TALENT — roteiro de homologação (Fase 11)
#
# Executa, em sequência, todas as verificações automáticas do sistema e grava
# um relatório em homologacao/relatorio-AAAAMMDD-HHMMSS.txt:
#
#   1. Ambiente sobe e todos os serviços ficam saudáveis
#   2. Testes automatizados do backend (PHPUnit, inclusive os de segurança)
#   3. Checagem de tipos e build do frontend
#   4. Vulnerabilidades conhecidas nas dependências (composer audit / npm audit)
#   5. Segurança HTTP (cabeçalhos, autenticação, CSRF, arquivos sensíveis)
#   6. Retrato dos dados reais (contagens e arquivos)
#   7. Persistência, backup e restauração (test-infrastructure.sh)
#   8. Dados reais conferidos depois da recriação e da restauração
#   9. (opcional, --e2e) Testes de interface no navegador (Playwright)
#
# Uso:
#   ./docker/scripts/homologacao.sh                 # desenvolvimento
#   ./docker/scripts/homologacao.sh --e2e           # + testes de interface
#   ./docker/scripts/homologacao.sh prod            # produção (sem itens 2, 3 e 9)
#
# Seguro: não apaga dados. O item 7 recria os containers e restaura um backup
# feito segundos antes (os dados voltam ao mesmo ponto). Os testes de interface
# criam registros com o prefixo "E2E": use-os só em desenvolvimento/homologação.
# =============================================================================
set -uo pipefail
cd "$(dirname "$0")/../.."

mode="dev"; run_e2e=0
for arg in "$@"; do
  case "$arg" in
    prod) mode="prod" ;;
    --e2e) run_e2e=1 ;;
    *) echo "Opção desconhecida: $arg"; exit 2 ;;
  esac
done

# Lê APP_PORT, DB_* etc. do .env (sem executar o arquivo).
env_value() { [ -f .env ] && grep -E "^$1=" .env | tail -n1 | cut -d= -f2- | tr -d '"' || true; }
APP_PORT="${APP_PORT:-$(env_value APP_PORT)}"; APP_PORT="${APP_PORT:-8080}"
HTTPS_PORT="${HTTPS_PORT:-$(env_value HTTPS_PORT)}"; HTTPS_PORT="${HTTPS_PORT:-443}"
DB_DATABASE="${DB_DATABASE:-$(env_value DB_DATABASE)}"; DB_DATABASE="${DB_DATABASE:-ceof_talent}"
DB_USERNAME="${DB_USERNAME:-$(env_value DB_USERNAME)}"; DB_USERNAME="${DB_USERNAME:-ceof_talent}"
export APP_PORT HTTPS_PORT DB_DATABASE DB_USERNAME

if [ "$mode" = "prod" ]; then
  compose=(docker compose -f docker-compose.prod.yml)
  base_url="https://127.0.0.1:${HTTPS_PORT}"; curl_opts=(-k)
  origin="https://localhost"
else
  compose=(docker compose)
  base_url="http://127.0.0.1:${APP_PORT}"; curl_opts=()
  origin="http://localhost:${APP_PORT}"
fi

mkdir -p homologacao
report="homologacao/relatorio-$(date '+%Y%m%d-%H%M%S').txt"
# Tela colorida; arquivo do relatório sem códigos de cor.
exec > >(tee >(sed -u 's/\x1b\[[0-9;]*m//g' >> "$report")) 2>&1

failures=0; warnings=0
results=()
step() { printf '\n\033[1;34m==> %s\033[0m\n' "$*"; current="$*"; }
pass() { printf '   \033[32m✔ %s\033[0m\n' "$*"; }
fail() { printf '   \033[31m✘ %s\033[0m\n' "$*"; failures=$((failures + 1)); results+=("FALHOU  | $current | $*"); }
warn() { printf '   \033[33m! %s\033[0m\n' "$*"; warnings=$((warnings + 1)); results+=("ATENÇÃO | $current | $*"); }

psql_app() { "${compose[@]}" exec -T database psql -v ON_ERROR_STOP=1 -U "$DB_USERNAME" -d "$DB_DATABASE" -tAc "$1"; }
http() { curl -s "${curl_opts[@]}" --noproxy '*' "$@"; }

echo "CEOF TALENT — homologação ($mode) em $(date '+%d/%m/%Y %H:%M:%S')"
echo "Commit: $(git rev-parse --short HEAD 2>/dev/null || echo 'n/d') · Relatório: $report"

# ---------------------------------------------------------------------------
step "1. Ambiente"
if "${compose[@]}" up -d --wait --wait-timeout 300 >/dev/null 2>&1; then
  pass "Todos os serviços estão saudáveis"
else
  fail "Algum serviço não ficou saudável"; "${compose[@]}" ps
fi
"${compose[@]}" ps --format 'table {{.Service}}\t{{.Status}}'

# ---------------------------------------------------------------------------
if [ "$mode" = "dev" ]; then
  step "2. Testes automatizados do backend (PHPUnit)"
  out="$("${compose[@]}" exec -T app php artisan test --colors=never 2>&1)"; rc=$?
  echo "$out" | grep -E '^\s+(FAIL|✘|⨯)' | head -20
  summary="$(echo "$out" | grep -E 'Tests:' | tail -n1 | sed 's/^ *//')"
  [ $rc -eq 0 ] && pass "$summary" || fail "${summary:-falha ao executar os testes}"

  step "3. Frontend: checagem de tipos e build"
  if "${compose[@]}" exec -T frontend sh -c "npx vue-tsc -b && npx vite build --outDir /tmp/homolog-dist --emptyOutDir" >/tmp/ceof-front.log 2>&1; then
    pass "TypeScript sem erros e build concluído"
  else
    tail -20 /tmp/ceof-front.log; fail "Erros na checagem de tipos ou no build"
  fi
fi

# ---------------------------------------------------------------------------
step "4. Vulnerabilidades conhecidas nas dependências"
if [ "$mode" = "dev" ]; then
  out="$("${compose[@]}" exec -T app composer audit --no-interaction --locked --format=plain 2>&1)"; rc=$?
else
  # A imagem de produção não inclui o Composer: usa a imagem oficial só para ler o composer.lock.
  out="$(docker run --rm -v "$PWD/backend/composer.json:/app/composer.json:ro" -v "$PWD/backend/composer.lock:/app/composer.lock:ro" \
    -w /app composer:2.9.8 audit --no-interaction --locked --format=plain 2>&1)"; rc=$?
fi
if [ $rc -eq 0 ]; then pass "PHP (composer audit): nenhuma vulnerabilidade conhecida"
elif echo "$out" | grep -qiE 'could not|failed to|curl error|network|resolve'; then warn "composer audit sem acesso à internet: não verificado"
else echo "$out" | head -30; fail "composer audit encontrou vulnerabilidades"; fi
if [ "$mode" = "dev" ]; then
  out="$("${compose[@]}" exec -T frontend npm audit --omit=dev --audit-level=high 2>&1)"; rc=$?
  if [ $rc -eq 0 ]; then pass "Frontend (npm audit, dependências de produção): nada grave"
  elif echo "$out" | grep -qiE 'ENOTFOUND|ECONNREFUSED|ETIMEDOUT|network|certificate|request to'; then warn "npm audit sem acesso à internet: não verificado"
  else echo "$out" | tail -20; fail "npm audit encontrou vulnerabilidades altas ou críticas"; fi
fi

# ---------------------------------------------------------------------------
step "5. Segurança HTTP"
headers="$(http -D - -o /dev/null "$base_url/")"
for h in 'X-Content-Type-Options: nosniff' 'X-Frame-Options' 'Referrer-Policy' 'Permissions-Policy'; do
  echo "$headers" | grep -qi "^$h" && pass "Cabeçalho $h" || fail "Cabeçalho ausente: $h"
done
if [ "$mode" = "prod" ]; then
  for h in 'Strict-Transport-Security' 'Content-Security-Policy'; do
    echo "$headers" | grep -qi "^$h" && pass "Cabeçalho $h" || fail "Cabeçalho ausente: $h"
  done
fi
echo "$headers" | grep -qiE '^Server: .*[0-9]' && fail "Servidor expõe a versão: $(echo "$headers" | grep -i '^Server')" || pass "Servidor não expõe versão"
api_headers="$(http -D - -o /dev/null -H 'Accept: application/json' "$base_url/api/health")"
echo "$api_headers" | grep -qi '^X-Powered-By' && fail "API expõe X-Powered-By" || pass "API não expõe X-Powered-By"

code="$(http -o /dev/null -w '%{http_code}' -H 'Accept: application/json' "$base_url/api/v1/candidates")"
[ "$code" = "401" ] && pass "Dados de candidatos exigem login (401)" || fail "GET /api/v1/candidates sem login respondeu $code"
code="$(http -o /dev/null -w '%{http_code}' -H 'Accept: application/json' "$base_url/api/v1/audit-logs")"
[ "$code" = "401" ] && pass "Auditoria exige login (401)" || fail "GET /api/v1/audit-logs sem login respondeu $code"
code="$(http -o /dev/null -w '%{http_code}' -X POST -H 'Accept: application/json' -H 'Content-Type: application/json' \
  -H "Origin: $origin" -H "Referer: $origin/" -d '{"email":"x@ceof.test","password":"x"}' "$base_url/api/v1/auth/login")"
[ "$code" = "419" ] && pass "Proteção CSRF ativa (login sem token: 419)" || fail "Login sem token CSRF respondeu $code (esperado 419)"

for path in /.env /backend/.env /.git/config /storage/logs/laravel.log /vendor/composer/installed.json /composer.json /docker-compose.yml /storage/app/private/candidates; do
  body="$(http "$base_url$path" | head -c 4000)"
  if echo "$body" | grep -qE 'APP_KEY|DB_PASSWORD|\[core\]|local\.ERROR|"packages"|"require"|services:'; then
    fail "Arquivo interno acessível pela web: $path"
  fi
done
pass "Arquivos internos (.env, .git, logs, vendor, documentos) não são servidos pela web"

if [ "$mode" = "dev" ]; then
  prod_debug="$(grep -E '^\s+APP_DEBUG:' docker-compose.prod.yml | head -1)"
  echo "$prod_debug" | grep -q 'false' && pass "Produção com APP_DEBUG=false" || warn "Confira APP_DEBUG em docker-compose.prod.yml: $prod_debug"
fi

# ---------------------------------------------------------------------------
snapshot() {
  psql_app "select 'candidatos='||(select count(*) from candidates)
    ||' documentos='||(select count(*) from candidate_documents)
    ||' vagas='||(select count(*) from job_openings)
    ||' inscricoes='||(select count(*) from applications)
    ||' entrevistas='||(select count(*) from interviews)
    ||' usuarios='||(select count(*) from users)
    ||' consentimentos='||(select count(*) from candidate_consents)
    ||' solicitacoes_lgpd='||(select count(*) from privacy_requests)
    ||' doc_hash='||coalesce((select md5(string_agg(path||':'||sha256, ',' order by id)) from candidate_documents),'-')"
  printf ' arquivos=%s' "$("${compose[@]}" exec -T app sh -c 'find storage/app/private -type f ! -path "*/.health/*" ! -name "_ceof_probe.txt" | wc -l' | tr -d ' \r')"
}

step "6. Retrato dos dados reais antes dos testes de persistência"
before="$(snapshot)"
echo "   $before" | tr ' ' '\n' | sed 's/^/   /' | grep -v '^ *$'
pass "Retrato registrado"

# ---------------------------------------------------------------------------
step "7. Persistência, backup e restauração"
infra_args=(); [ "$mode" = "prod" ] && infra_args=(prod)
if ./docker/scripts/test-infrastructure.sh "${infra_args[@]}"; then
  pass "Recriação dos containers, backup íntegro e restauração aprovados"
else
  fail "O teste de infraestrutura apontou falhas (veja acima)"
fi
current="7. Persistência, backup e restauração"

step "8. Dados reais conferidos depois da recriação e da restauração"
after="$(snapshot)"
if [ "$before" = "$after" ]; then
  pass "Mesmos registros e mesmos arquivos (candidatos, documentos, vagas, inscrições, entrevistas, usuários, LGPD)"
else
  echo "   antes:  $before"; echo "   depois: $after"
  fail "Os dados mudaram após recriação/restauração"
fi
missing="$("${compose[@]}" exec -T app php -r '
require "vendor/autoload.php"; $app = require "bootstrap/app.php"; $app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
$disk = Illuminate\Support\Facades\Storage::disk(App\Models\CandidateDocument::DISK); $m = 0;
foreach (App\Models\CandidateDocument::query()->cursor() as $d) { if (! $disk->exists($d->path) || hash_file("sha256", $disk->path($d->path)) !== trim($d->sha256)) $m++; } echo $m;' 2>/dev/null | tr -d '\r')"
[ "$missing" = "0" ] && pass "Todos os documentos existem no disco e o conteúdo confere (SHA-256)" || fail "Documentos ausentes ou alterados no disco: ${missing:-erro ao verificar}"

# ---------------------------------------------------------------------------
if [ "$run_e2e" = "1" ] && [ "$mode" = "dev" ]; then
  step "9. Testes de interface no navegador (Playwright)"
  if [ -z "${E2E_PASSWORD:-}" ]; then
    warn "Defina E2E_EMAIL e E2E_PASSWORD (um usuário Administrador) para rodar os testes de interface"
  elif "${compose[@]}" --profile tests run --rm e2e; then
    pass "Testes de interface aprovados (relatório em e2e/resultados/relatorio/index.html)"
  else
    fail "Testes de interface falharam (detalhes em e2e/resultados/)"
  fi
fi

# ---------------------------------------------------------------------------
echo
echo "==================================================================="
if [ "$failures" -eq 0 ]; then
  printf '\033[1;32mHOMOLOGAÇÃO APROVADA\033[0m'; [ "$warnings" -gt 0 ] && printf ' com %d aviso(s)' "$warnings"; echo
else
  printf '\033[1;31mHOMOLOGAÇÃO REPROVADA: %d falha(s), %d aviso(s)\033[0m\n' "$failures" "$warnings"
fi
for r in "${results[@]}"; do echo "  $r"; done
echo "Relatório salvo em $report"
[ "$failures" -eq 0 ]
