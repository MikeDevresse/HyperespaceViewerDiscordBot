ARG VERSION

FROM nginx:${VERSION}-alpine as dev

# GET local nginx config
COPY ./docker/nginx/default.conf /etc/nginx/conf.d/default.conf

WORKDIR /tp4

FROM dev as prod

COPY ./public /hyperespace/public
