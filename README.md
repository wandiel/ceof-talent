# CEOF TALENT

Sistema profissional de **Banco de Talentos e Gestão de Recrutamento e Seleção**:
candidatos, currículos, vagas, triagem, processos seletivos em Kanban, entrevistas,
avaliações, dashboard, relatórios, usuários e permissões, com conformidade à LGPD.

**Stack:** Laravel 13 (PHP 8.3) · Vue 3 + TypeScript + Vite + Tailwind · PostgreSQL 17 ·
Redis 7.4 · Nginx · tudo em Docker.

## Início rápido (desenvolvimento)

Requisito único: Docker Engine com Docker Compose v2.24+.

```bash
git clone https://github.com/wandiel/ceof-talent.git
cd ceof-talent
cp .env.example .env        # opcional em desenvolvimento
docker compose up -d --build
```

Crie o primeiro administrador (o comando pede nome, e-mail e senha):

```bash
docker compose exec app php artisan ceof:create-admin
```

Abra **http://localhost:8080** (ou a porta definida em `APP_PORT`) e entre com esse usuário.

```bash
docker compose ps                                   # saúde dos serviços
docker compose exec app php artisan test            # testes do backend
./docker/scripts/test-infrastructure.sh             # teste de persistência, backup e restauração
```

## Produção

```bash
cp .env.example .env
./docker/scripts/generate-secrets.sh                # cole os segredos no .env
docker compose -f docker-compose.prod.yml up -d --build
```

Veja HTTPS, atualização, backup e recuperação de desastres em
[docs/02-infraestrutura-docker.md](docs/02-infraestrutura-docker.md).

## Documentação

| Documento | Conteúdo |
|---|---|
| [01 — Planejamento e arquitetura](docs/01-planejamento-arquitetura.md) | decisões técnicas, módulos, modelo de dados, permissões, roadmap |
| [02 — Infraestrutura Docker](docs/02-infraestrutura-docker.md) | serviços, execução, produção, backup/restauração, testes |
| [03 — Autenticação e permissões](docs/03-autenticacao-permissoes.md) | login, senhas, perfis, usuários, estrutura organizacional, API |

## Status

| Fase | Status |
|---|---|
| 1. Planejamento e arquitetura | ✅ |
| 2. Estrutura Docker e infraestrutura | ✅ |
| 3. Banco de dados e autenticação | ✅ |
| 4. Cadastro de candidatos | próxima |
| 5–12 | ver [roadmap](docs/01-planejamento-arquitetura.md#11-roadmap-e-status-das-fases) |
