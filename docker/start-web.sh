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

# A signal interrupts `wait`, so the first one returns the moment the trap
# fires -- while both children are still draining the requests SIGQUIT just
# asked them to finish. Waiting a second time is what actually gives them
# that time, and is the whole point of sending SIGQUIT rather than SIGTERM.
#
# Only when shutting down, though: if a child died on its own, this script
# should exit immediately so Docker restarts the container. Waiting again
# there would leave nginx alone in the ring, answering 502 for as long as
# anyone cared to ask.
wait || true
if [ "$STOPPING" = 1 ]; then
    wait || true
fi
