<?php

declare(strict_types=1);

namespace Festa\Admin;

use Festa\Crypto\Crypto;
use Festa\Portal\Portal;
use Festa\Portal\Store;

/**
 * wp-admin → Festa dos Amigos → Portal externo.
 *
 * Só administradores do WordPress chegam aqui. É onde o portal é ligado ou
 * desligado e onde as contas do portal são criadas, alteradas, bloqueadas e
 * excluídas. Usuários do portal não gerenciam contas.
 */
final class PortalAdmin
{
    public const CAP   = 'manage_options';
    public const SLUG  = 'festa-portal';
    public const NONCE = 'festa_portal_admin';

    public static function boot_hooks()
    {
        add_action('admin_post_festa_portal_settings', [self::class, 'save_settings']);
        add_action('admin_post_festa_portal_user_create', [self::class, 'user_create']);
        add_action('admin_post_festa_portal_user_update', [self::class, 'user_update']);
        add_action('admin_post_festa_portal_user_op', [self::class, 'user_op']);
    }

    private static function guard()
    {
        if (! current_user_can(self::CAP)) {
            wp_die('Sem permissão.', 'Sem permissão', ['response' => 403]);
        }
        check_admin_referer(self::NONCE);
    }

    private static function page_url($args = [])
    {
        return add_query_arg($args, admin_url('admin.php?page=' . self::SLUG));
    }

    private static function back($msg, $args = [])
    {
        wp_safe_redirect(self::page_url(['pmsg' => $msg] + $args));
        exit;
    }

    private static function fields($action)
    {
        return '<input type="hidden" name="action" value="' . esc_attr($action) . '" />'
            . '<input type="hidden" name="_wpnonce" value="' . esc_attr(wp_create_nonce(self::NONCE)) . '" />';
    }

    /**
     * Registra no histórico do portal o que o administrador do WP fez.
     */
    private static function audit($text, $target = null)
    {
        $admin = wp_get_current_user();
        Store::log('admin', sprintf('%s (por %s, no wp-admin).', $text, $admin->user_login), $target);
    }

    /**
     * Guarda a senha provisória só até a próxima tela (no máximo 5 min),
     * para ser mostrada uma única vez — cifrada: o banco nunca tem a senha
     * em claro.
     */
    private static function flash_password($user, $senha)
    {
        set_transient(
            'festa_portal_flash_' . get_current_user_id(),
            [
                'nome'  => $user['nome'],
                'login' => $user['login'],
                'senha' => Crypto::encrypt($senha),
            ],
            5 * MINUTE_IN_SECONDS
        );
    }

    /* ---------------------------------------------------------------------
     * Ações
     * ------------------------------------------------------------------- */

    public static function save_settings()
    {
        self::guard();
        $current = Portal::settings();
        $enabled = empty($_POST['enabled']) ? 0 : 1;
        $slug    = isset($_POST['slug']) ? sanitize_title(wp_unslash($_POST['slug'])) : '';

        $reserved = ['wp-admin', 'wp-content', 'wp-includes', 'wp-json', 'wp-login', 'admin', 'login', 'feed', 'index'];
        if (strlen($slug) < 3 || strlen($slug) > 60 || in_array($slug, $reserved, true)) {
            self::back('slug_invalido');
        }
        if ($slug !== $current['slug'] && get_page_by_path($slug, OBJECT, ['page', 'post'])) {
            self::back('slug_em_uso');
        }

        update_option(
            Portal::OPTION,
            [
                'enabled' => $enabled,
                'slug'    => $slug,
            ]
        );

        if ((int) $current['enabled'] !== $enabled) {
            self::audit($enabled ? 'Portal ligado' : 'Portal desligado');
        }
        if ($slug !== $current['slug']) {
            self::audit('Endereço do portal alterado');
        }
        self::back('config');
    }

    public static function user_create()
    {
        self::guard();
        $in = wp_unslash($_POST);

        $result = Store::create_user(
            isset($in['nome']) ? (string) $in['nome'] : '',
            isset($in['login']) ? (string) $in['login'] : '',
            isset($in['email']) ? (string) $in['email'] : '',
            isset($in['perms']) ? (array) $in['perms'] : []
        );
        if (is_wp_error($result)) {
            set_transient('festa_portal_err_' . get_current_user_id(), $result->get_error_message(), MINUTE_IN_SECONDS);
            self::back('erro');
        }

        $user = Store::get_user($result['id']);
        self::flash_password($user, $result['senha']);
        self::audit('Conta criada', $user);
        self::back('criado');
    }

    public static function user_update()
    {
        self::guard();
        $in   = wp_unslash($_POST);
        $id   = isset($in['id']) ? absint($in['id']) : 0;
        $user = Store::get_user($id);
        if (! $user) {
            self::back('inexistente');
        }

        $nome = isset($in['nome']) ? sanitize_text_field($in['nome']) : '';
        if ('' === $nome) {
            set_transient('festa_portal_err_' . get_current_user_id(), 'Informe o nome da pessoa.', MINUTE_IN_SECONDS);
            self::back('erro', ['edit_user' => $id]);
        }

        $perms = Store::clean_perms(isset($in['perms']) ? (array) $in['perms'] : []);
        if (! $perms) {
            set_transient('festa_portal_err_' . get_current_user_id(), 'Marque ao menos uma permissão para a conta.', MINUTE_IN_SECONDS);
            self::back('erro', ['edit_user' => $id]);
        }
        Store::update_user(
            $id,
            [
                'nome'  => $nome,
                'email' => isset($in['email']) ? sanitize_email($in['email']) : '',
                'perms' => implode(',', $perms),
            ]
        );
        if ($perms !== $user['perms_list']) {
            self::audit('Permissões alteradas para: ' . implode(', ', $perms), $user);
        } else {
            self::audit('Dados da conta alterados', $user);
        }
        self::back('atualizado');
    }

    public static function user_op()
    {
        self::guard();
        $id   = isset($_POST['id']) ? absint($_POST['id']) : 0;
        $op   = isset($_POST['op']) ? sanitize_key($_POST['op']) : '';
        $user = Store::get_user($id);
        if (! $user) {
            self::back('inexistente');
        }

        switch ($op) {
            case 'reset':
                $senha = Store::generate_password();
                Store::set_password($id, $senha, true); // encerra as sessões
                self::flash_password($user, $senha);
                self::audit('Senha redefinida (provisória) e sessões encerradas', $user);
                self::back('senha');
                break;

            case 'sessions':
                Store::destroy_user_sessions($id);
                self::audit('Sessões encerradas', $user);
                self::back('sessoes');
                break;

            case 'unlock':
                Store::update_user(
                    $id,
                    [
                        'failed_count' => 0,
                        'locked_until' => 0,
                    ]
                );
                self::audit('Conta desbloqueada', $user);
                self::back('desbloqueado');
                break;

            case 'toggle':
                $ativo = $user['ativo'] ? 0 : 1;
                Store::update_user($id, ['ativo' => $ativo]);
                if (! $ativo) {
                    Store::destroy_user_sessions($id);
                }
                self::audit($ativo ? 'Conta reativada' : 'Conta desativada e sessões encerradas', $user);
                self::back($ativo ? 'ativado' : 'desativado');
                break;

            case 'delete':
                self::audit('Conta excluída', $user);
                Store::delete_user($id);
                self::back('excluido');
                break;
        }
        self::back('inexistente');
    }

    /* ---------------------------------------------------------------------
     * Tela
     * ------------------------------------------------------------------- */

    private static function perms_checkboxes($selected)
    {
        $html = '<fieldset class="festa-perms">';
        foreach (Ctx::perms() as $key => $label) {
            $html .= '<label><input type="checkbox" name="perms[]" value="' . esc_attr($key) . '" '
                . checked(in_array($key, $selected, true), true, false) . ' /> ' . esc_html($label) . '</label>';
        }
        return $html . '</fieldset>';
    }

    private static function user_state($user)
    {
        if (! $user['ativo']) {
            return ['off', 'Desativada'];
        }
        if ($user['locked_until'] > time()) {
            return ['lock', 'Bloqueada até ' . Store::format_ts($user['locked_until'], 'H:i')];
        }
        if ($user['must_change']) {
            return ['new', 'Aguardando 1º acesso'];
        }
        return ['ok', 'Ativa'];
    }

    public static function render()
    {
        if (! current_user_can(self::CAP)) {
            wp_die('Sem permissão.');
        }

        $settings = Portal::settings();
        $enabled  = Portal::enabled();
        $url      = Portal::url();
        $https    = Portal::is_https();
        $users    = Store::all_users();
        $labels   = Ctx::perms();
        $post     = admin_url('admin-post.php');

        $msg     = isset($_GET['pmsg']) ? sanitize_key($_GET['pmsg']) : '';
        $edit_id = isset($_GET['edit_user']) ? absint($_GET['edit_user']) : 0;
        $editing = $edit_id ? Store::get_user($edit_id) : null;

        $flash_key = 'festa_portal_flash_' . get_current_user_id();
        $flash     = get_transient($flash_key);
        if ($flash) {
            delete_transient($flash_key);
            try {
                $flash['senha'] = Crypto::decrypt((string) $flash['senha']);
            } catch (\Throwable) {
                $flash = null;
            }
        }
        $err_key = 'festa_portal_err_' . get_current_user_id();
        $error   = get_transient($err_key);
        if ($error) {
            delete_transient($err_key);
        }

        $notices = [
            'config'       => 'Configuração do portal salva.',
            'atualizado'   => 'Conta atualizada.',
            'sessoes'      => 'Sessões encerradas: a pessoa terá de entrar de novo.',
            'desbloqueado' => 'Conta desbloqueada.',
            'ativado'      => 'Conta reativada.',
            'desativado'   => 'Conta desativada. Ela não consegue mais entrar e as sessões abertas foram encerradas.',
            'excluido'     => 'Conta excluída.',
        ];
        $errors  = [
            'slug_invalido' => 'Endereço inválido: use de 3 a 60 caracteres (letras, números e hífen) e evite nomes reservados do WordPress.',
            'slug_em_uso'   => 'Já existe uma página do site com esse endereço. Escolha outro.',
            'inexistente'   => 'Conta não encontrada.',
        ];
        ?>
        <div class="wrap festa-admin festa-portal-admin">
            <h1>Festa dos Amigos 2026 — Portal externo</h1>

            <?php if (isset($notices[$msg])) : ?>
                <div class="notice notice-success is-dismissible"><p><?php echo esc_html($notices[$msg]); ?></p></div>
            <?php elseif (isset($errors[$msg])) : ?>
                <div class="notice notice-error"><p><?php echo esc_html($errors[$msg]); ?></p></div>
            <?php elseif ('erro' === $msg && $error) : ?>
                <div class="notice notice-error"><p><?php echo esc_html($error); ?></p></div>
            <?php endif; ?>

            <?php if ($flash) : ?>
                <div class="notice notice-warning festa-flash">
                    <p><strong>Senha provisória de <?php echo esc_html($flash['nome']); ?></strong> — anote agora: ela não será mostrada de novo.</p>
                    <p class="festa-flash-cred">
                        Usuário: <code><?php echo esc_html($flash['login']); ?></code>
                        &nbsp; Senha: <code class="festa-flash-pass"><?php echo esc_html($flash['senha']); ?></code>
                    </p>
                    <p>Entregue por um canal seguro (pessoalmente ou por ligação — evite e-mail e grupos). No primeiro acesso a pessoa é obrigada a criar a própria senha.</p>
                    <?php if (! $enabled) : ?>
                        <p><strong>O portal está desligado.</strong> Ligue-o abaixo para a pessoa conseguir entrar.</p>
                    <?php endif; ?>
                </div>
            <?php endif; ?>

            <?php if (! $https) : ?>
                <div class="notice notice-error"><p><strong>Este site não está em HTTPS.</strong> Sem HTTPS, usuário e senha do portal trafegam sem criptografia. Ative o HTTPS antes de ligar o portal.</p></div>
            <?php endif; ?>

            <div class="festa-box festa-portal-status">
                <h2>
                    <span class="festa-dot <?php echo $enabled ? 'is-open' : 'is-closed'; ?>"></span>
                    <?php echo $enabled ? 'Portal ligado' : 'Portal desligado'; ?>
                </h2>
                <p class="description">
                    O portal é uma página de gerenciamento fora do wp-admin, com login próprio. Quem entra por ele <strong>não</strong> é usuário do WordPress
                    e só faz o que você permitir abaixo. Desligado, o endereço responde “página não encontrada”.
                </p>
                <form method="post" action="<?php echo esc_url($post); ?>">
                    <?php echo self::fields('festa_portal_settings'); ?>
                    <table class="form-table" role="presentation">
                        <tr>
                            <th scope="row">Situação</th>
                            <td><label><input type="checkbox" name="enabled" value="1" <?php checked($enabled); ?> /> Portal ligado</label></td>
                        </tr>
                        <tr>
                            <th scope="row"><label for="festa-portal-slug">Endereço</label></th>
                            <td>
                                <code><?php echo esc_html(home_url('/')); ?></code>
                                <input type="text" id="festa-portal-slug" name="slug" value="<?php echo esc_attr($settings['slug']); ?>" class="regular-text" maxlength="60" />
                                <?php if (! Portal::pretty()) : ?>
                                    <p class="description">Os links permanentes do site estão no modo “simples”; por isso o portal usa o endereço abaixo, com <code>?festa_portal=1</code>.</p>
                                <?php endif; ?>
                                <p class="description">Endereço atual: <a href="<?php echo esc_url($url); ?>" target="_blank" rel="noopener"><code><?php echo esc_html($url); ?></code></a></p>
                            </td>
                        </tr>
                    </table>
                    <p class="submit"><button type="submit" class="button button-primary">Salvar</button></p>
                </form>
            </div>

            <?php if ($editing) : ?>
                <div class="festa-box" id="festa-portal-edit">
                    <h2>Editar conta: <?php echo esc_html($editing['login']); ?></h2>
                    <form method="post" action="<?php echo esc_url($post); ?>">
                        <?php echo self::fields('festa_portal_user_update'); ?>
                        <input type="hidden" name="id" value="<?php echo (int) $editing['id']; ?>" />
                        <table class="form-table" role="presentation">
                            <tr>
                                <th scope="row"><label for="festa-pu-nome-e">Nome</label></th>
                                <td><input type="text" id="festa-pu-nome-e" name="nome" class="regular-text" value="<?php echo esc_attr($editing['nome']); ?>" required maxlength="190" /></td>
                            </tr>
                            <tr>
                                <th scope="row"><label for="festa-pu-email-e">E-mail</label></th>
                                <td><input type="email" id="festa-pu-email-e" name="email" class="regular-text" value="<?php echo esc_attr($editing['email']); ?>" maxlength="190" />
                                    <p class="description">Opcional, só para contato.</p></td>
                            </tr>
                            <tr>
                                <th scope="row">Permissões</th>
                                <td><?php echo self::perms_checkboxes($editing['perms_list']); ?></td>
                            </tr>
                        </table>
                        <p class="submit">
                            <button type="submit" class="button button-primary">Salvar conta</button>
                            <a class="button" href="<?php echo esc_url(self::page_url()); ?>">Cancelar</a>
                        </p>
                    </form>
                </div>
            <?php endif; ?>

            <h2>Usuários do portal</h2>
            <table class="wp-list-table widefat fixed striped festa-table">
                <thead>
                    <tr>
                        <th>Pessoa</th>
                        <th>Pode</th>
                        <th class="festa-w150">Situação</th>
                        <th class="festa-w150">Último acesso</th>
                        <th class="festa-w330">Ações</th>
                    </tr>
                </thead>
                <tbody>
                <?php if (! $users) : ?>
                    <tr><td colspan="5">Nenhuma conta ainda. Crie a primeira abaixo.</td></tr>
                <?php endif; ?>
                <?php
                foreach ($users as $u) :
                    list($state, $state_label) = self::user_state($u);
                    $sessions                    = Store::count_sessions($u['id']);
                    $form_id                     = 'festa-pu-op-' . (int) $u['id'];
                    ?>
                    <tr>
                        <td>
                            <strong><?php echo esc_html($u['nome']); ?></strong>
                            <br /><span class="festa-sub-text">Usuário: <code><?php echo esc_html($u['login']); ?></code><?php echo $u['email'] ? ' · ' . esc_html($u['email']) : ''; ?></span>
                        </td>
                        <td class="festa-sub-text">
                            <?php
                            $names = [];
                            foreach ($u['perms_list'] as $p) {
                                $names[] = isset($labels[$p]) ? $labels[$p] : $p;
                            }
                            echo esc_html(implode(' · ', $names));
                            ?>
                        </td>
                        <td>
                            <span class="festa-pu-state festa-pu-state--<?php echo esc_attr($state); ?>"><?php echo esc_html($state_label); ?></span>
                            <?php if ($sessions) : ?>
                                <br /><span class="festa-sub-text"><?php echo (int) $sessions; ?> sessão(ões) aberta(s)</span>
                            <?php endif; ?>
                        </td>
                        <td><?php echo esc_html(Store::format_ts($u['last_login'])); ?></td>
                        <td class="festa-pu-actions">
                            <form method="post" action="<?php echo esc_url($post); ?>" id="<?php echo esc_attr($form_id); ?>">
                                <?php echo self::fields('festa_portal_user_op'); ?>
                                <input type="hidden" name="id" value="<?php echo (int) $u['id']; ?>" />
                            </form>
                            <a class="button button-small" href="<?php echo esc_url(self::page_url(['edit_user' => (int) $u['id']]) . '#festa-portal-edit'); ?>">Editar</a>
                            <button class="button button-small" form="<?php echo esc_attr($form_id); ?>" name="op" value="reset"
                                data-festa-confirm="<?php echo esc_attr('Gerar uma nova senha provisória para ' . $u['nome'] . '? A senha atual deixa de valer e as sessões abertas são encerradas.'); ?>">Nova senha</button>
                            <?php if ($sessions) : ?>
                                <button class="button button-small" form="<?php echo esc_attr($form_id); ?>" name="op" value="sessions">Encerrar sessões</button>
                            <?php endif; ?>
                            <?php if ('lock' === $state) : ?>
                                <button class="button button-small" form="<?php echo esc_attr($form_id); ?>" name="op" value="unlock">Desbloquear</button>
                            <?php endif; ?>
                            <button class="button button-small" form="<?php echo esc_attr($form_id); ?>" name="op" value="toggle"><?php echo $u['ativo'] ? 'Desativar' : 'Reativar'; ?></button>
                            <button class="button button-small festa-delete" form="<?php echo esc_attr($form_id); ?>" name="op" value="delete"
                                data-festa-confirm="<?php echo esc_attr('Excluir a conta de ' . $u['nome'] . '? Para só impedir o acesso, prefira "Desativar".'); ?>">Excluir</button>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>

            <div class="festa-box festa-portal-new">
                <h2>Novo usuário</h2>
                <form method="post" action="<?php echo esc_url($post); ?>">
                    <?php echo self::fields('festa_portal_user_create'); ?>
                    <table class="form-table" role="presentation">
                        <tr>
                            <th scope="row"><label for="festa-pu-nome">Nome</label></th>
                            <td><input type="text" id="festa-pu-nome" name="nome" class="regular-text" required maxlength="190" />
                                <p class="description">Aparece no histórico das inscrições, ao lado do que a pessoa alterar.</p></td>
                        </tr>
                        <tr>
                            <th scope="row"><label for="festa-pu-login">Usuário</label></th>
                            <td><input type="text" id="festa-pu-login" name="login" class="regular-text" required maxlength="40" pattern="[a-z0-9][a-z0-9._\-]{2,39}" autocapitalize="none" spellcheck="false" />
                                <p class="description">De 3 a 40 caracteres: letras minúsculas, números, ponto, hífen ou sublinhado. Ex.: <code>maria.silva</code>. Não pode ser alterado depois.</p></td>
                        </tr>
                        <tr>
                            <th scope="row"><label for="festa-pu-email">E-mail</label></th>
                            <td><input type="email" id="festa-pu-email" name="email" class="regular-text" maxlength="190" />
                                <p class="description">Opcional, só para contato.</p></td>
                        </tr>
                        <tr>
                            <th scope="row">Permissões</th>
                            <td>
                                <?php echo self::perms_checkboxes(['ver', 'dashboard', 'analisar', 'tenda', 'recibo', 'editar', 'exportar']); ?>
                                <p class="description">Dê só o necessário para o trabalho de cada pessoa. “Excluir” e “Configurações” vêm desmarcadas de propósito.
                                    Para a Defesa Civil, marque <strong>apenas</strong> “Buscar por CPF e confirmar a doação de colchões”: a conta cai direto na busca e não vê a lista de inscrições.</p>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row">Senha</th>
                            <td><p class="description">O sistema gera uma senha provisória forte e mostra <strong>uma única vez</strong> na próxima tela. A pessoa cria a própria senha no primeiro acesso.</p></td>
                        </tr>
                    </table>
                    <p class="submit"><button type="submit" class="button button-primary">Criar usuário</button></p>
                </form>
            </div>

            <div class="festa-box">
                <h2>Como o portal é protegido</h2>
                <ul class="festa-portal-sec">
                    <li><strong>Contas separadas do WordPress:</strong> quem entra pelo portal não tem acesso ao wp-admin, nem a plugins, temas ou outros dados do site.</li>
                    <li><strong>Senhas:</strong> guardadas só como hash (bcrypt); mínimo de <?php echo (int) Store::MIN_PASSWORD; ?> caracteres; a senha provisória precisa ser trocada no primeiro acesso.</li>
                    <li><strong>Tentativas erradas:</strong> <?php echo (int) Store::MAX_FAILS; ?> senhas erradas bloqueiam a conta por <?php echo (int) (Store::LOCK_SECONDS / 60); ?> minutos; muitas tentativas do mesmo endereço IP também são barradas.</li>
                    <li><strong>Sessão:</strong> encerra após <?php echo (int) (Store::SESSION_IDLE / 60); ?> minutos sem uso e, no máximo, <?php echo (int) (Store::SESSION_MAX / 3600); ?> horas depois do login. Trocar ou redefinir a senha encerra todas as sessões.</li>
                    <li><strong>Permissões:</strong> cada ação é conferida no servidor; o que a conta não pode fazer é recusado, mesmo que alguém tente forçar o envio.</li>
                    <li><strong>Rastro:</strong> acessos, recusas e exportações ficam no registro abaixo; cada alteração numa inscrição fica no histórico dela, com o nome de quem fez.</li>
                </ul>
                <p class="description">Não existe “esqueci minha senha” no portal: quem redefine é você, pelo botão “Nova senha”. Assim ninguém consegue assumir uma conta só por ter acesso ao e-mail de alguém.</p>
            </div>

            <h2>Atividade recente do portal</h2>
            <table class="wp-list-table widefat fixed striped festa-table">
                <thead>
                    <tr>
                        <th class="festa-w130">Quando</th>
                        <th class="festa-w150">Usuário</th>
                        <th class="festa-w250">Evento</th>
                        <th>Detalhe</th>
                        <th class="festa-w130">IP anônimo</th>
                    </tr>
                </thead>
                <tbody>
                <?php
                $events = Store::event_labels();
                $log    = Store::recent_log(60);
                if (! $log) :
                    ?>
                    <tr><td colspan="5">Nenhum registro ainda.</td></tr>
                <?php endif; ?>
                <?php foreach ($log as $l) : ?>
                    <tr>
                        <td><?php echo esc_html(Store::format_ts($l['created_at'], 'd/m/Y H:i:s')); ?></td>
                        <td><?php echo '' !== $l['login'] ? '<code>' . esc_html($l['login']) . '</code>' : '—'; ?></td>
                        <td><?php echo esc_html(isset($events[$l['evento']]) ? $events[$l['evento']] : $l['evento']); ?></td>
                        <td><?php echo esc_html($l['detalhe']); ?></td>
                        <td><?php echo esc_html($l['ip']); ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
            <p class="description">Mostra os 60 eventos mais recentes; o registro é guardado por <?php echo (int) Store::LOG_DAYS; ?> dias.</p>
        </div>
        <?php
    }
}
