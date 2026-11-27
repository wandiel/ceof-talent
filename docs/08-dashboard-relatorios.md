# CEOF TALENT — Fase 8: Dashboard e relatórios

## 1. O que foi entregue

- **Dashboard na tela inicial** (para quem tem `dashboard.view`):
  - Filtros de período (últimos 30 dias, este mês, últimos 3 ou 12 meses, este ano, todo o
    período ou datas personalizadas), departamento, unidade e área profissional.
  - Indicadores: candidatos no banco (e quantos ativos), novos cadastros, vagas abertas (e
    posições), candidatos em processo, entrevistas agendadas, aprovações, contratações e tempo
    médio de contratação. Os cartões levam à tela correspondente.
  - Gráficos mensais de novos cadastros, inscrições e contratações (passe o mouse para ver o
    valor de cada mês).
  - Funil do processo seletivo: das inscrições do período, quantas chegaram a cada etapa, com
    percentual, reprovados e desistentes.
  - Candidatos por área profissional, tabela por departamento e as próximas entrevistas.
- **Relatórios** (menu Recrutamento → Relatórios), com os mesmos filtros e exportação em
  **PDF**, **Excel (XLSX)** e **CSV**:

| Grupo | Relatório |
|---|---|
| Banco de currículos | Currículos cadastrados (lista), por área, por escolaridade, por cidade, novos cadastros por mês, origem dos candidatos |
| Vagas e processos | Vagas abertas, vagas encerradas, processos seletivos, candidatos por etapa |
| Entrevistas e contratações | Entrevistas, aprovações, contratações, tempo médio de contratação |

- A tela mostra até 500 linhas; o arquivo exportado traz todas (PDF até 3.000 linhas).
- O PDF sai com o logotipo do CEOF, os filtros usados, quem gerou, data e numeração de páginas.
- O CSV usa `;` e acentuação compatível com o Excel em português.
- Textos que começam com `=`, `+`, `-` ou `@` são neutralizados nas planilhas, para que nenhum
  conteúdo digitado por um candidato vire fórmula ao abrir o arquivo.
- **Dados de demonstração**: o comando `php artisan ceof:demo-data` cria 90 candidatos, 8 vagas,
  inscrições com histórico ao longo de 12 meses e algumas entrevistas. Ele só roda fora de
  produção e só quando o banco ainda não tem candidatos.

## 2. Quem vê o quê

| Perfil | Dashboard | Relatórios | Exportar |
|---|---|---|---|
| Administrador, Gestor de RH, Analista de RH | todos os departamentos | sim | sim |
| Recrutador | todos os departamentos | sim | não |
| Gestor de departamento | só o próprio departamento (filtro travado) | sim, só o próprio departamento | não |
| Entrevistador | não | não | não |

Cada exportação fica registrada na auditoria (`report.exported`) com o relatório, o formato, a
quantidade de linhas e os filtros, sem os dados exportados. Há limite de 20 exportações por
minuto por usuário.

## 3. Como os números são calculados

- **Período**: cadastros pela data de cadastro; inscrições pela data de inscrição; aprovações e
  contratações pela data da movimentação no histórico; entrevistas pela data marcada.
- **Vagas abertas**: aprovadas, publicadas ou suspensas.
- **Candidatos em processo**: inscrições em andamento (fora de contratado, reprovado e
  desistente) em vagas abertas.
- **Funil**: uma inscrição conta em todas as etapas até a mais avançada que alcançou, mesmo que
  depois tenha sido reprovada ou desistido.
- **Tempo médio de contratação**: dias entre a inscrição e a contratação.
- Com filtro de departamento ou unidade, os números de candidatos consideram só quem se
  inscreveu em vagas desse departamento ou unidade.

## 4. API (`/api/v1`)

| Método e rota | Descrição |
|---|---|
| `GET /dashboard` | indicadores; filtros `from`, `to` (AAAA-MM-DD), `department`, `unit` (uuids), `area` |
| `GET /reports` | catálogo de relatórios e opções dos filtros |
| `GET /reports/{chave}` | colunas e linhas (até 500) com `meta.total` |
| `GET /reports/{chave}/export/{pdf\|xlsx\|csv}` | arquivo para download |

Bibliotecas adicionadas ao backend: `dompdf/dompdf` (PDF) e `openspout/openspout` (XLSX).

## 5. Testes

141 testes no total (9 novos nesta fase): indicadores, funil, séries mensais e quebra por
departamento conferidos contra um cenário montado; gestor travado no próprio departamento;
permissões; validação dos filtros; todos os 14 relatórios executando; conteúdo dos principais;
exportação nos três formatos com auditoria e proteção contra fórmulas; comando de demonstração.

No navegador: dashboard com dados de demonstração, filtro por departamento, dica ao passar o
mouse, troca de relatórios e download de PDF, Excel e CSV.
