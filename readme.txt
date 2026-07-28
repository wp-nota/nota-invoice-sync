=== Nota Invoice Sync for Lexware Office ===
Contributors: wpnota
Tags: lexware, lexoffice, invoice, woocommerce, accounting
Requires at least: 6.0
Tested up to: 7.0
Requires PHP: 7.4
Requires Plugins: woocommerce
Stable tag: 0.1.7
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Creates invoices in Lexware Office directly from WooCommerce orders, with no third-party middleware in between.

== Description ==

Nota Invoice Sync for Lexware Office creates a real Lexware Office invoice directly from a
WooCommerce order — no ERP or middleware in between.

**Why not go through a sync tool?**

Most WooCommerce ↔ Lexware connectors work by re-calculating tax on their side and sending a
finished total to Lexware, which then re-validates that calculation. On low-value line items
(a sample product priced at a few cents, for example) that round-trip can produce a rate that
doesn't quite match after rounding, and the invoice is rejected.

This plugin sends only the net amount and tax rate for each line. Lexware Office itself
computes every tax amount and total — there is no second calculation to disagree with.

**What it does**

* Creates a Lexware Office invoice (draft or finalised) from the order screen, with one click.
* Finds or creates the matching Lexware contact by email, and keeps the contact's address in
  sync with the order if it changes on a later order.
* Works out the correct German/EU tax treatment for the order — domestic, intra-community
  reverse charge, EU distance sales (OSS), or export to a third country.
* Warns you on the settings page if your shop's tax setup conflicts with your Lexware Office
  account's own configuration, before it causes a rejected invoice.
* Shows the invoice number and status right on the order list and the order screen.
* Includes a test mode that builds the full invoice payload and writes it to the log without
  sending anything, so you can verify everything before the first real invoice.

**Requirements**

* WooCommerce, installed and active.
* A Lexware Office account with access to the Public API (a personal API key, created in
  Lexware Office under the Public API settings).
* Shop currency EUR — Lexware Office invoicing supports only EUR, so orders in any other
  currency are skipped and the reason is logged.

= External services =

This plugin connects to the Lexware Office Public API at `https://api.lexware.io`, operated by
Haufe-Lexware GmbH & Co. KG (Germany), to create documents in your own Lexware Office account.
It is required for everything the plugin does — without an API key, the plugin does nothing.

What is sent, and when:

* When you create an invoice for an order: the customer's name, billing address, email
  address, the order's line items (product names, quantities, net prices, tax rates),
  shipping cost, order number and order/delivery dates.
* As part of creating an invoice, the plugin looks up the matching Lexware contact by the
  customer's email address, and creates or updates that contact with the customer's name,
  billing address and email address.
* Once a day, and when you click "Save and test connection": a connection check that requests
  your own organisation profile. No customer data is included in this check.

No data is sent to any other external service, and no customer data leaves your site until
you create an invoice yourself.

Lexware Office Public API terms: [Public API Lizenz- und Nutzungsbedingungen](https://agb.lexware.de/lexware-office/public-api-lizenz--und-nutzungsbedingungen)
Lexware privacy information: [Datenschutz & Datensicherheit](https://www.lexware.de/datenschutz-datensicherheit/)

= What this version does not do =

Invoices in this version are created by hand, from a button on the order screen. Automatic
invoicing on order status, bulk invoice creation, payment status sync, automatic PDF delivery,
payment reminders and large-scale bulk import are part of
[Nota Invoice Sync Pro](https://www.wp-nota.com/lexware-invoice-sync).

== Privacy ==

When you create an invoice, the customer's name, billing address, email address and the order's
line items are sent to Lexware Office (operated by Haufe-Lexware GmbH & Co. KG) over their public
API. That data is then stored and processed under Lexware's own privacy policy. Once an invoice
is finalised, German accounting law (GoBD) requires it to be kept for ten years — this plugin
does not delete or modify anything in Lexware Office, including in response to a WordPress
personal-data erasure request. The plugin integrates with Tools → Export/Erase Personal Data and
explains this retention conflict there, so shop owners never wrongly assume the data was erased
everywhere.

The optional debug log level records customer names, addresses and invoice contents in plain
text in the WooCommerce log files on your own server. The settings page says so next to the
option; turn debug logging off once things are working.

No data is sent anywhere until you actually create an invoice for an order.

Lexware and Lexware Office are trademarks of Haufe-Lexware GmbH & Co. KG. This plugin is an
independent product and is not affiliated with or endorsed by Haufe-Lexware.

== Installation ==

1. Make sure WooCommerce is installed and active.
2. Upload and activate the plugin.
3. Go to WooCommerce → Lexware Invoices.
4. Create a personal API key in Lexware Office (Public API settings at app.lexware.de) and
   paste it in.
5. Click "Save and test connection". The plugin also checks your Lexware tax configuration
   against your shop's and warns you if the two disagree.
6. Open any order and use the "Create invoice now" button in the Lexware Office invoice panel.

== Frequently Asked Questions ==

= Is this an official Lexware plugin? =

No. This is an independent product, not affiliated with or endorsed by Haufe-Lexware GmbH &
Co. KG. It talks to your Lexware Office account through the official public API, with an API
key you create yourself.

= I know this service as "lexoffice" — is this the same thing? =

Yes. lexoffice is the former name of Lexware Office. This plugin uses the current
api.lexware.io gateway.

= Does this work with a free Lexware Office account? =

You need a Lexware Office account with API access enabled under the Public API settings.
Whether your plan includes this depends on your Lexware Office subscription.

= Which currencies are supported? =

Only EUR. Lexware Office's invoicing does not support other currencies at the time of writing,
so orders in another currency are not invoiced and the reason is logged.

= Does this plugin validate EU VAT numbers (VIES)? =

No. Nota Invoice Sync reads whatever VAT-ID a dedicated VAT compliance plugin (one that adds a
VAT number field at checkout, for example) has already saved on the order, and uses it to work
out reverse-charge treatment. It does not query the VIES database itself. If your shop needs
live VAT-ID validation or automatic tax exemption at checkout, use a plugin built specifically
for that.

= Does it support HPOS (High-Performance Order Storage)? =

Yes. The plugin declares HPOS compatibility and works with both classic and HPOS order
storage.

= Draft or finalised — which should I choose? =

Start with drafts. A draft ("Entwurf") can still be edited or deleted in Lexware Office and is
not booked. A finalised invoice is permanent, is recorded for GoBD and gets its e-invoice
document — it can only be reversed with a credit note, never deleted. Switch to finalising
once you have checked a few drafts.

= Can the plugin change or delete an invoice in Lexware Office? =

No. The plugin only ever creates documents. It never modifies or deletes anything in your
Lexware Office account — not even when the plugin itself is uninstalled.

= What happens to orders placed before I installed the plugin? =

Nothing automatically. Open the order and use the manual "Create invoice now" button.

= Something went wrong — where do I look? =

The order screen shows the last error for that order in the Lexware Office invoice panel.
Full logs are under WooCommerce → Status → Logs, source "nota-invoice-sync".

== Screenshots ==

1. The Lexware Office invoice panel on the order screen — status, actions and a credit note button.
2. Settings — Connection: add your Lexware Office API key.
3. Settings — When to create invoices: manual creation in this version, plus draft/finalise document mode.
4. Settings — Invoice content: language, payment terms, supply date and custom invoice text.
5. Settings — Automation: PDF delivery, payment reminders and payment status sync (available in Pro).
6. Settings — Diagnostics: order notes, test mode, logging and uninstall behaviour.
7. Settings — Compliance export: a GoBD-ready CSV of every finalised invoice (available in Pro).

== Changelog ==

= 0.1.7 =
* Fixed: after clicking "Save and test connection", a successful connection could still show a "Connection failing" badge on the settings page — that badge was reading a separate, once-a-day cached status instead of the result just tested. It now updates immediately with the same result, at no extra API cost.

= 0.1.6 =
* Added: a "VAT-ID meta keys" setting (Invoice content) listing the order meta keys checked for a customer's VAT-ID, pre-filled with the most common ones and editable — no code required to support a VAT compliance plugin that is not already covered.
* Added: a note on the settings page and in the FAQ clarifying that this plugin does not validate EU VAT numbers against VIES itself; it reads whatever VAT-ID a dedicated VAT compliance plugin has already saved on the order.

= 0.1.5 =
* Fixed: the invoice date sent to Lexware Office was the order's payment/creation date instead of the actual date the invoice document is issued — these are two separate legally required pieces of information under German invoicing rules (§14 UStG), and using the wrong one could backdate an invoice into an already-closed VAT reporting period when it is created some time after the order.
* Added: orders consisting entirely of virtual, downloadable products are now billed under the correct "electronic services" tax treatment (for shops on the destination-country VAT principle) rather than being lumped in with physical distance sales.
* Fixed: a customer's Lexware contact was marked as eligible for tax-free invoicing merely for having a VAT ID on file, even on a domestic order where normal VAT was actually charged. This flag now only gets set when the order genuinely qualifies as an intra-community reverse charge.

= 0.1.4 =
* Fixed: EU B2C orders could be sent to Lexware Office with the destination country's tax rate (e.g. 20%) even though the connected account only accepts German rates (origin-country taxation) — this either failed with a confusing "Invalid taxRatePercentage" error, or in rarer cases could silently invoice at the wrong German rate. Such orders are now refused up front with a clear explanation, and nothing is sent to Lexware Office. Orders taxed at German rates, including small-value items affected by per-line rounding, are unaffected.

= 0.1.3 =
* Added: a visual indicator on the product edit screen (Shipping tab, and each variation row) showing that per-product delivery date overrides are available in Nota Invoice Sync Pro. No new functionality in this version.

= 0.1.2 =
* Reduced the number of Pro upgrade mentions on the settings page: removed the standalone banner and combined three separate "Available in Pro" links into one.
* Fixed the Contributors field in this readme.

= 0.1.1 =
* Fixed: test mode could still create or update a real Lexware Office contact even though no invoice was sent — test mode now makes no API request at all, including contact lookups.
* Fixed: the daily connection check and the "Check payment status" button did not consistently respect test mode — now every feature follows the same "test mode = no API traffic" rule.
* Added: a "Refresh status" button on the order screen, to pick up an invoice that was finalised directly in Lexware Office rather than through this plugin.
* Added: a GPL license file, and expanded the privacy and external-services documentation in this readme.
* Added: visual indicators on the settings page and order screen showing which additional features (automation, PDF delivery, payment reminders, credit notes, PDF download) are available in Nota Invoice Sync Pro.

= 0.1.0 =
* Initial release.
