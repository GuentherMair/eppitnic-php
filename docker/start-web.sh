#!/bin/sh
set -eu

# php-fpm normally daemonizes and forks away from the process that started
# it, which would leave $! pointing at nothing useful; -F keeps it in the
# foreground so it's a real child this script can signal and wait on, the
# same way nginx already is.
php-fpm -F &
FPM_PID=$!

nginx -g 'daemon off;' &
NGINX_PID=$!

# tini (the image's PID 1, see the Dockerfile) hands this script SIGTERM on
# `docker stop`; forwarded here as SIGQUIT, which both nginx and php-fpm
# treat as "finish in-flight work, then exit" rather than dropping
# connections mid-response the way their own SIGTERM handling would.
STOPPING=0
shutdown() {
    STOPPING=1
    kill -QUIT "$FPM_PID" "$NGINX_PID" 2>/dev/null || true
}
trap shutdown TERM INT

# Plain `wait` (no arguments) blocks until *every* background job exits, not
# the first -- so it would never notice a lone child dying on its own (e.g.
# php-fpm failing to start): nginx just keeps running alone, answering 502
# for as long as anyone cared to ask, exactly the failure this guards
# against. Poll instead, so either one dying is caught promptly.
while [ "$STOPPING" = 0 ] && kill -0 "$FPM_PID" 2>/dev/null && kill -0 "$NGINX_PID" 2>/dev/null; do
    # guarded like the final `wait` below: a trapped signal can kill this
    # sleep too (it shares the shell's process group), and under `set -e`
    # that would otherwise abort the script here, skipping the graceful
    # drain entirely
    sleep 1 || true
done

if [ "$STOPPING" = 0 ]; then
    # one of them died on its own -- take the other down too and exit
    # non-zero, so `restart: unless-stopped` actually restarts the container
    # instead of leaving the survivor answering alone
    kill -TERM "$FPM_PID" "$NGINX_PID" 2>/dev/null || true
    wait || true
    exit 1
fi

# A signal interrupts the loop above via the trap, so it returns the moment
# SIGQUIT is sent -- while both children are still draining the requests it
# just asked them to finish. Waiting here is what actually gives them that
# time, and is the whole point of sending SIGQUIT rather than SIGTERM.
wait || true
