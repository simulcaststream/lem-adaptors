# LEM Adaptors

Official open-source adaptors for **[Live Event Manager](https://github.com/simulcaststream/wp-live-event-manager)** (core).

Registers all built-in providers on `plugins_loaded` (priority 20):

| Type | ID | Class |
|------|-----|--------|
| Streaming | `mux` | Mux |
| Streaming | `ome` | OvenMediaEngine |
| Payments | `stripe` | Stripe |
| Payments | `paypal` | PayPal |
| Chat | `ably` | Ably live chat |

Configure chat under **Live Events → Services → Chat** (not Settings).

## Install

1. Install and activate **Live Event Manager**.
2. Install and activate **LEM Adaptors** (this plugin).
3. Run `composer install` in this plugin directory (required for the Stripe PHP SDK).
4. Configure credentials under **Live Events → Services** and choose the active provider under **Settings**.

## Local WordPress (symlink)

Point your site’s plugins folder at this repo, e.g.:

```bash
ln -sf ~/Documents/github/wp-lem-adaptors \
  "~/Local Sites/your-site/app/public/wp-content/plugins/lem-adaptors"
```

Use folder name `lem-adaptors` in `wp-content/plugins/`.

## Deprecated: LEM Premium

The separate **LEM Premium** plugin is no longer used. Mux and Stripe live here. Deactivate and remove `lem-premium` from your site.

## Dev notes

Provider classes are lazy-loaded via:

- `lem_streaming_provider_class_file`
- `lem_payment_provider_class_file`
