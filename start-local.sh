#!/bin/zsh
cd "$(dirname "$0")"
unset HTTP_PROXY HTTPS_PROXY http_proxy https_proxy ALL_PROXY all_proxy
unset SOCKS_PROXY SOCKS5_PROXY socks_proxy socks5_proxy
unset GIT_HTTP_PROXY GIT_HTTPS_PROXY
export NO_PROXY='*'
export no_proxy='*'
PORT="${1:-8099}"
PAY_PHP_PORT="${PAY_PHP_PORT:-8101}"
lsof -ti:"$PORT" | xargs kill -9 2>/dev/null || true
lsof -ti:"$PAY_PHP_PORT" | xargs kill -9 2>/dev/null || true
echo "Starting PHP pay sidecar http://127.0.0.1:$PAY_PHP_PORT ..."
php -S "127.0.0.1:$PAY_PHP_PORT" pay_router.php >/tmp/veer-pay-php.log 2>&1 &
echo "Starting http://127.0.0.1:$PORT (Node + /pay → PHP) ..."
export PAY_PHP_PORT
exec node local-server.mjs "$PORT"
