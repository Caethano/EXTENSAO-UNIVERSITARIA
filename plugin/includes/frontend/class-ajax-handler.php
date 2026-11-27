<?php

declare(strict_types=1);

namespace Festa\Frontend;

use Festa\Audit\AuditLog;
use Festa\Cpt\CptInscricao;
use Festa\Crypto\Crypto;
use Festa\Fields\Fields;
use Festa\Pages\Pages;
use Festa\Pdf\Generator;
use Festa\Security\SecurityManager;
use Festa\Settings\Settings;
use Festa\Status\StateMachine;
use Festa\Status\Vagas;
use Festa\Upload\Handler;

/**
 * Handlers do front-end — arquitetura de autenticação em silo.
 *
 * Participantes não são usuários WP. Toda autenticação passa pelo AuthManager.
 *
 * Endpoints:
 *  - festa_auth_ini_inscricao   (nopriv) — Pré-cadastro: cria CPT + senha_hash.
 *  - festa_auth_login           (nopriv) — Login: valida CPF+senha, inicia sessão.
 *  - festa_auth_logout          (nopriv) — Logout: invalida sessão silo.
 *  - festa_auth_trocar_senha    (nopriv) — Troca da senha pelo participante.
 *  - festa_finalizar_inscricao  (nopriv) — Finalização: salva campos + transita status.
 *  - festa_upload_documentos    (nopriv) — Upload de comprovantes do participante.
 *  - festa_refresh_nonce        (nopriv) — Renova o nonce de páginas abertas há muito tempo.
 *
 * Cada endpoint atende dois caminhos com a mesma lógica: AJAX (admin-ajax.php,
 * resposta JSON) e envio tradicional sem JavaScript (admin-post.php, volta
 * para a página com o resultado).
 */
final class AjaxHandler
{
    private const NONCE = 'festa_auth_nonce';

    // -----------------------------------------------------------------------
    // Bootstrap
    // -----------------------------------------------------------------------

    public static function boot_hooks(): void
    {
        $nopriv = static function (string $action, string $method): void {
            add_action('wp_ajax_nopriv_' . $action, [self::class, $method]);
            add_action('wp_ajax_'        . $action, [self::class, $method]);
            // Envio sem JavaScript (POST tradicional para admin-post.php).
            add_action('admin_post_nopriv_' . $action, [self::class, $method]);
            add_action('admin_post_'        . $action, [self::class, $method]);
        };

        $nopriv('festa_auth_ini_inscricao',  'ini_inscricao');
        $nopriv('festa_auth_login',          'login');
        $nopriv('festa_auth_logout',         'logout');
        $nopriv('festa_auth_trocar_senha',   'trocar_senha');
        $nopriv('festa_finalizar_inscricao', 'finalizar_inscricao');
        $nopriv('festa_upload_documentos',   'upload_documentos');

        add_action('wp_ajax_nopriv_festa_refresh_nonce', [self::class, 'refresh_nonce']);
        add_action('wp_ajax_festa_refresh_nonce',        [self::class, 'refresh_nonce']);
    }

    public static function refresh_nonce(): void
    {
        wp_send_json_success(['nonce' => wp_create_nonce(self::NONCE)]);
    }

    // -----------------------------------------------------------------------
    // Entrada e saída comuns
    // -----------------------------------------------------------------------

    /**
     * Confere o envio antes de qualquer endpoint: tamanho, nonce e, quando o
     * endpoint exige sessão, a sessão silo e o token dela.
     *
     * @return int ID da inscrição autenticada (0 quando $needs_session é false).
     */
    private static function begin(bool $needs_session = false): int
    {
        // Quando o envio passa de post_max_size, o PHP descarta o corpo
        // inteiro: $_POST e $_FILES chegam vazios (inclusive o nonce).
        if ((int) ($_SERVER['CONTENT_LENGTH'] ?? 0) > 0 && empty($_POST) && empty($_FILES)) {
            $max = Handler::max_post_bytes();
            self::fail(
                'Os arquivos enviados, somados, passam do limite aceito pelo servidor'
                . ($max ? ' (' . Handler::human_size($max) . ')' : '')
                . '. Envie fotos menores ou PDFs mais leves e tente novamente.',
                413
            );
        }

        if (! wp_verify_nonce(self::post('nonce'), self::NONCE)) {
            self::fail('Sessão expirada. Recarregue a página e tente novamente.', 403, '', 'nonce');
        }

        if (! $needs_session) {
            return 0;
        }

        $inscricao_id = AuthManager::get_current_inscricao_id();
        if ($inscricao_id === null) {
            self::fail('Sua sessão terminou. Entre novamente com o CPF e a senha.', 401);
        }
        if (! AuthManager::check_csrf(self::post('festa_csrf'))) {
            self::fail('Não foi possível confirmar o envio. Recarregue a página e tente novamente.', 403);
        }

        return $inscricao_id;
    }

    /** Campo de texto do POST, sem as barras que o WordPress acrescenta. */
    private static function post(string $key): string
    {
        $value = $_POST[$key] ?? '';

        return is_string($value) ? wp_unslash($value) : '';
    }

    /** Para onde voltar no envio sem JavaScript: sempre uma página deste site. */
    private static function back_url(): string
    {
        $url = wp_validate_redirect(esc_url_raw(self::post('festa_redirect')), home_url('/'));

        return remove_query_arg(['festa_ok', 'festa_erro', 'festa_sig'], $url);
    }

    /** @param array<string, mixed> $data */
    private static function ok(array $data): never
    {
        if (wp_doing_ajax()) {
            wp_send_json_success($data);
        }

        wp_safe_redirect(add_query_arg('festa_ok', '1', (string) ($data['redirect'] ?? self::back_url())));
        exit;
    }

    private static function fail(string $message, int $status = 422, string $field = '', string $code = ''): never
    {
        if (wp_doing_ajax()) {
            wp_send_json_error(['message' => $message, 'field' => $field, 'code' => $code], $status);
        }

        // A mensagem vai assinada: a página só mostra avisos emitidos pelo site.
        wp_safe_redirect(add_query_arg([
            'festa_erro' => rawurlencode($message),
            'festa_sig'  => Ui::notice_signature($message),
        ], self::back_url()));
        exit;
    }

    /** ID da inscrição ativa com este CPF (pelo hash), ou 0. */
    private static function find_by_cpf_hash(string $cpf_hash): int
    {
        $query = new \WP_Query([
            'post_type'      => CptInscricao::POST_TYPE,
            'post_status'    => 'publish',
            'meta_query'     => [['key' => '_cpf_hash', 'value' => $cpf_hash]],
            'fields'         => 'ids',
            'posts_per_page' => 1,
            'no_found_rows'  => true,
        ]);

        return $query->have_posts() ? (int) $query->posts[0] : 0;
    }

    // -----------------------------------------------------------------------
    // Endpoint: festa_auth_ini_inscricao
    // Pré-cadastro — cria o CPT inscricao_festa com status rascunho e uma
    // senha de acesso sorteada, mostrada uma única vez.
    // -----------------------------------------------------------------------

    public static function ini_inscricao(): void
    {
        self::begin();

        // Honeypot: bots preenchem, humanos não veem.
        if (self::post('festa_hp') !== '') {
            self::fail('Envio inválido.', 400);
        }
        if (! Settings::is_open()) {
            self::fail('As inscrições estão encerradas.', 403);
        }

        // Sanitização
        $cpf_raw = Fields::digits(sanitize_text_field(self::post('cpf')));
        $nome    = sanitize_text_field(self::post('nome'));

        // Validação
        if (! Fields::valid_cpf($cpf_raw)) {
            self::fail('CPF inválido — confira os números digitados.', 422, 'cpf');
        }
        if (mb_strlen($nome) < 3) {
            self::fail('Informe o nome completo do responsável.', 422, 'nome');
        }

        // Rate limit por IP anonimizado ANTES de dizer se o CPF já existe:
        // a resposta "já existe" revela quem está inscrito, então consultá-la
        // também conta como tentativa.
        $ip = AuditLog::request_ip();
        try {
            SecurityManager::check_rate_limit($ip);
        } catch (\RuntimeException) {
            self::fail('Muitos pré-cadastros a partir desta conexão. Aguarde alguns minutos e tente de novo.', 429);
        }

        // Unicidade via HMAC do CPF — nunca plaintext em query
        $cpf_hash = Crypto::hash($cpf_raw);
        $existe   = 'Já existe uma inscrição para este CPF. Entre com o CPF e a senha para continuar.';
        if (self::find_by_cpf_hash($cpf_hash) > 0) {
            self::fail($existe, 409, 'cpf');
        }

        // Dois envios simultâneos do mesmo CPF: só o primeiro cria a inscrição.
        if (! SecurityManager::acquire_lock('cpf_' . $cpf_hash)) {
            self::fail($existe, 409, 'cpf');
        }

        // Criar CPT (sem post_author — participantes não são usuários WP)
        $post_id = wp_insert_post([
            'post_type'   => CptInscricao::POST_TYPE,
            'post_title'  => $nome,
            'post_status' => 'publish',
            'post_author' => 0,
        ], true);

        if (is_wp_error($post_id)) {
            SecurityManager::release_lock('cpf_' . $cpf_hash);
            self::fail('Erro interno ao criar inscrição. Tente novamente.', 500);
        }

        // Persistir metas
        $metas = [
            '_cpf'              => Crypto::encrypt($cpf_raw),
            '_cpf_hash'         => $cpf_hash,
            '_nome_responsavel' => $nome,
            '_status'           => StateMachine::STATUS_RASCUNHO,
            '_data_inscricao'   => wp_date('d/m/Y \à\s H:i'),
            '_ip_inscricao'     => $ip,
        ];
        foreach ($metas as $key => $value) {
            update_post_meta($post_id, $key, $value);
        }

        $senha = AuthManager::generate_password();
        AuthManager::set_password($post_id, $senha, true);

        SecurityManager::release_lock('cpf_' . $cpf_hash);

        // Quem acabou de se cadastrar já segue para a inscrição sem digitar a senha.
        AuthManager::login($post_id);
        AuditLog::record($post_id, 'pre_cadastro', 'Pré-cadastro realizado.');

        // Sem JavaScript não há tela de confirmação: a senha é mostrada na
        // próxima página, uma única vez.
        if (! wp_doing_ajax()) {
            AuthManager::flash_set('senha', $senha);
        }

        self::ok([
            'message' => 'Pré-cadastro realizado! Guarde a sua senha de acesso.',
            'senha'   => $senha,
        ]);
    }

    // -----------------------------------------------------------------------
    // Endpoint: festa_auth_login
    // Valida CPF + senha e inicia sessão silo via AuthManager.
    // -----------------------------------------------------------------------

    public static function login(): void
    {
        self::begin();

        $ip      = AuditLog::request_ip();
        $cpf_raw = Fields::digits(sanitize_text_field(self::post('cpf')));
        $senha   = self::post('senha');

        if (strlen($cpf_raw) !== 11 || $senha === '') {
            self::fail('Informe CPF e senha.');
        }

        // A tentativa é reservada antes de conferir a senha (contadores atômicos).
        $cpf_hash  = Crypto::hash($cpf_raw);
        $bloqueio  = AuthManager::reserve_attempt($cpf_hash, $ip);
        $post_id   = self::find_by_cpf_hash($cpf_hash);
        $usable    = $bloqueio === '' && $post_id > 0;

        if ($bloqueio === 'ip') {
            self::fail('Muitas tentativas de login. Aguarde 15 minutos.', 429);
        }

        // A resposta é a mesma (e leva o mesmo tempo) para CPF sem inscrição,
        // inscrição bloqueada e senha errada.
        if (! $usable) {
            wp_hash_password($senha);
        }

        if (! $usable || ! AuthManager::verify_password($post_id, $senha)) {
            self::fail(sprintf(
                'CPF ou senha inválidos. Após %d tentativas erradas, o acesso fica bloqueado por %d minutos.',
                AuthManager::MAX_FAILS,
                AuthManager::LOCK_SECONDS / MINUTE_IN_SECONDS
            ), 401);
        }

        // Credenciais corretas: devolver a reserva e iniciar sessão
        AuthManager::attempt_succeeded($cpf_hash, $ip);
        AuthManager::login($post_id);

        self::ok(['message' => 'Login realizado com sucesso.']);
    }

    // -----------------------------------------------------------------------
    // Endpoint: festa_auth_logout
    // Encerra a sessão silo do participante.
    // -----------------------------------------------------------------------

    public static function logout(): void
    {
        self::begin();
        AuthManager::logout();
        self::ok(['message' => 'Sessão encerrada.']);
    }

    // -----------------------------------------------------------------------
    // Endpoint: festa_auth_trocar_senha
    // O participante troca a senha recebida no pré-cadastro por uma sua.
    // -----------------------------------------------------------------------

    public static function trocar_senha(): void
    {
        $post_id  = self::begin(true);
        $cpf_hash = (string) get_post_meta($post_id, '_cpf_hash', true);
        $ip       = AuditLog::request_ip();

        // Conta como tentativa de senha: quem pegou uma sessão aberta não
        // pode ficar testando a senha atual à vontade.
        if (AuthManager::reserve_attempt($cpf_hash, $ip) !== '') {
            AuthManager::logout();
            self::fail('Muitas tentativas erradas. O acesso ficou bloqueado por alguns minutos.', 401);
        }
        if (! AuthManager::verify_password($post_id, self::post('senha_atual'))) {
            self::fail('A senha atual não confere.', 422, 'senha_atual');
        }
        AuthManager::attempt_succeeded($cpf_hash, $ip);

        $nova    = self::post('senha_nova');
        $cpf_raw = '';
        try {
            $cpf_raw = Crypto::decrypt((string) get_post_meta($post_id, '_cpf', true));
        } catch (\Throwable) {
            // Sem o CPF, a regra "a senha não pode ser o CPF" apenas não se aplica.
        }

        $problem = AuthManager::password_problem($nova, $cpf_raw);
        if ($problem === '' && $nova !== self::post('senha_nova2')) {
            $problem = 'A confirmação não é igual à nova senha.';
        }
        if ($problem === '' && AuthManager::verify_password($post_id, $nova)) {
            $problem = 'A nova senha precisa ser diferente da atual.';
        }
        if ($problem !== '') {
            self::fail($problem, 422, 'senha_nova');
        }

        // A troca encerra todas as sessões abertas com a senha anterior; este
        // navegador recebe uma nova, com o mesmo token CSRF da página aberta.
        $csrf = AuthManager::csrf_token();
        AuthManager::set_password($post_id, $nova, false);
        AuthManager::login($post_id, $csrf);
        AuditLog::record($post_id, 'senha', 'Senha de acesso alterada pelo participante.');

        self::ok(['message' => 'Senha alterada.']);
    }

    // -----------------------------------------------------------------------
    // Endpoint: festa_finalizar_inscricao
    // Salva campos restantes e transita para aguardando_analise.
    // Requer sessão silo ativa (AuthManager).
    // -----------------------------------------------------------------------

    public static function finalizar_inscricao(): void
    {
        $post_id = self::begin(true);

        // Garante que o status ainda é rascunho
        if (StateMachine::current($post_id) !== StateMachine::STATUS_RASCUNHO) {
            self::fail('Esta inscrição já foi finalizada anteriormente.', 409);
        }
        if (! Settings::is_open()) {
            self::fail('As inscrições estão encerradas.', 403);
        }

        // Sanitização
        $email      = sanitize_email(self::post('email'));
        $telefone   = sanitize_text_field(self::post('telefone'));
        $nome_grupo = sanitize_text_field(self::post('grupo'));
        $qtd        = absint(self::post('qtd_participantes'));
        $coccao     = sanitize_key(self::post('coccao'));
        $espaco     = (int) self::post('espaco');

        // Validação — cada erro aponta o campo que precisa de correção
        if (! is_email($email)) {
            self::fail('Informe um e-mail válido.', 422, 'email');
        }
        if (! Fields::valid_phone($telefone)) {
            self::fail('Telefone inválido — use o formato (00) 00000-0000.', 422, 'telefone');
        }
        if ($nome_grupo === '') {
            self::fail('Informe o nome do grupo.', 422, 'grupo');
        }
        if ($qtd < 1 || $qtd > 500) {
            self::fail('Informe o número de participantes (de 1 a 500).', 422, 'qtd_participantes');
        }
        if (Fields::coccao_label($coccao) === '') {
            self::fail('Escolha o tipo de cocção.', 422, 'coccao');
        }
        if (Fields::espaco_label($espaco) === '') {
            self::fail('Escolha o espaço necessário.', 422, 'espaco');
        }

        // Documentos (identidade e comprovante de residência) são opcionais,
        // mas o que for enviado precisa ser válido — antes de gravar qualquer coisa.
        $uploads = [];
        foreach (['identidade', 'comprovante_residencia'] as $field) {
            $file = Handler::uploaded($field);
            if ($file === null) {
                continue;
            }
            $doc   = Fields::documents()[$field];
            $check = Handler::check($file, $doc['accept'], $doc['label']);
            if (is_string($check)) {
                self::fail($check, 422, $field);
            }
            $uploads[$field] = $file;
        }

        // Aceite do edital e da LGPD: sem ele a inscrição não é registrada.
        if (self::post('aceite') === '') {
            self::fail('Para enviar, é preciso aceitar os termos do edital e o tratamento dos dados (LGPD).', 422, 'aceite');
        }

        // Salvar metas complementares
        $telefone = Fields::format_phone($telefone);
        $metas    = [
            '_email'             => $email,
            '_telefone'          => $telefone,
            '_nome_grupo'        => $nome_grupo,
            '_qtd_participantes' => $qtd,
            '_coccao'            => $coccao,
            '_espaco'            => $espaco,
            '_aceite_em'         => current_time('mysql'),
        ];
        foreach ($metas as $key => $value) {
            update_post_meta($post_id, $key, $value);
        }

        // Atualizar o título do post com o nome do grupo (melhora legibilidade no admin)
        wp_update_post([
            'ID'         => $post_id,
            'post_title' => sanitize_text_field($nome_grupo . ' — ' . wp_date('Y')),
        ]);

        foreach ($uploads as $field => $file) {
            try {
                Handler::handle_upload($file, $post_id, Fields::documents()[$field]['meta']);
                AuditLog::record($post_id, 'documento', 'Documento enviado: ' . Fields::document_label($field) . '.');
            } catch (\RuntimeException $e) {
                // Não-fatal: a Comissão pode solicitar o reenvio durante a análise
                error_log('[Festa AjaxHandler] Erro ao salvar documento: ' . $e->getMessage());
            }
        }

        // Gerar Ficha de Inscrição (HTML)
        try {
            $nome_responsavel = (string) get_post_meta($post_id, '_nome_responsavel', true);
            $cpf_enc          = (string) get_post_meta($post_id, '_cpf', true);
            $cpf_masked       = '';
            try {
                $cpf_masked = Crypto::mask_cpf(Crypto::decrypt($cpf_enc));
            } catch (\Throwable) {
                $cpf_masked = '***.***.***-**';
            }

            $ficha_data = [
                'inscricao_id'      => $post_id,
                'nome_responsavel'  => $nome_responsavel,
                'cpf_masked'        => $cpf_masked,
                'email'             => $email,
                'telefone'          => $telefone,
                'nome_grupo'        => $nome_grupo,
                'qtd_participantes' => $qtd,
                'coccao'            => Fields::coccao_label($coccao),
                'espaco'            => $espaco,
                'data_inscricao'    => wp_date('d/m/Y \à\s H:i'),
                'data_emissao'      => wp_date('d/m/Y \à\s H:i'),
            ];
            Generator::generate('ficha-inscricao', $ficha_data, $post_id, '_upload_ficha_gerada');
        } catch (\RuntimeException $e) {
            error_log('[Festa AjaxHandler] Erro ao gerar ficha: ' . $e->getMessage());
            // Não aborta a finalização — a comissão pode gerar manualmente
        }

        // Transição de status: rascunho → aguardando_analise, ou → lista_espera
        // quando há limite de vagas e ele já foi atingido.
        $destino = Vagas::status_finalizacao();
        try {
            StateMachine::transition($post_id, $destino, 0);
        } catch (\LogicException | \RuntimeException $e) {
            self::fail('Dados salvos, mas erro na transição de status: ' . $e->getMessage(), 500);
        }

        if ($destino === StateMachine::STATUS_LISTA_ESPERA) {
            $posicao = Vagas::posicao($post_id);
            // titulo/texto substituem a confirmação padrão da página (data-festa-fill).
            self::ok([
                'status'   => $destino,
                'posicao'  => $posicao,
                'message'  => 'Inscrição finalizada e colocada na lista de espera.',
                'titulo'   => 'Inscrição na lista de espera',
                'texto'    => sprintf(
                    'As vagas estão esgotadas: sua inscrição foi recebida e entrou na lista de espera, na posição %dº. '
                    . 'Se uma vaga for liberada, você será avisado por e-mail. Acompanhe pelo painel.',
                    $posicao
                ),
                'redirect' => Pages::url('painel'),
            ]);
        }

        self::ok([
            'status'   => $destino,
            'message'  => 'Inscrição finalizada com sucesso! Acompanhe o status no painel.',
            'redirect' => Pages::url('painel'),
        ]);
    }

    // -----------------------------------------------------------------------
    // Endpoint: festa_upload_documentos
    // Upload de comprovantes. Ownership verificada via AuthManager.
    // -----------------------------------------------------------------------

    public static function upload_documentos(): void
    {
        $session_id = self::begin(true);

        $post_id  = absint(self::post('inscricao_id'));
        $doc_type = sanitize_key(self::post('document_type'));

        // Ownership: a sessão deve corresponder ao post_id solicitado
        if ($session_id !== $post_id) {
            self::fail('Acesso não autorizado.', 403);
        }

        $meta_map = [
            'ficha_assinada' => '_upload_ficha_assinada',
            'recibo_tenda'   => '_upload_recibo_tenda',
        ];

        if (! isset($meta_map[$doc_type])) {
            self::fail('Tipo de documento inválido.', 400);
        }

        // A ficha disputa a inscrição com as ações da Comissão (indeferir,
        // chamar da fila): conferência de status, gravação e transição ficam
        // dentro da mesma trava, e o status é relido depois de travar. Um
        // indeferimento simultâneo não deixa arquivo novo numa inscrição
        // indeferida. (A trava é liberada pelo banco se a requisição parar.)
        if ($doc_type === 'ficha_assinada') {
            if (! Vagas::travar()) {
                self::fail('A inscrição está sendo atualizada pela Comissão neste momento. Tente de novo em instantes.', 503);
            }
            wp_cache_delete($post_id, 'post_meta');
        }

        // Cada documento só é aceito na fase em que ele faz parte do fluxo.
        // Indeferida, a inscrição não recebe nada: só a Comissão a reabre.
        $status = StateMachine::current($post_id);
        if ($status === StateMachine::STATUS_INDEFERIDO) {
            self::fail('Esta inscrição foi indeferida pela Comissão e não recebe novos envios.', 409);
        }
        if (
            $doc_type === 'ficha_assinada'
            && ! in_array($status, [StateMachine::STATUS_RASCUNHO, StateMachine::STATUS_ANALISE, StateMachine::STATUS_LISTA_ESPERA], true)
        ) {
            self::fail('A ficha desta inscrição já foi analisada pela Comissão.', 409);
        }
        if ($doc_type === 'recibo_tenda') {
            if ($status !== StateMachine::STATUS_COMPROVANTES) {
                self::fail('O recibo da tenda só pode ser enviado depois do sorteio.', 409);
            }
            if ((bool) get_post_meta($post_id, '_tenda_aprovada', true)) {
                self::fail('O recibo da tenda já foi aprovado pela Comissão.', 409);
            }
        }

        $file = Handler::uploaded('documento');
        if ($file === null) {
            self::fail('Escolha o arquivo do documento.', 422, 'documento');
        }

        try {
            Handler::handle_upload($file, $post_id, $meta_map[$doc_type]);
        } catch (\RuntimeException $e) {
            self::fail($e->getMessage(), 422, 'documento');
        }

        AuditLog::record($post_id, 'documento', 'Documento enviado: ' . Fields::document_label($doc_type) . '.');

        // Ficha assinada → transita para aguardando_analise (se ainda em
        // rascunho), ou para a lista de espera com as vagas esgotadas.
        if ($doc_type === 'ficha_assinada' && $status === StateMachine::STATUS_RASCUNHO) {
            try {
                StateMachine::transition($post_id, Vagas::status_finalizacao(), 0);
            } catch (\LogicException | \RuntimeException $e) {
                self::fail('Arquivo salvo, mas erro na transição de status: ' . $e->getMessage(), 500);
            }
        }

        Vagas::destravar();
        self::ok(['message' => 'Documento enviado com sucesso!']);
    }
}
