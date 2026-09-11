# FacultyLens frontend — production image: Vite build → static files served by nginx,
# which also fronts the Laravel API (php-fpm) and enforces HTTPS + security headers.
FROM node:20-alpine AS build
WORKDIR /app
COPY package.json package-lock.json* ./
RUN npm ci --no-audit --no-fund
COPY . .
# Public API base URL embedded at build time (no secrets; VITE_* values ship to the browser)
ARG VITE_API_BASE_URL=/api
ENV VITE_API_BASE_URL=${VITE_API_BASE_URL}
RUN npm run build

FROM nginx:1.27-alpine
RUN rm /etc/nginx/conf.d/default.conf
COPY --from=build /app/dist /usr/share/nginx/html
# PHP requests are handed to php-fpm by path; the app image holds the code at /var/www/html
HEALTHCHECK --interval=30s --timeout=5s --retries=3 CMD wget -qO- http://127.0.0.1/healthz >/dev/null || exit 1
EXPOSE 80 443
