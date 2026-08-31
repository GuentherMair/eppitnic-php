# Docker

## Prerequisites

Docker Engine plus the **Compose v2** and **Buildx** plugins. On Ubuntu, the
`docker.io` apt package ships neither — install both explicitly:

```
sudo apt install docker-compose-v2 docker-buildx
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
container serving `public/` on `127.0.0.1:80`, plus a scheduler sidecar on the
same image running `poll process` every five minutes (see "Scheduled jobs" in
[INSTALL.md](INSTALL.md) — it can't be skipped). Neither container provides a
database; point `config.php` at one on the Docker host via
`host.docker.internal` (works out of the box on Docker Desktop; on Linux add
`extra_hosts: ["host.docker.internal:host-gateway"]`), or at another compose
service by name.

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
