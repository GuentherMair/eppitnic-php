# Setting up an installation from the published image

A ready-made image is published at `ghcr.io/inet-services/eppitnic`, for
linux/amd64 and linux/arm64, built from this repository's `Dockerfile`.
Anonymous pulls work; no login is needed. This page sets up a new
installation from it instead of building it, as [DOCKER.md](DOCKER.md)
describes.

## What you get

One image, run in three roles: the REST API (`web`, nginx and php-fpm), the
`scheduler` that runs `eppitnic cron run` every minute, and the one-shot
`eppitnic-cli`. The web frontend is not part of it.

Prerequisites:

- Docker with the Compose v2 plugin (see "Prerequisites" in
  [DOCKER.md](DOCKER.md)).
- A MariaDB database and user, reachable from the containers (see "Connect to
  a database on the host" in [DOCKER.md](DOCKER.md)).
- The EPP registry credentials, entered during setup.
- Optionally [cosign](https://docs.sigstore.dev/cosign/installation/), to
  verify the image.

## Verify the image

Each image is signed with cosign by digest. The publisher's public key is
[cosign.pub](cosign.pub); from the repository root:

```bash
cosign verify --key docs/cosign.pub ghcr.io/inet-services/eppitnic:latest
```

Success prints the checks that were run and the signature's JSON payload
(`critical.image.docker-manifest-digest` is the signed digest) and exits 0.
A failure exits non-zero; don't run that image.

Each release is tagged with its version, and `latest` points to the newest.
To stay on one, pin its version in `compose.yaml`. To pin the exact content,
use the digest from the verified payload instead:

```yaml
image: ghcr.io/inet-services/eppitnic:7.0.0@sha256:<DIGEST>
```

## Write the compose file

In an empty directory, create `compose.yaml`:

```yaml
services:
  web:
    image: ghcr.io/inet-services/eppitnic:latest
    ports:
      - "127.0.0.1:8080:80"
    volumes:
      - ./data:/data
    extra_hosts:
      - "host.docker.internal:host-gateway"
    restart: unless-stopped

  scheduler:
    image: ghcr.io/inet-services/eppitnic:latest
    command: ["crond", "-f", "-L", "/dev/stdout"]
    volumes:
      - ./data:/data
    extra_hosts:
      - "host.docker.internal:host-gateway"
    restart: unless-stopped

  eppitnic-cli:
    image: ghcr.io/inet-services/eppitnic:latest
    profiles: ["cli"]
    entrypoint: ["/sbin/tini", "--", "/docker/entrypoint.sh", "su-exec", "www-data", "eppitnic"]
    volumes:
      - ./data:/data
    extra_hosts:
      - "host.docker.internal:host-gateway"
```

This is the repository's `compose.yaml` with `image:` pointing at the
published image and no `build:`. `./data` holds `config.php` and the
self-test notes; the services' logs go to `docker compose logs <service>`.

To publish another port, see "Change a published port" in
[DOCKER.md](DOCKER.md).

## First start

```bash
docker compose up -d
```

Run the setup, either interactively:

```bash
docker compose run --rm eppitnic-cli setup
```

or in a browser at `http://127.0.0.1:8080/setup.html`. Set the database host
to `host.docker.internal` for a database on the Docker host. Setup installs
the schema and writes `config.php` to `./data/config/`. Then confirm the API
answers:

```bash
curl http://127.0.0.1:8080/
```

The expected output is `Hello, World!`. The scheduler starts working once
`config.php` exists.

CLI commands run the same way, e.g. `docker compose run --rm eppitnic-cli
domain info example.it`; see "Run CLI commands" in [DOCKER.md](DOCKER.md).

## Behind a reverse proxy

`web` serves plain HTTP on `127.0.0.1:8080`; terminate TLS in a proxy in
front (see "Put a reverse proxy in front" in [DOCKER.md](DOCKER.md)). Then
list the proxy's network, or `X-Forwarded-For` is ignored:

```bash
docker compose run --rm eppitnic-cli config trusted-proxies add <NETWORK>
```

`<NETWORK>` is an address, optionally with a prefix (`172.18.0.0/16`). A
`/0` network is refused. [DOCKER.md](DOCKER.md) shows how to find the address
that actually arrives.

## Upgrade

With `latest`, just run the commands below; with a pinned version, change it
in all three `image:` lines first, then:

```bash
docker compose pull
docker compose up -d
```

Verify the new version first, as above. The schema upgrades itself: on
every initialization the application compares `settings.schema_version` with
the one it ships and applies the missing steps. Back up before upgrading,
and read the [CHANGELOG](CHANGELOG.md).

## Back up

Two things hold the state: the database and `./data`.

```bash
mysqldump --single-transaction <DB_NAME> > eppitnic.sql
tar czf eppitnic-data.tgz data
```

`./data` is a bind mount, so `docker compose down` never touches it.
