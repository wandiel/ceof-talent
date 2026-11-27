# syntax=docker/dockerfile:1.10
# =============================================================================
# CEOF TALENT — Nginx de borda (proxy reverso e terminação HTTPS)
# Executa como usuário "nginx" (não root), escutando nas portas 8080/8443
# do container. As portas 80/443 do host são mapeadas no Compose.
# Os templates em /etc/nginx/templates/*.template recebem variáveis de
# ambiente (envsubst) na inicialização — ver imagem oficial do Nginx.
# =============================================================================
ARG NGINX_IMAGE=nginx:1.30.8-alpine
FROM ${NGINX_IMAGE}

COPY docker/nginx/nginx.conf /etc/nginx/nginx.conf
COPY docker/nginx/snippets/ /etc/nginx/snippets/

RUN rm -f /etc/nginx/conf.d/default.conf \
    && mkdir -p /var/www/certbot \
    && chown -R nginx:nginx /var/cache/nginx /etc/nginx/conf.d /var/www/certbot

USER nginx
EXPOSE 8080 8443
CMD ["nginx", "-g", "daemon off;"]
