# CEOF TALENT — Fase 11: Testes e homologação

## 1. O que foi entregue

| Item | Onde | O que verifica |
|---|---|---|
| Suíte do backend | `backend/tests` (PHPUnit) | **178 testes / 1.435 verificações**: regras de negócio de todos os módulos, permissões por perfil, uploads, portal, LGPD e segurança |
| Testes de segurança | `backend/tests/Feature/Security/SecurityTest.php` | 23 testes transversais (ver item 3) |
| Testes de interface | `e2e/` (Playwright) | 8 roteiros no navegador real, ponta a ponta (ver item 4) |
| Roteiro de homologação | `docker/scripts/homologacao.sh` | executa tudo em sequência e grava um relatório (ver item 2) |
| Persistência e backup | `docker/scripts/test-infrastructure.sh` (Fase 2), chamado pelo roteiro | recriação dos containers, backup, restauração, isolamento de rede |
| Roteiro manual | item 6 deste documento | conferência funcional pelo RH antes de liberar o uso |

## 2. Roteiro de homologação automático

Um único comando faz todas as verificações e salva o resultado em
`homologacao/relatorio-AAAAMMDD-HHMMSS.txt`:

| Etapa | Verificação |
|---|---|
| 1. Ambiente | todos os serviços sobem saudáveis |
| 2. Backend | suíte PHPUnit completa (banco de testes separado, nunca o banco real) |
| 3. Frontend | checagem de tipos (TypeScript) e build |
| 4. Dependências | `composer audit` e `npm audit` (vulnerabilidades conhecidas) |
| 5. Segurança HTTP | cabeçalhos de segurança, versão do servidor oculta, API exige login (401), proteção CSRF (419), `.env`/`.git`/logs/vendor/documentos não acessíveis pela web, produção com `APP_DEBUG=false` |
| 6. Retrato dos dados | contagem de candidatos, documentos, vagas, inscrições, entrevistas, usuários, consentimentos, solicitações LGPD, impressão digital dos documentos e número de arquivos |
| 7. Persistência e backup | containers destruídos e recriados; backup completo verificado; perda simulada e restauração |
| 8. Conferência | o retrato dos dados é idêntico ao do item 6; cada documento existe no disco e o conteúdo confere com o SHA-256 gravado no upload |
| 9. Interface (opcional) | testes no navegador |

O roteiro **não apaga dados**. A restauração do item 7 usa um backup feito segundos antes, por
isso os dados voltam exatamente ao mesmo ponto (o item 8 confere isso).

### Como rodar no Windows

O roteiro é um script `bash`. No Windows, use o **Git Bash** (instalado junto com o Git):

1. Abra a pasta do projeto no Explorador de Arquivos.
2. Clique com o botão direito em um espaço vazio → **Open Git Bash here** (no Windows 11,
   antes clique em "Mostrar mais opções").
3. Digite e tecle Enter:

```
./docker/scripts/homologacao.sh
```

Leva de 3 a 6 minutos. No final aparece **HOMOLOGAÇÃO APROVADA** ou a lista do que falhou.

Para incluir os testes de interface (na primeira vez baixa a imagem do navegador, com cerca de 2 GB):

```
E2E_EMAIL=seu.email@ceof.com.br E2E_PASSWORD='sua senha' ./docker/scripts/homologacao.sh --e2e
```

Em produção (VPS, com `docker-compose.prod.yml`): `./docker/scripts/homologacao.sh prod`
(sem as etapas 2, 3 e 9, que dependem das ferramentas de desenvolvimento).

### Comandos individuais (PowerShell ou qualquer terminal)

| O quê | Comando |
|---|---|
| Testes do backend | `docker compose exec app php artisan test` |
| Só os de segurança | `docker compose exec app php artisan test tests/Feature/Security` |
| Testes de interface | `docker compose --profile tests run --rm -e E2E_EMAIL=... -e E2E_PASSWORD=... e2e` |
| Checagem do frontend | `docker compose exec frontend npx vue-tsc -b` |

## 3. Testes de segurança

| Ameaça | Como é testado |
|---|---|
| Acesso sem login | **todas** as rotas da API são percorridas automaticamente: cada rota que não está na lista de rotas públicas precisa exigir login e usuário ativo, e responde 401 sem sessão. Uma rota nova esquecida sem proteção faz o teste falhar |
| Abuso das rotas públicas | toda rota pública tem limite de requisições |
| Acesso indevido por perfil | entrevistador recebe 403 em usuários, auditoria, LGPD, relatórios, dashboard, cadastros e duplicidades (somados aos testes de permissão de cada módulo) |
| Escalada de privilégio | usuário não consegue se dar perfil de administrador nem criar administradores |
| Senha provisória | a API fica bloqueada até a troca |
| Enumeração de registros | registros só são acessíveis por UUID; o ID sequencial responde 404 |
| SQL injection | buscas e ordenação com cargas maliciosas (`' OR 1=1`, `DROP TABLE`, `UNION SELECT`) não retornam dados nem alteram o banco |
| Vazamento de dados | hash de senha e tokens nunca aparecem nas respostas |
| Atribuição em massa | campos protegidos (id, uuid, anonimização, autor) são ignorados no cadastro |
| XSS | conteúdo com HTML/JavaScript é escapado nos relatórios PDF (a interface Vue escapa por padrão) |
| Detalhes internos | erro 500 não expõe mensagem, SQL nem rastreamento de pilha |
| Arquivos maliciosos | (Fase 4) executáveis disfarçados de PDF, extensões proibidas e arquivos acima do limite são recusados |
| Força bruta | (Fase 3) bloqueio após tentativas de login seguidas |
| CSRF, cabeçalhos, arquivos internos | verificados pelo roteiro de homologação, contra o Nginx real |

## 4. Testes de interface (Playwright)

| Roteiro | O que faz no navegador |
|---|---|
| Acesso | página protegida redireciona ao login; senha errada com mensagem genérica; menu completo do administrador; saída encerra a sessão |
| Candidatos | validação do formulário, cadastro, recusa de arquivo disfarçado, envio e download do currículo, busca no banco de talentos, histórico |
| Vagas | aprovação e publicação, inscrição de candidatos, arrastar no quadro, reprovação com motivo |
| Portal | visitante encontra a vaga, valida o formulário, envia currículo e recebe protocolo; o RH vê a inscrição |
| Relatórios | dashboard e exportação CSV e PDF |
| LGPD e auditoria | solicitação do titular, exportação JSON, anonimização e registro na auditoria |

Os testes rodam no container oficial do Playwright (`docker compose --profile tests run --rm e2e`),
contra o sistema em execução, com um usuário administrador informado em `E2E_EMAIL` e
`E2E_PASSWORD`. Os registros criados têm o prefixo **E2E** no nome. O relatório em HTML fica
em `e2e/resultados/relatorio/index.html` e, em caso de falha, há captura de tela e gravação
(trace) em `e2e/resultados/artefatos/`.

> Rode os testes de interface em desenvolvimento ou em uma cópia de homologação, não no
> ambiente de produção com dados reais.

## 5. Resultado desta homologação

Executado em 27/11/2026, ambiente de desenvolvimento:

| Etapa | Resultado |
|---|---|
| Serviços | 8 serviços saudáveis |
| Backend | 178 testes aprovados (1.435 verificações) |
| Frontend | tipos e build sem erros |
| Dependências | composer audit e npm audit: nenhuma vulnerabilidade conhecida |
| Segurança HTTP | todos os itens aprovados |
| Persistência | dados e documentos preservados após recriar todos os containers |
| Backup e restauração | backup íntegro; banco e documentos restaurados |
| Conferência dos dados | 98 candidatos, 3 documentos (conteúdo conferido), 11 vagas, 76 inscrições, 3 entrevistas: idênticos antes e depois |
| Interface | 8 de 8 roteiros aprovados |

## 6. Roteiro manual de homologação (RH)

Antes de liberar o uso, recomenda-se que a equipe confira os fluxos com dados de teste. Marque
cada item:

**Administrador**
- [ ] Criar usuários para cada perfil (Gestor de RH, Analista, Recrutador, Gestor de área, Entrevistador)
- [ ] Cada usuário entra com a senha provisória e é obrigado a trocá-la
- [ ] Cadastrar unidades, departamentos e cargos reais do hospital

**RH (Gestor/Analista/Recrutador)**
- [ ] Cadastrar um candidato com currículo em PDF e outro em Word; conferir as sugestões de competências
- [ ] Buscar por palavra-chave do currículo, por área e por cidade
- [ ] Criar uma vaga, enviar para aprovação, aprovar e publicar
- [ ] Conferir a vaga no portal `/trabalhe-conosco` e se candidatar como visitante
- [ ] Mover candidatos no quadro, reprovar com motivo, agendar entrevista
- [ ] Registrar o parecer da entrevista e aprovar/contratar
- [ ] Exportar relatórios em PDF, Excel e CSV

**Gestor de área**
- [ ] Vê apenas as vagas e candidatos do seu departamento
- [ ] Solicita uma vaga para o seu departamento

**Entrevistador**
- [ ] Vê apenas as entrevistas e candidatos que vai entrevistar
- [ ] Registra o parecer pelos critérios

**LGPD**
- [ ] Registrar uma solicitação de titular, exportar os dados e anonimizar um cadastro de teste
- [ ] Conferir a auditoria filtrando por usuário e período
- [ ] Preencher `PRIVACY_DPO_NAME` e `PRIVACY_DPO_EMAIL` no `.env` e revisar o aviso de privacidade com o jurídico

**Infraestrutura**
- [ ] Rodar `./docker/scripts/homologacao.sh` e guardar o relatório
- [ ] Ativar o backup diário (`BACKUP_ENABLED=true`) e conferir o primeiro backup em `backups/`
