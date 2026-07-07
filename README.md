# Bunny.net Plugin for VitoDeploy

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
Use `@` (or leave the zone apex name) for root records. Bunny-specific record types
(Redirect, PullZone, Script, …) are listed read-only style but should be managed in the Bunny dashboard.

### Storage provider (backups)

1. Create a Storage Zone at dash.bunny.net → Storage.
2. In Vito, go to **Settings → Storage Providers**, add **Bunny Storage**:
   - **Storage Zone Name** — the zone name.
   - **Access Key** — the zone's password (FTP & API Access → Password), *not* your account API key.
   - **Endpoint** — must match the zone's main region (`storage.bunnycdn.com` for Falkenstein).
   - **Path** — optional directory prefix for backup files.
3. Use it as the destination when creating database or file backups.

File transfers run on the managed server itself using `curl` against the Bunny Storage HTTP API.

**Known limitation:** Vito core composes backup file paths only for its built-in storage
providers ([`BackupFile::path()`](https://github.com/vitodeploy/vito/blob/4.x/app/Models/BackupFile.php)
returns an empty path for plugin providers). This plugin falls back to
`{Path}/{backup-file-name}.zip` for uploads and restores, which works fine — but **deleting a backup
file in Vito does not delete it from the storage zone** (a warning is logged instead). Clean up old
files with a Bunny Storage zone [auto-delete policy](https://docs.bunny.net) or manually.

### CDN site feature

On any site, open **Features → Bunny CDN**:

- **Setup** — enter the pull zone ID. The API key is optional: if left empty, the key from your
  connected Bunny DNS provider is reused (recommended — it is stored encrypted). A key entered here
  is stored unencrypted in the site's metadata.
- **Purge Cache** — purges the whole pull zone.
- **Remove** — unlinks the pull zone from the site.

### Workflow action

Add **Bunny CDN Purge Cache** (General category) to a workflow, e.g. after **Deploy Site**:

| Input | Description |
| --- | --- |
| `site_id` | Optional. Site with the Bunny CDN feature set up; its pull zone/key are used. |
| `pull_zone_id` | Pull zone to purge. Required unless `site_id` or `url` is given. |
| `api_key` | Optional. Falls back to the site's key or a connected Bunny DNS provider. |
| `url` | Optional. Purge a single URL instead of the whole zone. |

Outputs: `success`, `status_code`, `message`.

## Requirements

- VitoDeploy 4.x
- `curl` on the managed servers (installed by default on Vito-provisioned servers)

## License

MIT
