# Atualização de versão — CEOF TALENT

Uma atualização nunca apaga dados: banco e documentos ficam em volumes/pastas que sobrevivem à
recriação dos containers, e as mudanças no banco (migrations) são aplicadas automaticamente na
subida.

> **Nunca** use `docker compose down -v`, `docker volume prune` ou
> `docker system prune --volumes`: esses comandos apagam o banco.

## 1. Servidor (Linux, com Git) — recomendado

Um comando faz tudo: confere a saúde, faz backup verificado, baixa a versão, reconstrói,
recria os containers e confere de novo.

```
cd /opt/ceof-talent
./docker/scripts/atualizar.sh v1.1.0           # versão (tag) desejada
```

Se algo falhar, o script para e mostra os comandos para voltar à versão anterior e, se
necessário, restaurar o backup `...-pre-update` que ele acabou de criar.

Opções: `dev` (ambiente de desenvolvimento), `--sem-git` (código já atualizado),
`--sem-build` (imagens já construídas).

## 2. Passo a passo manual

```
docker compose -f docker-compose.prod.yml exec backup backup --label pre-update
git fetch --tags && git checkout v1.1.0
# se a nova versão pedir, ajuste o .env (compare com o .env.example)
docker compose -f docker-compose.prod.yml up -d --build --wait
docker compose -f docker-compose.prod.yml ps
```

Confira `https://SEU-ENDEREÇO/api/health` e entre no sistema.

## 3. Computador Windows (desenvolvimento ou testes)

1. Baixe o ZIP da nova versão no GitHub (botão **Code → Download ZIP**) e extraia em uma pasta
   nova.
2. Copie o arquivo `.env` da pasta antiga para a nova.
3. Na pasta **antiga**, abra o PowerShell e rode `docker compose down` (sem `-v`).
4. Na pasta **nova**, rode `docker compose up -d --build`.
5. Abra http://localhost:8090 (ou a porta do seu `.env`).

Os dados continuam, porque o banco fica em um volume do Docker com o nome do projeto. Os
documentos ficam na pasta `backend/storage/app`: copie-a da pasta antiga para a nova **antes**
do passo 4 (ou mantenha sempre a mesma pasta e só substitua os arquivos).

## 4. Depois de atualizar

- Leia as notas da versão (pull request / release no GitHub): podem existir variáveis novas no
  `.env.example`.
- Rode a homologação em um ambiente de testes antes de atualizar a produção:
  `./docker/scripts/homologacao.sh`.
