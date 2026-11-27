# CEOF TALENT — Fase 4: Cadastro de candidatos

## 1. O que foi entregue

- Cadastro completo do candidato: dados pessoais, contatos, localização, perfil profissional
  (área, escolaridade, cargo pretendido, pretensão salarial, disponibilidade, turnos), formação,
  experiências, cursos e certificações, competências e origem do cadastro.
- Anexos privados (currículo, certificados, registro profissional) com validação do conteúdo real.
- Anotações internas do RH (não editáveis depois de registradas).
- Histórico do candidato a partir da trilha de auditoria: criação, alterações (com a lista do que
  mudou), situação, anexos, downloads, anotações e, opcionalmente, visualizações da ficha.
- Telas: lista com busca e filtros, formulário em seções, ficha com abas Perfil, Documentos,
  Anotações e Histórico. Layout responsivo.

## 2. Regras de negócio

| Regra | Detalhe |
|---|---|
| Campos obrigatórios | nome completo, área profissional, escolaridade, origem e **ao menos um contato** (e-mail ou telefone) |
| Duplicidade | e-mail e CPF são únicos entre candidatos não excluídos; a mensagem informa o motivo |
| CPF | opcional, dígitos verificadores validados, gravado **criptografado**; um HMAC com a `APP_KEY` permite achar duplicados sem guardar o CPF em claro |
| Idade mínima | 14 anos (jovem aprendiz) |
| Experiência | entrada obrigatória; saída obrigatória, salvo "Emprego atual"; datas não podem estar no futuro |
| Competências | catálogo compartilhado; "excel" e "Excel" são a mesma competência |
| Situação | Ativo ou Inativo (inativar mantém dados e histórico) |
| Exclusão | só perfis com `candidates.delete` (Administrador e Gestor de RH); exclusão lógica, registrada na auditoria. A anonimização definitiva está na Fase 10 (LGPD): após 30 dias o cadastro excluído é anonimizado |
| Auditoria | o CPF nunca aparece na trilha; o acesso à ficha é registrado (`candidate.viewed`) |

> **Atenção à `APP_KEY`**: o CPF é criptografado com ela. Guarde a chave de produção junto aos
> backups; sem ela os CPFs não podem ser lidos.

## 3. Anexos

| Item | Regra |
|---|---|
| Formatos | PDF, DOC, DOCX, ODT, JPG, PNG |
| Tamanho | até 10 MB por arquivo; até 20 arquivos por candidato |
| Conteúdo | o tipo real (via `fileinfo`) precisa corresponder à extensão |
| PDF | bloqueado se tiver JavaScript, ação de execução ou arquivo embutido |
| DOCX | precisa ser um pacote Word válido; documentos com macro são bloqueados |
| Repetição | o mesmo arquivo (SHA-256) não pode ser anexado duas vezes ao mesmo candidato |
| Armazenamento | `storage/app/private/candidates/<uuid>/<nome aleatório>`; nunca em pasta pública; incluído no backup |
| Download | só por rota autenticada, com permissão `candidates.download_documents`, `Content-Disposition: attachment` e `nosniff`; cada download é auditado |
| Limite de envio | 30 uploads por minuto por usuário |

## 4. Permissões

| Perfil | Ver | Cadastrar/editar, anexar, anotar | Baixar anexos | Excluir |
|---|:-:|:-:|:-:|:-:|
| Administrador | ✔ | ✔ | ✔ | ✔ |
| Gestor de RH | ✔ | ✔ | ✔ | ✔ |
| Analista de RH | ✔ | ✔ | ✔ | — |
| Recrutador | ✔ | ✔ | ✔ | — |
| Gestor de departamento | candidatos das vagas do seu departamento¹ | — | idem¹ | — |
| Entrevistador | — | — | — | — |

¹ Depende das inscrições em vagas (Fase 6). Até lá a lista desse perfil fica vazia.

## 5. API (`/api/v1`)

| Método e rota | Descrição |
|---|---|
| `GET /candidates` | lista paginada. Filtros: `search` (nome, e-mail, telefone, cargo), `status`, `professional_area`, `education_level`, `source`, `availability`, `state`, `city`; `sort` (`full_name`, `city`, `created_at`, `updated_at`, com `-` para decrescente); `per_page` |
| `GET /candidates/options` | listas de opções (áreas, escolaridade, turnos, tipos de documento etc.) |
| `POST /candidates` | cria (cadastro completo, com listas de `educations`, `experiences`, `courses`, `skills`) |
| `GET /candidates/{uuid}` | ficha completa (registra a visualização) |
| `PUT /candidates/{uuid}` | atualiza; as listas enviadas substituem as anteriores |
| `PATCH /candidates/{uuid}/status` | `{"status": "active" \| "inactive"}` |
| `DELETE /candidates/{uuid}` | exclusão lógica |
| `GET /candidates/{uuid}/history` | histórico paginado; `include_views=1` inclui visualizações |
| `POST /candidates/{uuid}/notes` | `{"body": "..."}` |
| `POST /candidates/{uuid}/documents` | multipart: `type`, `file` |
| `GET /candidates/{uuid}/documents/{uuid}` | download |
| `DELETE /candidates/{uuid}/documents/{uuid}` | remove o arquivo |
| `GET /skills?search=` | sugestões de competências |

Experiências usam mês no formato `AAAA-MM`. Telefones, CEP e CPF podem vir com máscara; a API
guarda só os dígitos.

## 6. Testes

```bash
docker compose exec app php artisan test
```

84 testes no total (28 novos nesta fase), cobrindo cadastro completo, validações em português,
duplicidade de e-mail e CPF, criptografia do CPF, histórico de alterações, filtros, permissões por
perfil, e anexos: upload, download, remoção, arquivo falso renomeado para .pdf, PDF com script,
DOCX com macro, limite de tamanho e arquivo repetido. O fluxo de tela foi validado no navegador,
incluindo celular.
