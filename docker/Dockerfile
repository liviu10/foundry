# ==============================================================================
# Ultra-optimized Containerfile for Foundry Documentation
# Minimal, hardened Nginx Alpine-slim (<20MB total image size, unprivileged)
# Serves the pre-built static documentation from public/
# ==============================================================================

FROM docker.io/library/nginx:alpine-slim

# Tailored single-worker configuration
COPY docker/nginx.conf /etc/nginx/nginx.conf

# Copy pre-compiled static assets
COPY public /usr/share/nginx/html

# Clean default configs & fix ownership for unprivileged nginx user (UID 101)
RUN rm -rf /etc/nginx/conf.d/* /docker-entrypoint.d/* \
    && chown -R 101:101 /usr/share/nginx/html /etc/nginx

USER 101:101

EXPOSE 8080

CMD ["nginx", "-g", "daemon off;"]
