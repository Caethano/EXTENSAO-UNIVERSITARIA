<?php

declare(strict_types=1);

namespace Festa\Cpt;

use Festa\Crypto\Crypto;
use Festa\Status\StateMachine;
use Festa\Status\Vagas;
use Festa\Upload\Handler;

/**
 * CPT `inscricao_festa` — Registro e leitura.
 *
 * O CPT é só o armazenamento: não tem telas próprias no wp-admin. Toda a
 * visualização e as ações passam pelo painel de gerenciamento (Admin\Panel),
 * que funciona igual no wp-admin e no portal externo.
 *
 * Os metas são gravados pelo front-end (Módulo 7) e pelas ações da Comissão
 * e da Defesa Civil (Módulo 8); o status, só pela máquina de estados.
 */
final class CptInscricao
{
    /** Slug do CPT. */
    public const POST_TYPE = 'inscricao_festa';

    // -----------------------------------------------------------------------
    // Bootstrap
    // -----------------------------------------------------------------------

    public static function boot_hooks(): void
    {
        add_action('init', [self::class, 'register']);
    }

    // -----------------------------------------------------------------------
    // Registro do CPT
    // -----------------------------------------------------------------------

    public static function register(): void
    {
        $labels = [
            'name'               => 'Inscrições',
            'singular_name'      => 'Inscrição',
            'menu_name'          => 'Inscrições',
            'add_new'            => 'Nova Inscrição',
            'add_new_item'       => 'Nova Inscrição',
            'edit_item'          => 'Editar Inscrição',
            'view_item'          => 'Ver Inscrição',
            'search_items'       => 'Buscar Inscrições',
            'not_found'          => 'Nenhuma inscrição encontrada.',
            'not_found_in_trash' => 'Nenhuma inscrição na lixeira.',
        ];

        register_post_type(self::POST_TYPE, [
            'labels'              => $labels,
            'public'              => false,        // não aparece no front-end
            'publicly_queryable'  => false,
            'show_ui'             => false,        // telas próprias em Admin\Panel
            'show_in_menu'        => false,
            'show_in_rest'        => false,        // REST desabilitado por segurança
            'exclude_from_search' => true,
            'query_var'           => false,
            'rewrite'             => false,
            'has_archive'         => false,
            'hierarchical'        => false,
            'supports'            => ['title', 'custom-fields'],
            // Capabilities totalmente customizadas (mapeadas em Roles::register())
            'capability_type'     => self::POST_TYPE,
            'map_meta_cap'        => true,
            // Inscrição só nasce pelo pré-cadastro: nenhuma API do WordPress
            // (XML-RPC, REST, editor) pode criar uma, qualquer que seja o papel.
            'capabilities'        => ['create_posts' => 'do_not_allow'],
        ]);
    }

    // -----------------------------------------------------------------------
    // Leitura
    // -----------------------------------------------------------------------

    public static function exists(int $id): bool
    {
        return $id > 0 && get_post_type($id) === self::POST_TYPE && get_post_status($id) === 'publish';
    }

    /**
     * Dados de uma inscrição prontos para exibição.
     * O CPF só sai daqui mascarado (política LGPD).
     *
     * @return array<string, mixed>|null null se a inscrição não existir.
     */
    public static function get(int $id): ?array
    {
        if (! self::exists($id)) {
            return null;
        }

        $meta = static fn (string $key): string => (string) get_post_meta($id, $key, true);

        return [
            'id'             => $id,
            'status'         => StateMachine::current($id),
            'nome'           => $meta('_nome_responsavel'),
            'cpf'            => self::masked_cpf($id),
            'email'          => $meta('_email'),
            'telefone'       => $meta('_telefone'),
            'grupo'          => $meta('_nome_grupo'),
            'qtd'            => (int) $meta('_qtd_participantes'),
            'coccao'         => $meta('_coccao'),
            'espaco'         => (int) $meta('_espaco'),
            'tenda'          => $meta('_numero_tenda'),
            'recibo_enviado' => $meta('_upload_recibo_tenda') !== '',
            'tenda_aprovada' => (bool) get_post_meta($id, '_tenda_aprovada', true),
            'colchoes'       => (bool) get_post_meta($id, '_colchoes_confirmados', true),
            'criada_em'      => (string) get_post_field('post_date', $id),
            'aceite_em'      => $meta('_aceite_em'),
            'observacoes'    => $meta('_observacoes'),
            'anonimizada'    => (bool) get_post_meta($id, '_lgpd_purged', true),
            'fila_em'        => $meta('_fila_em'),
            'indeferimento'  => $meta('_indeferimento_motivo'),
            'indeferido_em'  => $meta('_indeferido_em'),
        ];
    }

    /**
     * CPF da inscrição mascarado para exibição: ***.456.789-**
     * Descriptografado apenas em memória; nunca exibido por inteiro.
     */
    public static function masked_cpf(int $id): string
    {
        $cpf_enc = (string) get_post_meta($id, '_cpf', true);
        if ($cpf_enc === '') {
            return '';
        }

        try {
            return Crypto::mask_cpf(Crypto::decrypt($cpf_enc));
        } catch (\Throwable) {
            // Falha de descriptografia ou inscrição anonimizada: não expõe nada.
            return '***.***.***-**';
        }
    }

    /**
     * O que falta acontecer nesta inscrição, e por conta de quem.
     *
     * @param array<string, mixed> $row Resultado de get().
     */
    public static function next_step(array $row): string
    {
        switch ($row['status']) {
            case StateMachine::STATUS_RASCUNHO:
                return 'Participante: finalizar a inscrição';
            case StateMachine::STATUS_ANALISE:
                return 'Comissão: analisar e deferir ou indeferir';
            case StateMachine::STATUS_LISTA_ESPERA:
                $pos = Vagas::posicao((int) $row['id']);
                return $pos > 0
                    ? sprintf('Lista de espera: %dº da fila, aguardando vaga', $pos)
                    : 'Lista de espera: aguardando vaga';
            case StateMachine::STATUS_DEFERIDO:
                return 'Comissão: informar a tenda sorteada';
            case StateMachine::STATUS_CONCLUIDO:
                return 'Concluída';
            case StateMachine::STATUS_INDEFERIDO:
                return 'Indeferida (a Comissão pode reabrir)';
        }

        if ($row['tenda_aprovada']) {
            $recibo = 'Recibo aprovado';
        } else {
            $recibo = $row['recibo_enviado'] ? 'Comissão: aprovar o recibo' : 'Participante: enviar o recibo';
        }

        return $recibo . ' · ' . ($row['colchoes'] ? 'Colchões confirmados' : 'Defesa Civil: confirmar os colchões');
    }

    // -----------------------------------------------------------------------
    // Exclusão
    // -----------------------------------------------------------------------

    /** Exclui a inscrição de vez, com todos os documentos do disco. */
    public static function delete(int $id): void
    {
        if (get_post_type($id) !== self::POST_TYPE) {
            return;
        }

        Handler::delete_all($id);
        wp_delete_post($id, true);
    }
}
