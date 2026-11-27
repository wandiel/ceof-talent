#!/usr/bin/env bash
# =============================================================================
# CEOF TALENT — atualização segura para uma nova versão (Fase 12)
#
# 1. confere se o sistema está saudável
# 2. faz um backup verificado com o rótulo "pre-update"
# 3. baixa a nova versão (git fetch + checkout da tag/branch informada)
# 4. reconstrói as imagens e recria os containers (as migrations rodam na subida)
# 5. espera os healthchecks e confere /api/health
#
# Uso:
#   ./docker/scripts/atualizar.sh v1.1.0            # produção, versão (tag) v1.1.0
#   ./docker/scripts/atualizar.sh main dev          # desenvolvimento
#   ./docker/scripts/atualizar.sh --sem-git prod    # só backup + rebuild (código já atualizado)
#   --sem-build: não reconstrói as imagens (já construídas ou baixadas de um registro)
#
# Nunca remove volumes. Se algo falhar, o script para e mostra como voltar.
# =============================================================================
set -euo pipefail
cd "$(dirname "$0")/../.."

ref=""; mode="prod"; use_git=1; do_build=1
for arg in "$@"; do
  case "$arg" in
    prod|dev) mode="$arg" ;;
    --sem-git) use_git=0 ;;
    --sem-build) do_build=0 ;;
    -*) echo "Opção desconhecida: $arg"; exit 2 ;;
    *) ref="$arg" ;;
  esac
done
[ "$use_git" = 1 ] && [ -z "$ref" ] && { echo "Informe a versão: ./docker/scripts/atualizar.sh <tag|branch> [prod|dev]"; exit 2; }

if [ "$mode" = "prod" ]; then compose=(docker compose -f docker-compose.prod.yml); else compose=(docker compose); fi
step() { printf '\n\033[1;34m==> %s\033[0m\n' "$*"; }
die() { printf '\n\033[1;31mERRO: %s\033[0m\n' "$*"; exit 1; }

health() {
  "${compose[@]}" exec -T app php artisan tinker --execute 'echo json_encode(app(App\Support\Health\HealthChecker::class)->run());' 2>/dev/null | grep -q '"status":"ok"'
}

step "1. Situação atual"
previous="$(git rev-parse --short HEAD 2>/dev/null || echo 'n/d')"
echo "   Versão em execução: $previous ($(git describe --tags --always 2>/dev/null || echo 'sem tag'))"
"${compose[@]}" ps --format 'table {{.Service}}\t{{.Status}}'

step "2. Backup antes da atualização"
backup_path="$("${compose[@]}" exec -T backup backup --label pre-update | tail -n 1)" || die "Backup falhou. Nada foi alterado."
backup_name="$(basename "$backup_path")"
echo "   Backup criado e verificado: $backup_name"

if [ "$use_git" = 1 ]; then
  step "3. Baixando a versão $ref"
  [ -z "$(git status --porcelain --untracked-files=no)" ] || die "Há alterações locais em arquivos do projeto. Resolva antes de atualizar (git status)."
  git fetch --tags origin || die "Não foi possível baixar do repositório."
  git checkout -q "$ref" || die "Versão $ref não encontrada."
  git rev-parse --abbrev-ref HEAD | grep -qv '^HEAD$' && git pull -q --ff-only origin "$ref" || true
  echo "   Código agora em: $(git rev-parse --short HEAD) ($(git describe --tags --always))"
else
  step "3. Código já atualizado (--sem-git)"
fi

step "4. Reconstruindo imagens e recriando containers"
[ "$do_build" = 0 ] || "${compose[@]}" build || die "Falha ao construir as imagens (verifique a internet). Os containers em execução não foram alterados.
Para voltar o código: git checkout $previous"
"${compose[@]}" up -d --wait --wait-timeout 600 || {
  "${compose[@]}" ps
  die "Os serviços não ficaram saudáveis. Veja os logs: ${compose[*]} logs --tail=100 app
Para voltar: git checkout $previous && ${compose[*]} up -d --build
e, se o banco tiver sido alterado: ${compose[*]} stop app queue scheduler nginx && ${compose[*]} run --rm restore $backup_name --yes && ${compose[*]} up -d"
}

step "5. Conferência"
"${compose[@]}" ps --format 'table {{.Service}}\t{{.Status}}'
if health; then
  printf '\n\033[1;32mAtualização concluída. Backup de segurança: %s\033[0m\n' "$backup_name"
else
  die "Sistema no ar, mas /api/health não está ok. Verifique: ${compose[*]} exec app php artisan about"
fi
