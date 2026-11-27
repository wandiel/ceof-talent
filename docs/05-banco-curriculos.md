# CEOF TALENT — Fase 5: Banco de currículos

## 1. O que foi entregue

- **Banco de talentos** (menu Recrutamento): busca rápida, busca avançada com filtros combinados,
  ordenação por relevância, nome, data ou experiência, e filtros guardados na URL (dá para
  recarregar, voltar ou enviar o link da busca a um colega).
- **Favoritos** pessoais (estrela), com a visão "Meus favoritos".
- **Etiquetas** compartilhadas pela equipe, com cor, tela própria de gestão e filtro.
- **Duplicidades**: aviso no formulário enquanto o cadastro é digitado, alerta na ficha e tela
  com todos os pares suspeitos para revisão.
- **Histórico**: alterações de etiquetas e duplicidades descartadas entram no histórico do candidato.

## 2. Busca

| Filtro | Como funciona |
|---|---|
| Busca rápida | parte do nome, e-mail, cargo pretendido ou dígitos do telefone |
| Palavras-chave | texto completo do cadastro, sem acentos e com plural/flexões ("enfermeiras" encontra "enfermeira"). Aspas para frase exata, hífen para excluir: `"centro cirúrgico" -estágio` |
| Competências | todas as informadas precisam estar no cadastro |
| Já trabalhou como / Empresa | busca nos cargos e empresas das experiências |
| Experiência mínima | soma dos períodos de experiência, sem contar duas vezes empregos simultâneos; emprego atual conta até hoje |
| Escolaridade mínima | o nível escolhido ou superior |
| Curso de formação | busca no nome do curso |
| Demais filtros | área, origem, cidade (sem acento), UF, disponibilidade, turno, pretensão até um valor (inclui quem não informou), faixa de idade, período de cadastro, com currículo anexado, aceita mudar de cidade, etiquetas (qualquer uma), meus favoritos |

Pesos da relevância: nome e cargo pretendido > competências, cargos ocupados e etiquetas >
resumo, empresas, atividades, formação e cursos > cidade e bairro.

O índice de busca (`candidates.search_vector`, PostgreSQL `tsvector` + `unaccent`) é atualizado
a cada alteração do cadastro ou das etiquetas.

## 3. Duplicidades

Um par é considerado suspeito quando há **qualquer** destes indícios:

| Indício | Observação |
|---|---|
| Mesmo CPF ou e-mail | só aparece no aviso do formulário; o sistema já impede salvar |
| Mesmo telefone | principal ou secundário, em qualquer combinação |
| Nome muito parecido | similaridade de 80% ou mais (`pg_trgm`) |
| Nome parecido + mesma data de nascimento | similaridade de 55% ou mais |

Um nome parecido sozinho não gera alerta, para evitar falsos positivos com nomes comuns.

Quando o RH confirma que são pessoas diferentes (**Não é a mesma pessoa**), o par deixa de
aparecer. Quando é a mesma pessoa, o procedimento é completar o cadastro mais atual e inativar
ou excluir o outro. A fusão automática de cadastros não foi implementada de propósito: ela
mistura documentos e históricos e precisa de decisão humana.

## 4. Permissões

| Ação | Quem pode |
|---|---|
| Pesquisar | quem vê candidatos |
| Favoritar e etiquetar candidatos; criar, editar e excluir etiquetas | `talent_pool.manage`: Administrador, Gestor de RH, Analista de RH, Recrutador |
| Ver duplicidades | `candidates.view` (quem vê o banco inteiro) |
| Marcar "não é a mesma pessoa" | `candidates.manage` |

## 5. API (`/api/v1`)

| Método e rota | Descrição |
|---|---|
| `GET /candidates` | além dos filtros da Fase 4: `keywords`, `skills[]`, `tags[]`, `education_level_min`, `education_course`, `experience_title`, `experience_company`, `min_experience_years`, `work_shift`, `salary_max`, `age_min`, `age_max`, `willing_to_relocate`, `has_resume`, `favorites`, `created_from`, `created_to`; `sort` aceita `relevance` e `experience`. Cada item traz `experience_months`, `is_favorite`, `tags` e `skills_preview` |
| `PUT` / `DELETE /candidates/{uuid}/favorite` | marca ou desmarca favorito do usuário atual |
| `PUT /candidates/{uuid}/tags` | `{"tags": [uuid, ...]}` substitui as etiquetas |
| `GET /tags` · `POST /tags` · `PUT /tags/{uuid}` · `DELETE /tags/{uuid}` | catálogo de etiquetas (`name`, `color`) |
| `POST /candidates/duplicates/check` | verifica `full_name`, `email`, `phone`, `phone_secondary`, `cpf`, `birth_date` (e `ignore` ao editar) |
| `GET /candidates/{uuid}/duplicates` | possíveis duplicados de um candidato |
| `GET /candidates/duplicates` | todos os pares suspeitos, paginado |
| `POST /candidates/{uuid}/duplicates/{uuid}/dismiss` | marca o par como pessoas diferentes |

## 6. Testes

95 testes no total (11 novos nesta fase), cobrindo busca sem acento e com plural, frase e exclusão,
ranking por relevância, soma de experiência sem sobreposição, filtros combinados, favoritos
pessoais, etiquetas (unicidade, busca, histórico, renomear e excluir) e duplicidades (telefone,
nome + nascimento, CPF/e-mail, nome comum sem alerta, descarte, exclusão). O fluxo de tela foi
validado no navegador, incluindo celular.
