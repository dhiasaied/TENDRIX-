# syntax=docker/dockerfile:1
# Web tier: Angular SPA + reverse proxy to the PHP-FPM API. Build context: repository root.

FROM node:22-alpine AS spa
WORKDIR /src
COPY frontend/package.json frontend/package-lock.json ./
RUN npm ci --no-audit --no-fund
COPY frontend/ ./
RUN npx ng build --configuration production \
 # CSP: hash of the single inline script (theme bootstrap) of the built index.html.
 && node -e "const h=require('fs').readFileSync('dist/frontend/browser/index.html','utf8');const m=[...h.matchAll(/<script>([\s\S]*?)<\/script>/g)];if(m.length!==1)throw new Error('expected exactly one inline script, found '+m.length);process.stdout.write('sha256-'+require('crypto').createHash('sha256').update(m[0][1]).digest('base64'))" > /src/csp-script-hash

# Runs as an unprivileged user (no root process in the container), listening on 8080.
FROM nginxinc/nginx-unprivileged:1.27-alpine
COPY docker/nginx/default.conf /etc/nginx/conf.d/default.conf
COPY docker/nginx/security-headers.conf docker/nginx/php-api.conf /etc/nginx/snippets/
COPY --from=spa /src/dist/frontend/browser /usr/share/nginx/html
COPY --from=spa /src/csp-script-hash /tmp/csp-script-hash
USER root
RUN sed -i "s|__THEME_SCRIPT_HASH__|$(cat /tmp/csp-script-hash)|" /etc/nginx/snippets/security-headers.conf \
 && ! grep -q __THEME_SCRIPT_HASH__ /etc/nginx/snippets/security-headers.conf \
 && rm /tmp/csp-script-hash \
 && find /usr/share/nginx/html -name '*.map' -delete
USER nginx
HEALTHCHECK --interval=15s --timeout=3s CMD wget -qO- http://127.0.0.1:8080/healthz || exit 1
