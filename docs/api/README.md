# API do CEOF TALENT (OpenAPI)

`openapi.json` descreve todas as rotas da API (OpenAPI 3.1): parâmetros, regras de validação,
formatos de resposta e códigos de erro. É gerado a partir do próprio código, então acompanha o
que o sistema realmente faz.

## Como visualizar

- **Swagger Editor**: abra https://editor.swagger.io → *File → Import file* → escolha
  `openapi.json`. (O arquivo não contém senhas nem dados.)
- **VS Code**: extensão "OpenAPI (Swagger) Editor".

## Autenticação

A interface usa sessão por cookie (Laravel Sanctum, mesma origem):

1. `GET /sanctum/csrf-cookie` grava o cookie `XSRF-TOKEN`.
2. `POST /api/v1/auth/login` com `{ "email", "password" }`, enviando o valor do cookie no
   cabeçalho `X-XSRF-TOKEN` e `Accept: application/json`.
3. As demais chamadas seguem com o cookie de sessão e o cabeçalho `X-XSRF-TOKEN`.

Rotas em `/api/v1/public/*` (portal Trabalhe conosco) e `GET /api/health` não exigem login.
Registros são identificados por UUID. Erros de validação respondem **422** com mensagens em
português; sem login **401**; sem permissão **403**; excesso de requisições **429**.

## Como atualizar o arquivo

Depois de mudar rotas ou validações:

```
docker compose exec app php artisan scramble:export --path=storage/app/openapi.json
```

e mova `backend/storage/app/openapi.json` para `docs/api/openapi.json`.
