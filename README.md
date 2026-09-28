# Bunny.net Plugin for VitoDeploy

[![CI](https://github.com/pietervanleuven/vitodeploy-bunny/actions/workflows/ci.yml/badge.svg)](https://github.com/pietervanleuven/vitodeploy-bunny/actions/workflows/ci.yml)

Integrates [Bunny.net](https://bunny.net) with [VitoDeploy](https://vitodeploy.com) (4.x):

- **Bunny DNS provider** — manage DNS zones and records from Vito (Settings → DNS Providers).
- **Bunny Storage provider** — use an Edge Storage zone as a backup destination (Settings → Storage Providers).
- **Bunny CDN site feature** — link a pull zone to a site and purge its cache from the site's Features tab.
- **Workflow action** — `Bunny CDN Purge Cache` to purge a pull zone (or a single URL) from a workflow, e.g. after a deployment.

## Installation

Install from the **Admin → Plugins** section in Vito (Community tab), or manually:

```
Repository: pietervanleuven/vitodeploy-bunny
```

For local development, place this repository at
`app/Vito/Plugins/Pietervanleuven/VitodeployBunny` inside your Vito installation, then discover it
via **Admin → Plugins → Discover** and enable it.

## Usage

### DNS provider

1. Create an account API key at dash.bunny.net → Account Settings → API.
2. In Vito, go to **Settings → DNS Providers**, add **Bunny DNS**, and paste the key.

Supported record types: `A`, `AAAA`, `CNAME`, `TXT`, `MX`, `SRV`, `NS`, `CAA`, `PTR`.
Use `@` (or the zone apex name) for root records. Bunny-specific record types
(Redirect, PullZone, Script, …) are listed read-only style but must be managed in the Bunny dashboard.

Record input is validated before it is sent: the content of `A`/`AAAA` records must be an IP address,
`CNAME`/`NS`/`PTR`/`MX`/`SRV` targets must be host names, `CAA` content must look like
`0 issue "letsencrypt.org"`, the TTL must be between 1 and 86400 seconds, and `MX` records require a
priority (0–65535). Zone listings are paginated, so accounts with more than 1,000 zones work.

### Storage provider (backups)

1. Create a Storage Zone at dash.bunny.net → Storage.
2. In Vito, go to **Settings → Storage Providers**, add **Bunny Storage**:
   - **Storage Zone Name** — the zone name.
   - **Access Key** — the zone's password (FTP & API Access → Password), *not* your account API key.
   - **Endpoint** — must match the zone's main region (see below).
   - **Path** — optional directory prefix for backup files.
3. Use it as the destination when creating database or file backups.

#### Supported regions

The endpoint must match the **main storage region** of the zone. Using another region's endpoint
results in `401` responses.

| Main region        | Endpoint                   |
| ------------------ | -------------------------- |
| Falkenstein, DE    | `storage.bunnycdn.com`     |
| London, UK         | `uk.storage.bunnycdn.com`  |
| New York, US       | `ny.storage.bunnycdn.com`  |
| Los Angeles, US    | `la.storage.bunnycdn.com`  |
| Singapore, SG      | `sg.storage.bunnycdn.com`  |
| Stockholm, SE      | `se.storage.bunnycdn.com`  |
| São Paulo, BR      | `br.storage.bunnycdn.com`  |
| Johannesburg, SA   | `jh.storage.bunnycdn.com`  |
| Sydney, AU         | `syd.storage.bunnycdn.com` |

#### How transfers work

File transfers run on the managed server itself using `curl` against the
[Bunny Storage HTTP API](https://docs.bunny.net/reference/storage-api). Every value is passed to
`curl` as a single shell argument, remote paths are percent-encoded, and each request uses
`--fail`, a 30 second connection timeout, three retries for transient errors and a stall detector.

Backups are stored as `{Path}/{backup-file-name}.sql.gz` (database) or `.tar.gz` (files).

| Operation | Request                        | Expected response                    |
| --------- | ------------------------------ | ------------------------------------ |
| Connect   | `GET /{zone}/`                 | `200` with the directory listing     |
| Upload    | `PUT /{zone}/{path}`           | `201 Created`                        |
| Download  | `GET /{zone}/{path}`           | `200 OK`                             |
| Delete    | `DELETE /{zone}/{path}`        | `200 OK`, or `404` (already deleted) |

Any other status fails the operation; the HTTP status is recorded in Vito's server log for the
transfer and in the application log. Response bodies are not logged.

#### Backup deletion

Vito core only composes backup file paths for its built-in storage providers
([`BackupFile::path()`](https://github.com/vitodeploy/vito/blob/4.x/app/Models/BackupFile.php)
passes an empty path to plugin providers). This plugin therefore handles deletion itself: when Vito
deletes a backup file stored in Bunny Storage, the plugin recomposes the path it uploaded to and
removes the remote object. If the remote delete fails, the file is marked **Delete failed** in Vito
and a notification is sent, exactly like the built-in providers. Files uploaded by plugin versions
that used a different path layout must be removed manually.

### CDN site feature

On any site, open **Features → Bunny CDN**:

- **Setup** — enter the pull zone ID. The API key is optional:
  - If left empty, Setup links the **Bunny DNS provider** you have connected for the site's project
    (or a global one) and reuses its API key at purge time. Nothing secret is copied to the site.
  - If a key is entered, it is stored **encrypted** with Vito's application key.
- **Purge Cache** — purges the whole pull zone.
- **Remove** — unlinks the pull zone from the site.

### Workflow action

Add **Bunny CDN Purge Cache** (General category) to a workflow, e.g. after **Deploy Site**:

| Input          | Description                                                                                        |
| -------------- | -------------------------------------------------------------------------------------------------- |
| `site_id`      | Optional. Site with the Bunny CDN feature set up; its pull zone and credentials are used.          |
| `pull_zone_id` | Pull zone to purge. Required unless `site_id` or `url` is given.                                   |
| `api_key`      | Optional. Falls back to the site's credentials, then to a Bunny DNS provider of the workflow's user in the workflow's project (or a global one). |
| `url`          | Optional. Purge a single URL instead of the whole zone.                                            |

Outputs: `success`, `status_code`, `message`.

## Credential storage

| Credential                          | Where it lives                                | Protection                                                                 |
| ----------------------------------- | --------------------------------------------- | -------------------------------------------------------------------------- |
| DNS provider API key                | `dns_providers.credentials`                   | Encrypted by Vito (`encrypted:array` cast), never returned to the browser  |
| Storage zone access key             | `storage_providers.credentials`               | Encrypted by Vito, only overwritten when a new value is submitted          |
| CDN feature: linked DNS provider    | `sites.type_data.bunny_cdn.dns_provider_id`   | A reference only; the key stays with the DNS provider                      |
| CDN feature: explicit API key       | `sites.type_data.bunny_cdn.api_key_encrypted` | Encrypted with `APP_KEY`                                                   |

Keep in mind that `sites.type_data` is plain JSON that Vito shows to every member of the project
and returns from its API, which is why the plugin never writes a plain-text key there. Sites
configured by plugin versions before 0.1.0 must be run through Setup again before cache purging
will work; legacy plain-text keys are intentionally no longer read.

All Bunny API requests use a 10 second connection timeout, a 30 second request timeout and retry
safe (`GET`/`HEAD`/`DELETE`) requests up to three times on connection errors, `429` and `5xx`
responses. Writes are never retried.

## Requirements

- VitoDeploy 4.x
- `curl` on the managed servers (installed by default on Vito-provisioned servers)

## Development

The plugin only resolves against a VitoDeploy checkout, so tests and static analysis run from
inside one:

```bash
git clone --depth 1 --branch 4.x https://github.com/vitodeploy/vito.git
cd vito && composer install
touch .env && php artisan key:generate && touch storage/database-test.sqlite

# Copy (or symlink) this repository into the plugins directory
rsync -a --exclude .git --exclude vendor /path/to/vitodeploy-bunny/ app/Vito/Plugins/Pietervanleuven/VitodeployBunny/

php artisan test app/Vito/Plugins/Pietervanleuven/VitodeployBunny/tests
./vendor/bin/phpstan analyse -c app/Vito/Plugins/Pietervanleuven/VitodeployBunny/phpstan.neon
```

Code style runs standalone: `composer install && composer lint:test` in this repository.

CI does the same against the pinned Vito release and, as an advisory job, the `4.x` branch.

## License

MIT
