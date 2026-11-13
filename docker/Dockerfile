# ==============================================================================
# Ultra-optimized Containerfile for Foundry Documentation
# Minimal, hardened Nginx Alpine-slim (<20MB total image size, unprivileged)
# Serves the pre-built static documentation from public/
# ==============================================================================

FROM docker.io/library/nginx:alpine-slim

# Tailored configuration and security headers
COPY docker/nginx.conf /etc/nginx/nginx.conf
COPY docker/security-headers.conf /etc/nginx/security-headers.conf

# Copy pre-compiled static assets
COPY public /usr/share/nginx/html

# Clean default configs & fix ownership for unprivileged nginx user (UID 101)
RUN rm -rf /etc/nginx/conf.d/* /docker-entrypoint.d/* \
    && chown -R 101:101 /usr/share/nginx/html /etc/nginx

USER 101:101

EXPOSE 8080

HEALTHCHECK --interval=30s --timeout=3s --start-period=5s --retries=3 \
    CMD wget -q --spider http://127.0.0.1:8080/ || exit 1

CMD ["nginx", "-g", "daemon off;"]
