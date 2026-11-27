# CEOF TALENT — Fase 3: Banco de dados, autenticação e permissões

## 1. O que foi entregue

- Login por e-mail e senha com **Laravel Sanctum** em modo SPA (cookie de sessão + CSRF), na
  mesma origem do frontend. Nenhum token fica no `localStorage`.
- Perfis e permissões granulares com `spatie/laravel-permission`, definidos em código
  (`App\Domain\Identity\Enums\Role` e `Permission`) e sincronizados pelo `ReferenceDataSeeder`.
- Gestão de usuários: cadastro, edição, ativação/inativação, definição de senha temporária.
- Estrutura organizacional (adiantada da Fase 7.7): unidades, departamentos e cargos.
- Recuperação de senha por e-mail, troca obrigatória de senha no primeiro acesso.
- Trilha de auditoria (`audit_logs`, somente inserção) para login, logout, falhas de login,
  trocas de senha e alterações de cadastro.
- Layout autenticado responsivo (menu lateral filtrado por permissão, menu móvel).

## 2. Primeiro administrador

Nenhum usuário é criado automaticamente. Com os containers rodando:

```bash
docker compose exec app php artisan ceof:create-admin
# ou sem perguntas:
docker compose exec app php artisan ceof:create-admin --name="Fulano" --email=fulano@empresa.com.br --password='Senha@Forte2026'
```

O administrador criado por comando **não** precisa trocar a senha no primeiro acesso. Usuários
criados pela tela recebem uma senha temporária e são obrigados a trocá-la ao entrar.

## 3. Regras de segurança

| Regra | Valor |
|---|---|
| Senha | mínimo 10 caracteres, maiúsculas e minúsculas, números e símbolos |
| Tentativas de login | 5 por e-mail + IP; bloqueio de 5 minutos |
| Limite das rotas de autenticação | 20 requisições/minuto por IP (também limitado no Nginx em produção) |
| Usuário inativo | não entra; sessões existentes são encerradas na próxima requisição |
| Sessão | expira após `SESSION_LIFETIME` minutos (padrão 120) |
| Administradores | só um administrador concede ou remove o perfil de administrador; ninguém remove o próprio; sempre resta ao menos um administrador ativo |
| Auditoria | registros não podem ser alterados nem apagados pela aplicação; senhas nunca são gravadas |

## 4. Perfis

| Perfil | Uso |
|---|---|
| Administrador | acesso total, inclusive usuários e perfis |
| Gestor de RH | gestão completa do recrutamento e da estrutura organizacional |
| Analista de RH | operação do recrutamento |
| Recrutador | triagem e condução dos processos |
| Gestor de departamento | acompanha vagas e candidatos do próprio departamento (departamento obrigatório) |
| Entrevistador | acesso apenas às entrevistas atribuídas |

A matriz completa de permissões está na tela **Perfis** do sistema e em
`backend/app/Domain/Identity/Enums/Role.php`. As permissões dos módulos futuros (candidatos,
vagas etc.) já estão cadastradas e serão aplicadas conforme cada fase for entregue.

## 5. API (`/api/v1`)

Todas as rotas esperam o cookie de sessão. Antes de `POST`, `PUT`, `PATCH` e `DELETE`, o SPA chama
`GET /sanctum/csrf-cookie` e envia o cabeçalho `X-XSRF-TOKEN`.

| Método e rota | Descrição | Permissão |
|---|---|---|
| `POST /auth/login` | entrar (`email`, `password`, `remember`) | pública |
| `POST /auth/forgot-password` | envia link de redefinição (resposta sempre igual) | pública |
| `POST /auth/reset-password` | redefine com `token`, `email`, `password`, `password_confirmation` | pública |
| `GET /auth/me` | usuário atual com perfis e permissões | autenticado |
| `POST /auth/logout` | sair | autenticado |
| `PUT /auth/password` | trocar a própria senha (`current_password`, `password`, `password_confirmation`) | autenticado |
| `GET /roles` | perfis e permissões | `roles.view` |
| `GET/POST /users`, `GET/PUT /users/{uuid}` | listar, criar, ver, editar | `users.view` / `users.manage` |
| `PATCH /users/{uuid}/status` | ativar/inativar (`is_active`) | `users.manage` |
| `PUT /users/{uuid}/password` | definir senha temporária | `users.manage` |
| `GET/POST /units`, `GET/PUT/DELETE /units/{uuid}` | unidades | `organization.view` / `organization.manage` |
| `GET/POST /departments`, `GET/PUT/DELETE /departments/{uuid}` | departamentos | `organization.view` / `organization.manage` |
| `GET/POST /positions`, `GET/PUT/DELETE /positions/{uuid}` | cargos | `organization.view` / `organization.manage` |

Listagens aceitam `search`, `status` (`active`/`inactive`), `sort` (`campo` ou `-campo`),
`per_page` (máx. 100) e filtros específicos (`role`, `department_id`, `unit_id`). Registros
são identificados por UUID público; IDs internos não saem na API. Excluir um registro com
dependentes retorna `409` com mensagem explicando o motivo.

Respostas de erro: `401` sem sessão, `403` sem permissão (ou `password_change_required`
quando a troca de senha é obrigatória), `419` CSRF expirado, `422` validação com mensagens em
português, `429` excesso de tentativas.

## 6. Testes

```bash
docker compose exec app php artisan test
```

56 testes cobrem login, bloqueio por tentativas, usuário inativo, troca e recuperação de senha,
regras de administrador, matriz de permissões (cada perfil contra cada rota), estrutura
organizacional e o comando `ceof:create-admin`. O fluxo de tela foi validado em navegador
(login, cadastro de usuário, troca obrigatória de senha, bloqueio de páginas sem permissão e
layout móvel).
