# FestOnline — Plugin WordPress para inscrições na Festa dos Amigos

> **Versão:** 2.0.0 &nbsp;|&nbsp; **Licença:** GPL-2.0-or-later

Plugin para WordPress que digitaliza a inscrição de grupos na **Festa dos Amigos de Camboriú/SC**, evento da Prefeitura Municipal de Camboriú organizado pela Secretaria Municipal de Desenvolvimento Econômico Sustentável (SMDES). O participante faz o pré-cadastro, envia os documentos e acompanha a inscrição pela internet; a Comissão Organizadora e a Defesa Civil conduzem a análise, o sorteio das tendas e a confirmação das doações em painéis próprios.

Desenvolvido por **Caethano Rampelotti da Rosa** no projeto de Extensão Universitária do curso de Bacharelado em Sistemas de Informação do Instituto Federal Catarinense (IFC) – Campus Camboriú.

## Funcionalidades

- Pré-cadastro por CPF com senha de acesso sorteada e painel de acompanhamento do participante.
- Envio de documentos (imagem ou PDF, até 10 MB) com tipo verificado pelo conteúdo.
- Fluxo em cinco etapas controlado por máquina de estados:
  `Rascunho → Aguardando Análise → Deferido → Aguardando Comprovantes → Concluído`
- Indeferimento pela Comissão, com justificativa obrigatória mostrada ao participante, e reabertura com motivo.
- Limite de vagas configurável e lista de espera por ordem de chegada, com chamada manual do próximo da fila.
- Dashboard com contagens por status, vagas restantes e gráfico de inscrições por dia, sem dados pessoais.
- Geração automática da ficha de inscrição, do comprovante de doação e do termo de cessão em PDF.
- Notificações por e-mail em cada etapa.
- Painel da Comissão (lista, filtros, busca, exportação CSV/PDF, download em ZIP) e busca por CPF para a Defesa Civil.
- Portal externo com contas e permissões próprias.
- Registro de auditoria de todas as ações.

## Segurança e LGPD

- CPF cifrado (AES-256-CBC), com busca por hash HMAC-SHA256 e chaves guardadas fora do banco de dados.
- CPF mascarado nas telas, no CSV e nos PDFs; IPs anonimizados nos registros.
- Documentos fora da raiz pública do site, entregues apenas por URL assinada.
- Aceite do edital e do tratamento de dados registrado com data e hora.
- Anonimização em lote de inscrições concluídas ou indeferidas há mais de um ano.

## Tecnologias

PHP 8.2+, WordPress 6.4+, MariaDB/MySQL. Sem bibliotecas externas (sem Composer) e sem scripts ou fontes de terceiros. O repositório inclui o ambiente Docker e a suíte de testes automatizados (pytest).

## Estrutura

```
festa-amigos-2026.php   arquivo principal do plugin
includes/               classes PHP (admin, frontend, portal, segurança, PDF, e-mail, auditoria)
assets/                 CSS e JavaScript
templates/              modelo de página
uninstall.php           limpeza na desinstalação
DOCUMENTACAO.md         documentação técnica
DOCUMENTACAO_AUTH.md    autenticação do participante
```

## Instalação

1. Compacte a pasta do plugin (a que contém `festa-amigos-2026.php`) em um `.zip`.
2. No WordPress, acesse **Plugins → Adicionar novo → Fazer upload do plugin**, envie o `.zip` e ative.
3. Na ativação, o plugin cria as tabelas, os perfis de acesso, as páginas públicas, a pasta privada de documentos e as chaves de criptografia.

Requisitos: extensão `openssl` (obrigatória) e `zip` (opcional); HTTPS recomendado e obrigatório para o portal externo.

> **Backup:** o arquivo de chaves `festa-uploads-privados/festa-chaves.php` (ou as constantes `FESTA_ENC_KEY` e `FESTA_HMAC_KEY` no `wp-config.php`) deve entrar no backup, guardado separado do banco de dados. Sem ele, os CPFs gravados não podem ser lidos.
