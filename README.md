# Festa dos Amigos 2026 — sistema de inscrições (WordPress + Docker)

Projeto de extensão universitária: um WordPress que já sobe com o plugin **Festa dos Amigos 2026** instalado e ativo, para organizar as inscrições dos grupos que montam tenda no evento.

## Funcionalidades

- **Participante:** faz o pré-cadastro, finaliza a inscrição, envia documentos e acompanha cada etapa no próprio painel.
- **Comissão:** analisa as inscrições, defere ou indefere (com justificativa obrigatória), reabre quando preciso e sorteia as tendas.
- **Vagas:** limite configurável; quem finaliza com as vagas esgotadas entra numa lista de espera por ordem de chegada.
- **Defesa Civil:** confirma a entrega dos colchões, sem acesso à lista de inscrições nem aos documentos.
- **Dashboard:** contagens por status, vagas restantes e o gráfico de inscrições por dia, sem dados pessoais.
- **Portal externo:** acesso da equipe fora do wp-admin, com permissões por conta.
- **LGPD:** CPF criptografado e sempre mascarado, auditoria de cada ação com IP anonimizado e anonimização das inscrições antigas.

Detalhes do plugin: [plugin/README.md](plugin/README.md) e [plugin/DOCUMENTACAO.md](plugin/DOCUMENTACAO.md).

## Pré-requisitos

- Docker Desktop (Windows ou macOS) ou Docker Engine com Compose v2 (Linux).
- Portas **8080** (site) e **8025** (e-mail de teste) livres. Dá para trocar no `.env` (veja [Variáveis](#variáveis)).

## Início rápido

```bash
git clone https://github.com/Caethano/EXTENSAO-UNIVERSITARIA.git
cd EXTENSAO-UNIVERSITARIA
docker compose up -d --build
docker compose logs wordpress
```

Na primeira vez, o processo leva mais ou menos um minuto: baixa as imagens, instala o WordPress em português e ativa o plugin. O final do log traz o acesso do administrador:

```
[festa]  Administrador do WordPress (http://localhost:8080/wp-admin/)
[festa]    usuário: admin
[festa]    senha:   3f9c0e...
```

Essa senha é sorteada uma única vez e não aparece mais quando o container é recriado. Para definir a sua, preencha `WP_ADMIN_PASSWORD` no `.env` **antes** da primeira subida.

### Acessos

| Endereço | Para quê |
|---|---|
| http://localhost:8080 | Site público |
| http://localhost:8080/wp-admin/ | Administração; o sistema fica no menu **Festa dos Amigos** |
| http://localhost:8080/pre-inscricao/ | Pré-cadastro do participante |
| http://localhost:8080/inscricao/ | Finalização da inscrição |
| http://localhost:8080/painel-inscricao/ | Painel do participante |
| http://localhost:8025 | Mailpit, a caixa que recebe todos os e-mails do site |

O portal externo da equipe começa desligado; ative em **Festa dos Amigos → Portal externo**.

## O que a instalação já entrega

- WordPress 7.1 + PHP 8.3, MariaDB 11.8 e Mailpit.
- Idioma `pt_BR`, fuso `America/Sao_Paulo` e links amigáveis.
- Plugin ativo, com as páginas do participante criadas.
- Chaves de criptografia dos CPFs geradas na primeira subida e guardadas **fora do banco**, numa pasta sem acesso pela web.
- Nenhum e-mail sai para a internet: o Mailpit captura tudo.
- O banco não expõe porta; o site e o Mailpit só respondem em `127.0.0.1`.

## Variáveis

Todas têm valor padrão. Para alterar, copie `.env.example` para `.env` (ele fica fora do Git).

| Variável | Padrão | Uso |
|---|---|---|
| `WP_PORT` | `8080` | Porta do site |
| `MAIL_PORT` | `8025` | Porta do Mailpit |
| `WP_URL` | `http://localhost:<WP_PORT>` | Endereço do site (pode mudar quando quiser) |
| `WP_ADMIN_USER` / `WP_ADMIN_PASSWORD` / `WP_ADMIN_EMAIL` | `admin` / sorteada / `admin@festa.local` | Administrador criado na primeira subida |
| `WP_TITLE`, `WP_LOCALE`, `WP_TIMEZONE` | `Festa dos Amigos 2026`, `pt_BR`, `America/Sao_Paulo` | Lidas só na primeira subida |
| `WP_DEBUG` | `0` | Com `1`, os avisos do PHP vão para `docker compose logs wordpress` |
| `DB_NAME` / `DB_USER` / `DB_PASSWORD` | `wordpress` | Banco; a senha vale a partir da primeira subida e o MariaDB não a troca sozinho |

## Dados e backup

| Volume | O que guarda |
|---|---|
| `db_data` | Banco: inscrições, usuários e configurações |
| `wp_data` | Arquivos do WordPress: núcleo, temas e uploads públicos |
| `festa_privado` | Documentos dos participantes **e as chaves dos CPFs** |

> **Atenção:** sem as chaves, os CPFs do banco não podem ser lidos. Faça backup do volume `festa_privado` e guarde-o separado do backup do banco, porque quem tiver os dois juntos consegue ler todos os CPFs.

Gerar backup (PowerShell, Linux ou macOS):

```bash
mkdir backups
docker compose exec db sh -c 'MYSQL_PWD=$MARIADB_PASSWORD mariadb-dump -u $MARIADB_USER $MARIADB_DATABASE > /tmp/banco.sql'
docker compose cp db:/tmp/banco.sql backups/banco.sql
docker compose exec wordpress tar czf /tmp/festa-privado.tgz -C /var/www festa-uploads-privados
docker compose cp wordpress:/tmp/festa-privado.tgz backups/festa-privado.tgz
```

Restaurar:

```bash
docker compose cp backups/banco.sql db:/tmp/banco.sql
docker compose exec db sh -c 'MYSQL_PWD=$MARIADB_PASSWORD mariadb -u $MARIADB_USER $MARIADB_DATABASE < /tmp/banco.sql'
docker compose cp backups/festa-privado.tgz wordpress:/tmp/festa-privado.tgz
docker compose exec wordpress tar xzf /tmp/festa-privado.tgz -C /var/www
```

A pasta `backups/` já está no `.gitignore`; ainda assim, nunca a envie para o repositório, porque ela tem dados pessoais.

## Desenvolvimento

Depois de mexer em `plugin/`, reconstrua:

```bash
docker compose up -d --build
```

O container copia o plugin da imagem a cada início. Quando a versão muda, as tabelas do plugin são atualizadas na primeira visita ao site, e os dados dos volumes continuam onde estão.

Para ver as alterações na hora, sem reconstruir, use o modo de desenvolvimento (monta `plugin/` dentro do container e liga os avisos do PHP):

```bash
docker compose -f docker-compose.yml -f docker-compose.dev.yml up -d --build
docker compose logs -f wordpress
```

## Testes automatizados

São 329 testes em [tests/](tests/), escritos com pytest. Eles fazem pelo HTTP o que um navegador faria (como participante, Comissão, Defesa Civil, administrador e contas do portal) e conferem o banco diretamente. Cobrem o isolamento entre participantes, as permissões de cada papel, o fluxo completo da inscrição, indeferimento, lista de espera, limite de vagas (com deferimentos simultâneos), dashboard e as regressões de segurança.

Precisa de Python 3.10 ou mais novo e dos containers no ar:

```powershell
.\tests\rodar.ps1                  # Windows: suíte inteira (uns 5 minutos)
.\tests\rodar.ps1 -m seguranca     # só as regressões de segurança
```

```bash
sh tests/rodar.sh                  # Linux/macOS
sh tests/rodar.sh -m "not lento"   # pula as rajadas de requisições paralelas
```

Os testes criam e removem os próprios dados (inscrições "Teste Auto …", usuários `festa_t_*` e contas `festa-t-*`). Use só numa instância de teste, nunca com dados reais. Se trocou a porta, deixe `WP_PORT` ou `WP_URL` no `.env` ou exporte antes de rodar.

## Dados de exemplo

Um script popula o site com 15 inscrições fictícias, uma ou mais em cada etapa:
- rascunho;
- aguardando análise;
- deferida;
- aguardando comprovantes, nas quatro situações possíveis (nada enviado, recibo enviado, recibo aprovado, só colchões);
- concluída;
- indeferida, com justificativa;
- lista de espera, com duas inscrições finalizadas depois de esgotadas as vagas.

Para criar a lista de espera, o script iguala o **limite de vagas** ao número de vagas ocupadas e não o desfaz depois. Ajuste em **Festa dos Amigos → Configurações**.

Tudo passa pelos caminhos reais do sistema: o participante pelo site, a Comissão e a Defesa Civil pelo wp-admin. Os documentos enviados são PDFs de teste com os dados de cada inscrição, marcados "documento de teste, sem valor legal".

```powershell
.\tests\popular.ps1 --apagar-tudo      # Windows
```

```bash
sh tests/popular.sh --apagar-tudo      # Linux/macOS
```

> **Cuidado:** o comando apaga **todas** as inscrições do site, não só as de teste. Antes, o script salva o banco e a pasta privada em `backups/<data-hora>/`.

Arquivos gerados (nenhum vai para o Git):
- **`cadastros-teste.md`:** CPF e senha de cada participante e o login das contas `comissao.teste` e `defesa.teste`, que recebem senhas novas a cada execução;
- **`pdfs-teste/`:** cópia de cada PDF enviado.

Os e-mails para os participantes podem ser vistos no Mailpit.

## Recomeçar do zero

```bash
docker compose down -v
```

Remove banco, documentos e chaves, sem volta.

## Publicar num servidor

A configuração deste repositório é para uso local. Para colocar no ar:
- use um proxy com HTTPS na frente e defina `WP_URL=https://seu-dominio`;
- troque o Mailpit por um SMTP de verdade e tire `SMTP_HOST` do `docker-compose.yml`;
- defina senhas fortes no `.env` antes da primeira subida;
- automatize o backup dos três volumes.

## Organização

```
├── Dockerfile                 imagem com WordPress 7.1, WP-CLI e o plugin
├── docker-compose.yml         serviços: banco, WordPress e Mailpit
├── docker-compose.dev.yml     modo de desenvolvimento (plugin montado ao vivo)
├── .env.example               modelo de configuração (copie para .env)
├── docker/
│   ├── festa-entrypoint.sh    instala o WordPress e ativa o plugin na primeira subida
│   └── mu-plugins/            envia os e-mails para o Mailpit
├── plugin/                    plugin Festa dos Amigos 2026 (2.0.0)
└── tests/                     testes automatizados e gerador de dados de exemplo
```
