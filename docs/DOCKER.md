# Docker

## Rootless mode

Please consider running the images in rootless mode. For more details see the
[official documentation](https://docs.docker.com/engine/security/rootless/).

If so, you will need to configure the official Docker repository and might want
to install these packages instead of those indicated in the following section:

```
apt install uidmap docker-ce docker-ce-cli containerd.io docker-buildx-plugin docker-compose-plugin
```

**Note:** setting the `DB_HOST` to `host.docker.internal` will not work; either
use a hostname from DNS or an IP address when connecting.

**Note:** `./data` looks unowned from the host. `docker/entrypoint.sh` chowns
it to the container's `www-data`, and rootless mode maps that through
`/etc/subuid` to a host id far outside your own — so `ls -l` shows a bare
number and you cannot read the files without `sudo`. That is correct, not
damage. Recovering a `./data` salvaged from elsewhere only needs it readable
by the container's user before the first start:

```
chown -R $(id -u):$(id -g) ./data
```

The entrypoint takes it from there on every start.

## Prerequisites

Docker Engine plus the **Compose v2** and **Buildx** plugins. On Ubuntu, the
`docker.io` apt package ships neither — install both explicitly:

```
apt install docker-compose-v2 docker-buildx
```

Without Compose v2, `docker compose` isn't a recognized command at all (older
`docker-compose`, hyphenated, is a different, unsupported tool). Without
Buildx, `docker compose up` still runs, but with a `configured to build using
Bake, but buildx isn't installed` warning.

Only `web` (`web-alpha` in the multi-instance sample) declares `build: .`.
`scheduler` and `eppitnic-cli` deliberately don't, even though they run the
same `image: eppitnic` — building the identical `Dockerfile` to the identical
tag from more than one service races on the final export/tag step even under
Buildx/Bake (it shares the build steps between them, but not that last one),
failing with `image "docker.io/library/eppitnic:latest": already exists`. If
you ever add a service that needs this image, give it `image: eppitnic`
without its own `build:` — Compose builds it once, from whichever service
owns it, and the rest just start from the result.

`docker compose up -d` brings up one instance: nginx + php-fpm in one
container serving `public/` on `127.0.0.1:8080`, plus a scheduler sidecar on the
same image running `poll process` every five minutes (see "Scheduled jobs" in
[INSTALL.md](INSTALL.md) — it can't be skipped). Neither container provides a
database; every service carries `extra_hosts: ["host.docker.internal:host-gateway"]`
so `DB_HOST=host.docker.internal` in `config.php` reaches one on the Docker
host — or point `DB_HOST` at another compose service's name if the database
is a container too.

## Reaching a database on the host

A container's loopback isn't the host's — traffic to `host.docker.internal`
arrives at the host over the bridge interface, with a real (non-loopback)
source address. A MariaDB bound to `127.0.0.1` refuses it regardless of
`extra_hosts`; it never had a chance to see the connection. Three changes on
the host, none of them exposing the database to the internet — the bridge
network isn't routed anywhere by the host's public interface unless you
explicitly forward it:

1. **`bind-address`** in MariaDB's config — `127.0.0.1` → `0.0.0.0`, then
   restart. This puts it on every interface, the bridge included; it is not
   the same as making it internet-reachable.
2. **Firewall it anyway**, defense in depth: `docker network inspect
   eppitnic_default | grep Subnet` for the bridge's actual subnet, then allow
   port 3306 from only that subnet and confirm nothing already allows it from
   the public interface.
3. **A grant that matches the bridge, not `localhost`** —
   `GRANT ALL PRIVILEGES ON eppitnic.* TO 'username'@'172.18.%.%' IDENTIFIED BY '<password>'; FLUSH PRIVILEGES;`
   (adjust the wildcard to the subnet from step 2).

## Putting a reverse proxy in front

`web` publishes on `127.0.0.1:8080` only, plain HTTP — nothing in the image
terminates TLS or knows the real hostname, by design (see
`docker/nginx.conf`'s own comment). Port 8080, not 80: that leaves 80 free
for the proxy itself, or for `config/nginx-vhost.sample`/
`config/apache-vhost.sample` to bind directly on a bare-metal install. Put a
normal host webserver in front of the container: `config/nginx-proxy.sample`
or `config/apache-proxy.sample`, which terminate TLS at the real
`server_name`/`ServerName` and proxy to `127.0.0.1:8080`. These are the
Docker-facing counterparts of the two vhost samples above. Changed
`compose.yaml`'s published port? Update the proxy sample's target port to
match.

One thing both proxy samples call out and is easy to get wrong: a request
proxied through `127.0.0.1` to a published container port does not
necessarily arrive with a source address of `127.0.0.1` — Docker's NAT for
host-to-published-port traffic commonly rewrites it to the bridge gateway
address instead (`docker network inspect eppitnic_default` shows the real
one). Set the `trusted_proxies` setting (see INSTALL.md's "Login rate
limiting") to whatever address actually shows up — confirmed by sending one
request and checking `GET /v1/history?object=security&limit=1` — not to
`127.0.0.1`. Get it wrong and `X-Forwarded-For` is silently ignored, so every
client behind the proxy shares one rate-limit bucket.

The image adds only `docker-php-ext-install pdo_mysql` to
`php:8.5-fpm-alpine` — everything else this codebase touches (`curl`, `dom`,
`simplexml`, `mbstring`, `posix`, …) already ships in it. `xsd/` is left out;
nothing reads it at runtime, only its filenames appear as `xsi:schemaLocation`
literals.

Configuration splits the same way as bare-metal (see "Configuration" in
[INSTALL.md](INSTALL.md)), except `config/config.php` and the self-test notes
(`var/selftest/`) move outside the image to `EPPITNIC_CONFIG_DIR` /
`EPPITNIC_VAR_DIR` (`/data/config`, `/data/var` by default, both under
`compose.yaml`'s one `/data` volume). The rest of `config/`
(`constants.php`, `mariadb-schema*.sql`) stays inside the image — don't shadow
it with a bind mount, both are read on every request. Neither variable
normally needs setting; only what `/data` maps to and which port is published
vary between instances.

CLI verbs run as a third, one-shot service rather than `docker exec` into a
running container — `doctor ownership`, `doctor epp-password`, `setup` are
wanted precisely when the stack *isn't* healthy, and `exec` needs something
already running:

```
docker compose run --rm eppitnic-cli domain info example.it
```

or via the wrapper script at the repo root, which adds picking an instance by
name:

```
./eppitnic default domain info example.it
```

`docker compose run` allocates a TTY by default, which is what lets
`Cli\Command::confirm()` prompt before a destructive verb. Scripted use needs
both `-T` (no TTY) and `--yes` — either alone still refuses:

```
./eppitnic -T default domain delete example.it --yes
```

Two instances on one host: `compose.multi.yaml.sample` — the same three
services twice, different ports and `/data` folders, each with its own
`eppitnic-cli-<name>` service (`./eppitnic alpha ...`, `./eppitnic beta ...`).

`pdns sync` is deliberately not scheduled in the image: it shells out to
`pdnsutil`, not part of a PHP image, against zones the container can't reach.
Run it from the PowerDNS host itself, against the same database.

## Tearing down, rebuilding, and starting over

To pick up a code or `Dockerfile` change, keeping the build cache:

```
docker compose down
docker compose up -d --build
```

For a genuinely clean rebuild — discard the built image and the build
cache, not just the containers:

```
docker compose down --rmi all
docker compose build --no-cache
docker compose up -d
```

`--rmi all` removes every image the compose file references, including the
pulled `php:8.5-fpm-alpine` and `composer:2` base images, so the next build
re-pulls those too. Removing the image alone doesn't clear BuildKit's layer
cache — a plain `docker compose build` afterward could still hand back
something close to what was just deleted — `--no-cache` is what forces an
actual rebuild.

Neither command touches `./data`: it's a bind mount, not a named volume, so
`config/config.php` and `var/selftest/` survive regardless. `eppitnic-cli`
needs no separate handling — it only ever runs via `docker compose run --rm`,
so there's never a lingering container for it.
