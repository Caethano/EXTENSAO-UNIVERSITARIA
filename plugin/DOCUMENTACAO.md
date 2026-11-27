# DOCUMENTACAO.md — Plugin: Festa dos Amigos 2026

> Documentação técnica da versão 2.0.0. A visão geral do plugin está em `README.md`; a autenticação do participante, em `DOCUMENTACAO_AUTH.md`.

---

## 1. ARQUITETURA DE DIRETÓRIOS

```
festa-amigos-2026/
├── festa-amigos-2026.php              # Bootstrap: constantes, autoloader, hooks de ciclo de vida
├── uninstall.php                      # Limpeza de roles, opções e tabelas
├── assets/
│   ├── css/
│   │   ├── festa-forms.css            # Páginas do participante
│   │   ├── festa-admin.css            # Telas de gerenciamento (wp-admin e portal)
│   │   └── festa-portal.css           # Moldura do portal externo
│   └── js/
│       ├── festa-forms.js             # Máscaras, validação, uploads, envio AJAX
│       └── festa-admin.js             # Confirmações e seleção em massa
├── templates/
│   └── festa-fullpage.php             # Modelo "Festa dos Amigos — Página inteira"
└── includes/
    ├── class-autoloader.php           # PSR-4: Festa\Sub\ClassName → includes/sub/class-class-name.php
    ├── class-plugin.php               # Orquestrador: ativação, atualização, boot dos módulos
    ├── cpt/
    │   └── class-cpt-inscricao.php    # Registro do CPT + leitura (CPF mascarado) + exclusão
    ├── roles/
    │   └── class-roles.php            # Roles, capabilities e restrições de menu
    ├── status/
    │   ├── class-state-machine.php    # Máquina de estados + hook festa_status_transition
    │   └── class-vagas.php            # Limite de vagas, vagas restantes, lista de espera e trava
    ├── crypto/
    │   └── class-crypto.php           # AES-256-CBC, HMAC-SHA256, máscara de CPF
    ├── fields/
    │   └── class-fields.php           # Definição única: opções, documentos, validadores, aceite
    ├── settings/
    │   └── class-settings.php         # Configurações (abertura, limite de vagas, textos, evento, e-mails)
    ├── pages/
    │   ├── class-pages.php            # Criação das páginas públicas e resolução dos endereços
    │   └── class-template.php         # Registro do modelo de página inteira
    ├── upload/
    │   └── class-handler.php          # Upload seguro, leitura, URLs assinadas, envio de arquivos
    ├── pdf/
    │   ├── class-generator.php        # Documentos oficiais em HTML (ficha, doação, termo)
    │   ├── class-writer.php           # Gerador de PDF nativo (exportações do painel)
    │   └── templates/
    │       ├── ficha-inscricao.php
    │       ├── doacao-colchoes.php
    │       └── termo-cessao.php
    ├── frontend/
    │   ├── class-ui.php                       # CSS/JS, cabeçalho, cartões, campos, formulários
    │   ├── class-auth-manager.php             # Sessão silo, senhas e bloqueios do participante
    │   ├── class-ajax-handler.php             # Endpoints do participante (AJAX e sem JS)
    │   ├── class-shortcode-ini-inscricao.php  # [ini_inscricao]
    │   ├── class-shortcode-inscricao.php      # [festa_inscricao]
    │   └── class-shortcode-painel.php         # [festa_painel]
    ├── admin/
    │   ├── class-ctx.php              # Fachada do contexto de gerenciamento
    │   ├── class-ctx-driver.php       # Contrato do contexto
    │   ├── class-ctx-admin.php        # Contexto wp-admin (capabilities, nonces, admin-post)
    │   ├── class-admin-menu.php       # Menus
    │   ├── class-panel.php            # Lista, detalhe, documentos, ZIP, CSV, PDF, exclusão, lista de espera
    │   ├── class-admin-actions.php    # Ações da Comissão: deferir, indeferir, reabrir, chamar da fila, tenda, recibo
    │   ├── class-dashboard.php        # Dashboard: contagens e gráfico "Inscrições por dia" (SVG gerado no servidor)
    │   ├── class-admin-defesa.php     # Busca por CPF e confirmação de colchões
    │   └── class-portal-admin.php     # wp-admin → Portal externo (liga/desliga, contas)
    ├── portal/
    │   ├── class-portal.php           # Roteamento, login, CSRF, CSP, telas do portal
    │   ├── class-store.php            # Contas, senhas, sessões, bloqueios, registro de atividade
    │   └── class-ctx-portal.php       # Contexto do portal (permissões da conta, token CSRF)
    ├── notifications/
    │   └── class-email.php            # E-mails transacionais por status
    ├── audit/
    │   └── class-audit-log.php        # Log de auditoria + histórico de cada inscrição
    └── security/
        ├── class-security-manager.php # Proteção do diretório, rate limit, travas
        └── class-lgpd-anonymizer.php  # Ferramenta de anonimização em lote
```

Diretório de documentos (fora do plugin): `festa-uploads-privados/{inscricao_id}/{uuid}.{ext}`, ao lado da pasta do WordPress.

### Contexto de gerenciamento (`Festa\Admin\Ctx`)

As telas e ações de gerenciamento são escritas uma vez e funcionam em dois lugares. O que muda — quem é o usuário, o que ele pode, como são os endereços e como cada envio é autenticado — fica atrás da fachada `Ctx`:

| | wp-admin (`CtxAdmin`) | Portal externo (`CtxPortal`) |
|---|---|---|
| Usuário | Usuário do WordPress | Conta do portal |
| Permissão | Capability da role | Lista de permissões da conta |
| Envio de ação | `admin-post.php` + nonce | URL do portal + token CSRF da sessão |
| Download | `admin-post.php` + nonce | `?baixar=…` com a sessão |

Por isso as telas não usam `style="…"` nem `<script>` embutido: o portal sai com uma política de conteúdo (CSP) que os proíbe.

---

## 2. ROLES, CAPABILITIES E PERMISSÕES

| Permissão (`Ctx::perms`) | Capability no wp-admin | `festa_comissao` | `festa_defesacivil` | Administrador |
|---|---|:---:|:---:|:---:|
| `ver` — inscrições, documentos, auditoria | `festa_analisar_inscricao` | ✓ | ✗ | ✓ |
| `dashboard` — Dashboard (só contagens) | `festa_ver_dashboard` | ✓ | ✗ | ✓ |
| `analisar` — deferir, indeferir, reabrir, chamar da lista de espera | `festa_upload_deferimento` | ✓ | ✗ | ✓ |
| `tenda` — número da tenda | `festa_inserir_numero_tenda` | ✓ | ✗ | ✓ |
| `recibo` — aprovar recibo | `festa_aprovar_recibo_tenda` | ✓ | ✗ | ✓ |
| `colchoes` — buscar CPF e confirmar | `festa_confirmar_colchoes` | ✗ | ✓ | ✓ |
| `editar` — observações, contato, anexos, senha | `festa_analisar_inscricao` | ✓ | ✗ | ✓ |
| `exportar` — CSV e PDF | `festa_ver_relatorio_conclusao` | ✓ | ✗ | ✓ |
| `excluir` — excluir inscrições | `festa_excluir_inscricao` | ✗ | ✗ | ✓ |
| `config` — configurações (inclui o limite de vagas) | `festa_configurar` | ✗ | ✗ | ✓ |

**Notas:**
- `festa_participante` é mantida por compatibilidade; os participantes **não** são usuários do WordPress (ver `DOCUMENTACAO_AUTH.md`).
- `festa_defesacivil`: wp-admin restrito à página `festa_busca_colchoes`. Sem listagem de inscrições, sem Dashboard e sem acesso aos documentos do participante — só ao comprovante de doação que ela emite.
- No portal externo, cada permissão é marcada por conta. Uma conta só com `dashboard` vê os números e nenhuma tela com dados pessoais. A Comissão do portal define o limite de vagas se tiver `config`.
- Portal externo e Ferramentas LGPD exigem `manage_options`.
- Roles registradas na ativação e a cada atualização do plugin; removidas na desativação.

---

## 3. CPT `inscricao_festa`

### Registro
- `public`: `false` · `publicly_queryable`: `false` · `show_in_rest`: `false`
- `show_ui`: `false` — o CPT é só armazenamento; as telas são as de `Admin\Panel`
- `capability_type`: `inscricao_festa`

### Meta Fields

| Meta Key                         | Tipo     | Origem / Observações                                              |
|----------------------------------|----------|-------------------------------------------------------------------|
| `_cpf`                           | `string` | CPF **cifrado** (AES-256-CBC): `base64(iv):base64(ciphertext)`   |
| `_cpf_hash`                      | `string` | HMAC-SHA256 do CPF — unicidade e busca                            |
| `_nome_responsavel`              | `string` | Pré-cadastro                                                      |
| `_senha_hash`                    | `string` | `wp_hash_password()` da senha de acesso                           |
| `_senha_provisoria`              | `int`    | 1 = senha sorteada pelo sistema; 0 = escolhida pelo participante  |
| `_email`                         | `string` | Finalização (`sanitize_email` + `is_email`)                       |
| `_telefone`                      | `string` | Finalização; 10 ou 11 dígitos, gravado como `(00) 00000-0000`     |
| `_nome_grupo`                    | `string` | Finalização                                                       |
| `_qtd_participantes`             | `int`    | 1 a 500                                                           |
| `_coccao`                        | `string` | enum `gas` \| `carvao`                                            |
| `_espaco`                        | `int`    | enum `1` \| `2` (tendas solicitadas)                              |
| `_aceite_em`                     | `string` | Data/hora do aceite do edital e da LGPD                           |
| `_numero_tenda`                  | `string` | Informado pela Comissão no sorteio                                |
| `_status`                        | `string` | Controlado exclusivamente pela State Machine (ver §4)             |
| `_fila_em`                       | `string` | Data/hora da finalização com as vagas esgotadas: ordem da fila    |
| `_indeferimento_motivo`          | `string` | Justificativa do indeferimento (texto da Comissão, sanitizado)    |
| `_indeferido_em`                 | `string` | Data/hora do indeferimento                                        |
| `_reabertura_motivo`             | `string` | Motivo da última reabertura                                       |
| `_reaberto_em`                   | `string` | Data/hora da última reabertura                                    |
| `_tenda_aprovada`                | `bool`   | Flag: recibo aprovado pela Comissão                               |
| `_colchoes_confirmados`          | `bool`   | Flag: entrega confirmada pela Defesa Civil                        |
| `_observacoes`                   | `string` | Observações internas da equipe                                    |
| `_data_inscricao`                | `string` | Data/hora do pré-cadastro (texto)                                 |
| `_ip_inscricao`                  | `string` | IP anonimizado                                                    |
| `_lgpd_purged`                   | `bool`   | Inscrição anonimizada                                             |
| `_upload_identidade`             | `string` | Nome do arquivo (UUID) — participante, opcional                   |
| `_upload_comprovante_residencia` | `string` | idem                                                              |
| `_upload_ficha_gerada`           | `string` | Ficha de inscrição (HTML) gerada na finalização                   |
| `_upload_ficha_assinada`         | `string` | Participante, na fase de análise                                  |
| `_upload_deferimento`            | `string` | Comissão, ao deferir                                              |
| `_upload_recibo_tenda`           | `string` | Participante, após o sorteio                                      |
| `_upload_comprovante_doacao`     | `string` | Gerado ao confirmar os colchões                                   |
| `_upload_termo_cessao`           | `string` | Gerado ao concluir                                                |
| `_upload_*_info`                 | `array`  | Nome original, tamanho e data de envio de cada upload             |

A lista de documentos (rótulo, meta, quem envia, formatos aceitos) é definida uma única vez em `Festa\Fields\Fields::documents()` e consultada pelo upload, pelo painel, pelo ZIP, pelo CSV e pelo expurgo LGPD.

**Regra de unicidade CPF:** antes de criar a inscrição, consulta por `_cpf_hash`. Uma trava atômica impede que dois envios simultâneos do mesmo CPF criem duas inscrições.

---

## 4. MÁQUINA DE ESTADOS

```
  rascunho
     │  Participante finaliza a inscrição            (festa_finalizar_inscricao)
     │
     ├── com vaga (ou sem limite) ─────────────────────────────┐
     └── vagas esgotadas → lista_espera                         │
                             │  → e-mail "lista de espera"      │
                             │    (posição na fila)             │
                             │  Comissão: "Chamar próximo       │
                             │  da fila" — exige vaga           │
                             │  (festa_chamar_fila)             │
                             │  → e-mail "você foi chamado"     │
                             ▼                                  ▼
  aguardando_analise        → e-mail à Comissão + confirmação ao participante
     │  Comissão envia o deferimento — exige vaga    (festa_deferir)
     ▼
  deferido                  → e-mail ao participante
     │  Comissão registra o número da tenda          (festa_tenda)
     ▼
  aguardando_comprovantes   → e-mail ao participante com o número da tenda
     │
     ├── Participante envia o recibo → Comissão aprova      (festa_recibo)   → _tenda_aprovada
     └── Participante entrega os colchões → Defesa confirma (festa_colchoes) → _colchoes_confirmados
     │                                                        + comprovante de doação
     │  [_tenda_aprovada AND _colchoes_confirmados]
     ▼
  concluido                 → Termo de Cessão gerado + e-mail ao participante
```

Indeferimento e reabertura (só a Comissão, permissão `analisar`):

```
  aguardando_analise ──┐
                       ├── Comissão indefere, com justificativa   (festa_indeferir)
  lista_espera ────────┘        → e-mail "inscrição indeferida" com a justificativa
                       ▼
                   indeferido     participante: só leitura, sem envios
                       │  Comissão reabre, com motivo             (festa_reabrir)
                       ▼        → e-mail "reaberta para análise"
               aguardando_analise
```

- **Transições inválidas e regressões** são bloqueadas por `LogicException`; a conclusão sem as duas flags e o deferimento/chamada sem vaga, por `RuntimeException`.
- **Justificativa obrigatória:** `→ indeferido` e `indeferido → aguardando_analise` exigem um texto de 10 a 900 caracteres (`StateMachine::MOTIVO_MIN/MAX`; quebras de linha contam como um caractere, como no navegador), sanitizado com `sanitize_textarea_field()` na entrada e escapado (`esc_html`) em toda saída (telas, e-mails, CSV, PDF). O texto cabe inteiro na auditoria.
- Toda transição dispara `festa_status_transition($id, $anterior, $novo, $user_id, $motivo)`, ouvido por: geração de documentos (p10), e-mails (p10) e auditoria (p20). A auditoria guarda quem, quando e a justificativa/motivo.
- **Não existe edição livre de status** em nenhuma tela: a única forma de mudar o status são as ações acima.

### 4.1 Limite de vagas e lista de espera (`Festa\Status\Vagas`)

| Regra | Como funciona |
|---|---|
| Limite | Configurações → "Limite de vagas", inteiro ≥ 0. **0 = sem limite.** Valor inválido (negativo, texto, decimal) é recusado e nada é salvo. |
| Vagas ocupadas | Inscrições em `deferido` + `aguardando_comprovantes` + `concluido`. |
| Vagas restantes | `limite − ocupadas`, nunca negativo. Baixar o limite abaixo do ocupado não desfaz nada: só bloqueia novos deferimentos. |
| Deferir sem vaga | Bloqueado na tela (botão desativado, aviso) e no servidor (`vagas_esgotadas`) — e na própria máquina de estados. |
| Concorrência | Deferir, chamar da fila, indeferir, reabrir e o envio da ficha pegam a trava `festa_vagas` (`SecurityManager::db_lock()`: `GET_LOCK` do banco, com espera de até 15 s). A trava pertence à conexão: se a requisição parar, o banco a libera — não existe trava abandonada. Dentro dela, status e vagas são relidos do banco; dois deferimentos simultâneos pela última vaga: um defere, o outro recebe `vagas_esgotadas`. Os e-mails das transições só saem depois de a trava ser liberada. |
| Entrar na fila | Finalização (ou envio da ficha ainda em rascunho) com limite e 0 vagas restantes → `lista_espera`, com `_fila_em` = data/hora da finalização. O pré-cadastro continua aberto e avisa que a inscrição entrará na lista de espera. |
| Ordem | Menor `_fila_em` primeiro; empate pelo protocolo. O participante vê a posição no painel. |
| Chamar | "Chamar próximo da fila" (lista de inscrições) move o primeiro da fila para `aguardando_analise`. Só com vaga restante. |
| Limite de ações | Indeferir, reabrir e chamar da fila avisam o participante por e-mail: no máximo 60 por usuário a cada 10 minutos e, numa mesma inscrição, no máximo 6 indeferimentos/reaberturas por dia (de qualquer conta). |

### 4.2 Dashboard

Tela "Dashboard" no wp-admin (menu Festa dos Amigos) e aba no portal, com a permissão `dashboard`. Só contagens — nenhum nome, CPF, e-mail ou telefone:
- cartões: total, um por status e vagas restantes ("Limite: N" ou "Sem limite");
- gráfico de barras "Inscrições por dia" (pré-cadastros), da abertura configurada — ou do primeiro pré-cadastro — ao encerramento configurado — ou hoje —, no máximo um ano. O SVG é gerado em PHP, sem biblioteca e sem script; cada barra tem uma dica (`<title>`) e o gráfico tem descrição para leitores de tela e uma tabela com os mesmos números.

A configuração opcional "Abrir automaticamente em" define o início do período: antes dela, o pré-cadastro avisa a data de abertura.

---

## 5. FLUXO DAS 5 FASES

### Fase 1 — Inscrição (participante)
1. `[ini_inscricao]`: CPF + nome → inscrição em `rascunho` + senha sorteada (mostrada uma vez) + sessão iniciada.
2. `[festa_inscricao]`: contato, dados do grupo, documentos opcionais (identidade, comprovante de residência) e aceite do edital/LGPD.
3. O servidor valida tudo antes de gravar, gera a ficha (`_upload_ficha_gerada`) e transita para `aguardando_analise`.

### Fase 2 — Análise (Comissão)
1. O participante abre a ficha no painel, assina e envia a ficha assinada (ou a Comissão a anexa, se entregue em mãos).
2. A Comissão confere dados e documentos, envia o deferimento assinado → `deferido` (se houver vaga).
3. Ou indefere, com justificativa → `indeferido`. A inscrição fica somente leitura para o participante, que vê a justificativa no painel; só a Comissão a reabre (com motivo), de volta para `aguardando_analise`.
4. Com as vagas esgotadas, as novas finalizações vão para `lista_espera`; quando uma vaga é liberada (ou o limite aumenta), a Comissão chama o próximo da fila.

### Fase 3 — Sorteio (Comissão)
1. A Comissão informa o número da tenda → `aguardando_comprovantes`. O número pode ser corrigido depois, sem nova transição.

### Fase 4 — Validação Dupla
- **4A — Recibo:** o participante envia o recibo (pode substituí-lo até a aprovação); a Comissão abre e aprova → `_tenda_aprovada`.
- **4B — Colchões:** a Defesa Civil busca o CPF e confirma a entrega → `_colchoes_confirmados` + comprovante de doação.

### Fase 5 — Conclusão (sistema)
1. Com as duas flags, a ação que completou o par transita para `concluido`.
2. O Termo de Cessão é gerado e fica disponível no painel do participante (URL assinada, 48h).
3. A inscrição passa a ser somente leitura (sem correção de contato nem anexos).

---

## 6. BLOCO LGPD / DPO

### 6.1 Criptografia de CPF
- `AES-256-CBC`, com a chave de cifra normalizada para 32 bytes por SHA-256.
- IV aleatório por operação, armazenado junto ao ciphertext (`base64(IV):base64(ciphertext)`).
- Hash de busca: `hash_hmac('sha256', $cpf, chave HMAC)` em `_cpf_hash`. A mesma chave HMAC assina as URLs de download.
- **As chaves ficam fora do banco de dados**: uma cópia do banco sozinha não decifra os CPFs. Ordem de procura (`Festa\Crypto\Crypto`):
  1. constantes `FESTA_ENC_KEY` e `FESTA_HMAC_KEY` no `wp-config.php` (recomendado);
  2. arquivo `festa-chaves.php` na pasta privada dos documentos (outro caminho pode ser definido pela constante `FESTA_KEYS_FILE`). Ele é criado na ativação com chaves aleatórias, fica protegido como os uploads e começa com `defined('ABSPATH') || exit`, então abri-lo direto pelo navegador não mostra nada. A gravação é atômica: duas ativações simultâneas nunca geram chaves diferentes.
- Se o banco tiver chaves nas opções `festa_enc_key` e `festa_hmac_key`, a ativação/atualização copia os mesmos valores para o arquivo e só então apaga as opções; os CPFs já cifrados continuam legíveis. Uma opção com valor diferente da chave em uso é mantida no banco e avisada no log de erros, para conferência manual.
- A desinstalação não apaga as chaves (sem elas os CPFs gravados ficam ilegíveis); removê-las é decisão do DPO.
- **Backup:** o arquivo de chaves (ou as constantes) deve entrar no backup, guardado separado do backup do banco.
- O CPF nunca aparece em logs, títulos, exportações ou consultas em texto. Nas telas, sempre mascarado.

### 6.2 Proteção de Uploads
- Diretório fora da raiz do site (`dirname(ABSPATH)/festa-uploads-privados`), com `.htaccess` (Apache 2.2/2.4), `web.config` (IIS) e `index.php`, regravados a cada ativação/atualização.
- Um subdiretório por inscrição; nomes UUID v4.
- Entrega só via PHP: URL assinada + sessão dona (participante) ou permissão + nonce/sessão (equipe). `realpath()` impede directory traversal.
- Documentos HTML gerados saem com `Content-Security-Policy: default-src 'none'`.

### 6.3 Validação de arquivos
- Tipo identificado pela **assinatura do conteúdo** (PDF, JPG, PNG, WEBP, HEIC), ignorando extensão e `Content-Type` do navegador.
- Limite de 10 MB por arquivo (ou o do servidor, se menor).

### 6.4 Auditoria
- Tabela `{prefix}festa_audit_log`: inscrição, usuário WP ou nome da conta do portal/"Participante", ação, detalhe (sem dados pessoais), IP anonimizado, data.
- Registra transições (com a justificativa do indeferimento, o motivo da reabertura e a chamada da fila), documentos, tenda, validações, observações, contato, senhas, exportações, exclusões, configurações (inclusive a mudança do limite de vagas) e anonimizações.
- O portal externo tem ainda o próprio registro de acessos e recusas (180 dias), também com IP anonimizado.

### 6.5 Retenção
- Ferramenta de anonimização em lote para inscrições `concluido` ou `indeferido` criadas há mais de 365 dias. Além de nome, CPF, e-mail e telefone, apaga os textos livres da equipe (observações, justificativa do indeferimento, motivo da reabertura) e troca esses textos na auditoria pelo marcador de anonimização.
- Base legal LGPD (Art. 7º, II): execução de contrato/procedimento preliminar (participação no evento). O aceite do edital e do tratamento dos dados fica registrado em `_aceite_em`.
