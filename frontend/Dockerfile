# syntax=docker/dockerfile:1

# ---------- Etapa 1: build de React con Vite ----------
FROM node:22-alpine AS build
WORKDIR /app
COPY package.json package-lock.json ./
RUN npm ci --ignore-scripts
COPY . .
# La API se consume en el mismo origen (/api/v1) y NGINX la redirige al backend.
ENV VITE_API_URL=/api/v1
RUN npm run build

# ---------- Etapa 2: NGINX sirviendo el build estático ----------
FROM nginx:1.27-alpine
COPY docker/nginx.conf /etc/nginx/conf.d/default.conf
COPY --from=build /app/dist /usr/share/nginx/html

EXPOSE 80

HEALTHCHECK --interval=15s --timeout=3s --retries=3 \
    CMD wget -qO- http://127.0.0.1/healthz || exit 1
