#!/usr/bin/env bash
# Prepara o WordPress com o plugin Festa dos Amigos 2026 antes de subir o Apache:
#
#  1. preparação padrão da imagem oficial: copia o WordPress para o volume e
#     gera o wp-config.php a partir das variáveis WORDPRESS_*;
#  2. copia o plugin (e o mu-plugin de e-mail) da imagem para wp-content, a
#     cada início: "docker compose up -d --build" já entrega a versão nova;
#  3. na primeira execução instala o WordPress; em todas, deixa o plugin ativo.
set -Eeuo pipefail

PLUGIN=festa-amigos-2026
WP_ROOT=/var/www/html
PLUGINS_DIR="$WP_ROOT/wp-content/plugins"
PLUGIN_DIR="$PLUGINS_DIR/$PLUGIN"
MARCA=.copiado-pela-imagem # só pastas com esta marca são substituídas

: "${WP_URL:=http://localhost:8080}"
: "${WP_TITLE:=Festa dos Amigos 2026}"
: "${WP_ADMIN_USER:=admin}"
: "${WP_ADMIN_PASSWORD:=}"
: "${WP_ADMIN_EMAIL:=admin@festa.local}"
: "${WP_LOCALE:=pt_BR}"
: "${WP_TIMEZONE:=America/Sao_Paulo}"

log() { echo >&2 "[festa] $*"; }

# WP-CLI como www-data, o dono dos arquivos. O ambiente do container vai junto:
# o wp-config.php da imagem lê os dados do banco das variáveis WORDPRESS_*.
wp() {
	HOME=/tmp setpriv --reuid=www-data --regid=www-data --init-groups \
		/usr/local/bin/wp --path="$WP_ROOT" "$@"
}

copiar_plugin() {
	if mountpoint -q "$PLUGIN_DIR"; then
		log "plugin montado de fora (modo desenvolvimento): usando a pasta montada"
	elif [ -d "$PLUGIN_DIR" ] && [ ! -e "$PLUGIN_DIR/$MARCA" ] && [ -n "$(ls -A "$PLUGIN_DIR")" ]; then
		log "AVISO: $PLUGIN_DIR não foi copiado por esta imagem; mantido como está"
	else
		# Pasta com ponto: o WordPress ignora, então uma cópia interrompida não
		# aparece como plugin duplicado. Arquivos do root: o PHP só lê o código.
		local novo="$PLUGINS_DIR/.$PLUGIN-novo"
		rm -rf "$novo"
		cp -R "/usr/src/$PLUGIN" "$novo"
		touch "$novo/$MARCA"
		rm -rf "$PLUGIN_DIR"
		mv "$novo" "$PLUGIN_DIR"
	fi

	install -d -o www-data -g www-data "$WP_ROOT/wp-content/mu-plugins"
	cp /usr/src/festa-mu-plugins/*.php "$WP_ROOT/wp-content/mu-plugins/"
}

esperar_banco() {
	local tentativa
	for tentativa in $(seq 1 60); do
		if php -r '
			mysqli_report(MYSQLI_REPORT_OFF);
			$host = explode(":", getenv("WORDPRESS_DB_HOST") ?: "mysql", 2);
			$ok = @mysqli_connect($host[0], (string) getenv("WORDPRESS_DB_USER"),
				(string) getenv("WORDPRESS_DB_PASSWORD"), (string) getenv("WORDPRESS_DB_NAME"),
				(int) ($host[1] ?? 3306));
			exit($ok ? 0 : 1);'; then
			return
		fi
		[ "$tentativa" = 1 ] && log "aguardando o banco de dados..."
		sleep 2
	done
	log "ERRO: o banco de dados não respondeu em 2 minutos"
	exit 1
}

instalar_wordpress() {
	wp core is-installed 2>/dev/null && return

	local senha="$WP_ADMIN_PASSWORD" sorteada=
	if [ -z "$senha" ]; then
		senha="$(php -r 'echo bin2hex(random_bytes(10));')"
		sorteada=1
	fi

	log "primeira execução: instalando o WordPress em $WP_URL"
	# A senha vai pela entrada padrão: na linha de comando ela apareceria no "ps".
	printf '%s\n' "$senha" | wp core install --prompt=admin_password \
		--url="$WP_URL" --title="$WP_TITLE" --admin_user="$WP_ADMIN_USER" \
		--admin_email="$WP_ADMIN_EMAIL" --skip-email >/dev/null
	wp option update timezone_string "$WP_TIMEZONE" >/dev/null
	wp rewrite structure '/%postname%/' >/dev/null

	if [ "$WP_LOCALE" != en_US ]; then
		wp language core install "$WP_LOCALE" --activate >/dev/null 2>&1 \
			|| log "AVISO: não deu para baixar o idioma $WP_LOCALE (sem internet?); o painel fica em inglês"
	fi

	if [ -n "$sorteada" ]; then
		log "============================================================"
		log " Administrador do WordPress ($WP_URL/wp-admin/)"
		log "   usuário: $WP_ADMIN_USER"
		log "   senha:   $senha"
		log " Anote: ela não é sorteada de novo e some destes logs quando o container for recriado."
		log "============================================================"
	fi
}

if [[ "${1-}" == apache2* ]]; then
	docker-ensure-installed.sh
	copiar_plugin
	esperar_banco
	instalar_wordpress
	wp plugin is-active "$PLUGIN" || wp plugin activate "$PLUGIN" >/dev/null
	log "pronto: $WP_URL"
fi

exec docker-entrypoint.sh "$@"
