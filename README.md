# LEM Adaptors

Official open-source adaptors for **[Live Event Manager](https://github.com/simulcaststream/live-event-manager)** (core v1.2.0+).

**Repository:** [github.com/simulcaststream/lem-adaptors](https://github.com/simulcaststream/lem-adaptors)

Registers all built-in providers on `plugins_loaded` (priority 20):

| Type | ID | Provider |
|------|-----|----------|
| Streaming | `mux` | Mux |
| Streaming | `ome` | OvenMediaEngine |
| Payments | `stripe` | Stripe |
| Payments | `paypal` | PayPal |
| Chat | `ably` | Ably live chat |

Configure credentials under **Live Events → Services** (or **Vendors**). Configure Ably chat under **Services → Chat**, not the main Settings screen.

## Requirements

- WordPress 5.0+, **PHP 8.0+**
- **[Live Event Manager](https://github.com/simulcaststream/live-event-manager)** 1.2.0+ (activate first)
- Provider accounts as needed (Mux, OME, Stripe, PayPal, Ably)

## Install

1. Install and activate **Live Event Manager**.
2. Install **LEM Adaptors** into `wp-content/plugins/lem-adaptors` and activate.
3. Run `composer install` in this plugin directory (required for the Stripe PHP SDK).
4. Configure credentials under **Live Events → Services** and choose the active provider under **Settings**.

## Local WordPress (symlink)

```bash
ln -sf ~/Documents/github/lem-adaptors \
  "~/Local Sites/your-site/app/public/wp-content/plugins/lem-adaptors"
```

Use folder name `lem-adaptors` in `wp-content/plugins/`.

## Deprecated: LEM Premium

The separate **LEM Premium** plugin is no longer used. Mux and Stripe live here. Deactivate and remove `lem-premium` from your site.

## Extension API

Provider classes are lazy-loaded via core filters:

- `lem_streaming_provider_class_file`
- `lem_payment_provider_class_file`
- `lem_chat_provider_class_file`

See [EXTENDING.md](https://github.com/simulcaststream/live-event-manager/blob/main/EXTENDING.md) in the core repo.

## License

GPLv2 or later — see [license.txt](license.txt).
