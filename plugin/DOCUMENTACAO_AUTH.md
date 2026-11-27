# Arquitetura de Autenticação em Silo — Festa dos Amigos 2026

> Atualizado para a versão 2.0.0.

## 1. Visão Geral

Os participantes do evento **não são usuários do WordPress** (`wp_users`). Toda a identidade de um participante é um CPT `inscricao_festa`. O acesso às páginas do participante é controlado por um sistema de sessão próprio, baseado em:

- **Token aleatório** gerado via `bin2hex(random_bytes(32))` — 64 caracteres hexadecimais, imprevisíveis.
- **Transient do WordPress** (`festa_sess_{sha256 do token}`) mapeando para o `post_id` da inscrição, com TTL de 2 horas. O servidor guarda **só o hash** do token.
- **Cookie HttpOnly** (`festa_auth_token`) entregue ao navegador do participante com os flags `Secure` (em HTTPS) e `SameSite=Lax`.

Nenhuma sessão PHP (`$_SESSION`) é utilizada. O WordPress não "loga" o participante; a identidade é resolvida internamente pelo `AuthManager`.

A equipe (Comissão e Defesa Civil) **não** usa este mecanismo: entra pelo wp-admin (roles do WordPress) ou pelo portal externo (contas próprias — ver `Festa\Portal`).

---

## 2. Por que Silo (sem `wp_users`)?

| Aspecto | WP Users | Silo (este projeto) |
|---|---|---|
| Escopo de permissão | Roles/caps do WP inteiras | Zero — participante só acessa o próprio CPT |
| Complexidade LGPD | Dados replicados em múltiplas tabelas WP | Dados concentrados em `postmeta` |
| Vazamento de atacante | Login WP expõe o dashboard | Nenhuma rota admin disponível |
| Número de usuários | Pode crescer sem limite na `wp_users` | Sem poluição da tabela de usuários |

---

## 3. Fluxo em Duas Etapas

### Etapa 1 — Pré-Cadastro via `[ini_inscricao]`

```
Participante informa CPF + Nome
        ↓
festa_auth_ini_inscricao
        ↓
Nonce → honeypot → inscrições abertas?
Valida o CPF (11 dígitos + dígitos verificadores)
Rate limit por rede (10 envios válidos / 10 min) — ANTES da unicidade:
  a resposta "já existe" também conta, para não revelar em massa quem está inscrito
Verifica unicidade via HMAC-SHA256 do CPF (_cpf_hash) + trava contra envio duplo
        ↓
Cria CPT inscricao_festa (post_status=publish, status=rascunho)
Salva metas:
  _cpf              → AES-256-CBC (Crypto::encrypt)
  _cpf_hash         → HMAC-SHA256 (Crypto::hash) — índice de busca
  _nome_responsavel → plaintext sanitizado
  _senha_hash       → wp_hash_password(senha sorteada)
  _senha_provisoria → 1
  _status           → 'rascunho'
  _data_inscricao   → data/hora
  _ip_inscricao     → IP anonimizado (LGPD)
        ↓
AuthManager::login() — o participante já sai autenticado
Resposta com a senha → mostrada UMA única vez na tela de confirmação
```

A senha é sorteada por `AuthManager::generate_password()`: 12 caracteres de um alfabeto sem os que se confundem (0/O, 1/l/I), em três grupos (`xxxx-xxxx-xxxx`, ~69 bits).

`verify_password()` recusa senhas com menos de 8 caracteres. Inscrições sem senha válida aparecem com um aviso na tela da Comissão, que gera uma senha nova para o responsável.

### Etapa 2 — Finalização via `[festa_inscricao]`

```
Participante acessa a página de inscrição
        ↓
AuthManager::get_current_inscricao_id() → null?
   ├─ SIM → Exibe formulário de Login (CPF + Senha)
   │         festa_auth_login → AuthManager::login() → recarrega página
   └─ NÃO → status == 'rascunho'?
              ├─ SIM → Exibe formulário de finalização
              │         (E-mail, Telefone, Grupo, Qtd, Cocção, Espaço, Uploads, Aceite)
              │         festa_finalizar_inscricao → salva metas + gera a ficha
              │                                   + transita para aguardando_analise
              └─ NÃO → Exibe aviso: "Inscrição já enviada, acesse o Painel"
```

### Acompanhamento via `[festa_painel]`

```
Participante acessa o painel
        ↓
AuthManager::get_current_inscricao_id() → null?
   ├─ SIM → Exibe formulário de Login (mesmo form do [festa_inscricao])
   └─ NÃO → Renderiza a etapa conforme o _status atual
              + envio de documentos da fase (ficha assinada, recibo)
              + "Trocar a senha de acesso"
              + Botão "Sair" (festa_auth_logout → AuthManager::logout() → recarrega)
```

---

## 4. Mecânica da Sessão

### Armazenamento no servidor

```php
set_transient('festa_sess_' . hash('sha256', $token), [
    'id'   => $inscricao_id,
    'ua'   => hash('sha256', $user_agent),   // navegador que abriu a sessão
    'csrf' => bin2hex(random_bytes(32)),     // token dos formulários autenticados
    'gen'  => $geracao,                      // meta _sessao_geracao no momento do login
], 2 * HOUR_IN_SECONDS);
```

O token em claro existe só no cookie. Uma cópia do banco não permite assumir sessões abertas.

Toda troca de senha (`AuthManager::set_password()`, pelo participante ou pela equipe) incrementa o meta `_sessao_geracao` da inscrição: as sessões abertas antes deixam de valer. Na troca feita pelo próprio participante, o navegador dele recebe na hora uma sessão nova, com o mesmo token CSRF — a página aberta continua funcionando.

### Cookie do navegador

| Flag | Efeito |
|---|---|
| `httponly` | JavaScript não pode ler o cookie — mitiga XSS |
| `secure` | Cookie só trafega em HTTPS — mitiga sniffing |
| `samesite=Lax` | Cookie não enviado em POSTs cross-site — mitiga CSRF, e ainda acompanha o clique no link do e-mail |

### Resolução de identidade

```
Cookie festa_auth_token presente e no formato esperado (64 hex)?
  → get_transient('festa_sess_' . sha256(token))
    → ausente?                         → null (sessão expirada ou inválida)
    → User-Agent diferente do gravado? → sessão destruída → null
    → inscrição excluída?              → sessão destruída → null
    → senha trocada depois do login?   → sessão destruída → null
    → ok                               → $inscricao_id
```

### Proteção dos envios

Cada endpoint confere, nesta ordem: tamanho do corpo (envio acima de `post_max_size`), **nonce** do WordPress e — nos endpoints autenticados — a **sessão** e o **token CSRF da sessão** (campo `festa_csrf`). O nonce de páginas abertas há muito tempo é renovado pelo JavaScript (`festa_refresh_nonce`) e o envio é repetido.

### Logout

```php
AuthManager::logout();
// → delete_transient('festa_sess_' . sha256(token))  (invalida servidor)
// → setcookie('festa_auth_token', '', expires: passado)  (expira cliente)
```

---

## 5. Senhas e bloqueios

| Situação | Como funciona |
|---|---|
| Armazenamento | `wp_hash_password()` em `_senha_hash`; nunca em texto |
| Verificação | `wp_check_password()`; a resposta (e o tempo) é a mesma para CPF sem inscrição, inscrição bloqueada e senha errada |
| Troca pelo participante | `festa_auth_trocar_senha`: exige a senha atual; mínimo de 8 caracteres, não pode ser o CPF nem uma senha óbvia |
| Senha perdida | Não há "esqueci minha senha". A equipe gera uma nova na tela da inscrição ("Gerar nova senha"), mostrada uma única vez (guardada cifrada até a tela abrir) |
| Bloqueio da inscrição | 5 senhas erradas a partir da mesma rede bloqueiam a inscrição **para essa rede** por 15 minutos — quem erra a senha de outra pessoa não tranca o dono. Teto de 50 erros somando todas as redes. Gerar nova senha destrava |
| Bloqueio por rede | 30 erros em 15 minutos a partir do mesmo IP anonimizado |
| Tentativas simultâneas | Cada tentativa é reservada antes de conferir a senha, num contador atômico (`SecurityManager::hit()`, tabela `festa_counters`); as que passam do limite nem testam a senha. Login certo devolve a reserva |

---

## 6. Endpoints

Todos atendem dois caminhos com a mesma lógica: **AJAX** (`admin-ajax.php`, resposta JSON com `message`, `field` e `code`) e **envio tradicional sem JavaScript** (`admin-post.php`, redireciona de volta para a página com `?festa_ok=1` ou `?festa_erro=…`).

| Action | Autenticação | Responsabilidade |
|---|---|---|
| `festa_auth_ini_inscricao` | nonce + honeypot | Pré-cadastro: cria CPT + senha sorteada + sessão |
| `festa_auth_login` | nonce | Login: valida CPF+senha, inicia sessão |
| `festa_auth_logout` | nonce | Logout: invalida token + cookie |
| `festa_auth_trocar_senha` | nonce + sessão + CSRF | Troca da senha pelo participante |
| `festa_finalizar_inscricao` | nonce + sessão + CSRF | Salva campos restantes, gera a ficha, transita status |
| `festa_upload_documentos` | nonce + sessão + CSRF | Upload de `ficha_assinada` e `recibo_tenda`, cada um na sua fase |
| `festa_refresh_nonce` | — | Devolve um nonce novo |
| `festa_download_secure` | URL assinada + sessão (ou equipe) | Entrega um documento da inscrição |

---

## 7. Páginas

As páginas são criadas na ativação (`Festa\Pages\Pages`), e os endereços usados nos links e nos e-mails vêm de `Pages::url()` — podem ser alterados no editor sem tocar no código.

| Página | Shortcode | Endereço padrão |
|---|---|---|
| Pré-Cadastro | `[ini_inscricao]` | `/pre-inscricao/` |
| Finalizar Inscrição | `[festa_inscricao]` | `/inscricao/` |
| Painel do Participante | `[festa_painel]` | `/painel-inscricao/` |

O shortcode `[ini_inscricao]` aceita o atributo `inscricao_url` para apontar a página de finalização:

```
[ini_inscricao inscricao_url="/inscricao"]
```

---

## 8. Diagrama de Estado (State Machine)

```
[rascunho] ──(festa_finalizar_inscricao)──► [aguardando_analise]
                                                    │
                                         (Comissão defere: festa_deferir)
                                                    │
                                                    ▼
                                              [deferido]
                                                    │
                                (Comissão registra o sorteio: festa_tenda)
                                                    │
                                                    ▼
                                        [aguardando_comprovantes]
                  ┌─────────────────────────────────┤
                  │ Recibo aprovado (festa_recibo)   │ Colchões confirmados (festa_colchoes)
                  └─────────────────────────────────┘
                                                    │ (ambos confirmados)
                                                    ▼
                                              [concluido]
```
