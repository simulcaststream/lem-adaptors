=== LEM Adaptors ===
Contributors: simulcast
Tags: live streaming, mux, stripe, paypal, events
Requires at least: 5.0
Tested up to: 6.8
Requires PHP: 8.0
Stable tag: 1.0.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Official streaming, payment, and chat providers for Live Event Manager.

== Description ==

LEM Adaptors registers the built-in providers for **[Live Event Manager](https://github.com/simulcaststream/live-event-manager)** core:

* **Mux** and **OvenMediaEngine (OME)** — streaming
* **Stripe** and **PayPal** — payments
* **Ably** — live chat on watch pages

Core ships contracts only; this plugin supplies the default implementations. Requires **Live Event Manager** 1.2.0+.

== Installation ==

1. Install and activate **Live Event Manager** (folder: `live-event-manager`).
2. Upload this plugin to `/wp-content/plugins/lem-adaptors` and activate.
3. Run `composer install` in the plugin directory (required for Stripe).
4. Open **Live Events → Services** (or **Vendors**) and enter provider credentials.
5. Choose the active streaming and payment provider under **Live Events → Settings**.

== Frequently Asked Questions ==

= Does this work without the core plugin? =

No. Activate **Live Event Manager** first. LEM Adaptors will refuse activation if core is missing.

= Where do I configure Mux, Stripe, PayPal, and Ably? =

Under **Live Events → Services** in wp-admin. Chat (Ably) is configured there, not on the main Settings screen.

== Changelog ==

= 1.0.0 =
* Official adaptors for Mux, OME, Stripe, PayPal, and Ably chat.
* Lazy provider registration via LEM core factories (plugins_loaded priority 20).
* PayPal capture return handler and Stripe SDK via Composer.
* Requires Live Event Manager 1.2.0+ and PHP 8.0+.
