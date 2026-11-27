# WordPress com o plugin Festa dos Amigos 2026 já instalado e ativo.
# Versões fixas: o ambiente é o mesmo em qualquer máquina.

FROM wordpress:cli-2.12.0-php8.3 AS wpcli

FROM wordpress:7.1-php8.3-apache

# WP-CLI: instala o WordPress na primeira execução e é usado pelos testes.
COPY --from=wpcli /usr/local/bin/wp /usr/local/bin/wp
ENV WP_CLI_CACHE_DIR=/tmp/wp-cli-cache

# Pasta privada do plugin (documentos enviados e chaves de criptografia), ao
# lado da raiz do site e fora dela: não existe URL que chegue até aqui.
# O plugin a encontra sozinho; o docker-compose.yml guarda num volume próprio.
RUN install -d -o www-data -g www-data -m 0750 /var/www/festa-uploads-privados

COPY plugin/ /usr/src/festa-amigos-2026/
COPY docker/mu-plugins/ /usr/src/festa-mu-plugins/
COPY docker/festa-entrypoint.sh /usr/local/bin/festa-entrypoint.sh

# sed: o script precisa de fim de linha LF mesmo num clone feito no Windows.
RUN sed -i 's/\r$//' /usr/local/bin/festa-entrypoint.sh \
 && chmod 0755 /usr/local/bin/festa-entrypoint.sh \
 && find /usr/src/festa-amigos-2026 /usr/src/festa-mu-plugins -type d -exec chmod 0755 {} + \
 && find /usr/src/festa-amigos-2026 /usr/src/festa-mu-plugins -type f -exec chmod 0644 {} +

ENTRYPOINT ["festa-entrypoint.sh"]
CMD ["apache2-foreground"]
