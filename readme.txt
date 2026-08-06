=== Unwan – Address Library for WooCommerce ===
Contributors: smartwpplugins, jeetsaha86
Tags: woocommerce, address book, multiple addresses, checkout, checkout block
Requires at least: 6.5
Tested up to: 7.0
Requires PHP: 7.4
Stable tag: 1.0.0
License: GPL-2.0-or-later
License URI: https://www.gnu.org/licenses/gpl-2.0.html
Requires Plugins: woocommerce
WC requires at least: 8.2
WC tested up to: 10.9

A single, unified address book for WooCommerce — one saved-address library for checkout and My Account, with full Checkout Blocks support.

== Description ==

[**Full documentation →**](https://docs.smartwpplugins.com/unwan) — setup, every setting explained, theming, and the complete developer hooks reference.

Most WooCommerce stores make customers manage two separate address lists — a billing address and a shipping address — and re-enter the same details every time they order. Unwan replaces that with **one address book**: every saved address is simply available everywhere, at checkout and in My Account, with no duplication and no confusion about which list a customer is editing.

Unwan ships this feature set complete, with no artificial limits, no "Pro" upsell, and no locked settings.

The full source, including the unminified JavaScript behind every compiled `build/` file, is maintained publicly on GitHub at [Smart-WP-Plugins/unwan-address-library-for-woocommerce](https://github.com/Smart-WP-Plugins/unwan-address-library-for-woocommerce) — that's also where to file a bug report or feature request as an issue.

= One address book. Not two. =

Instead of a billing list and a separate shipping list, Unwan keeps a single, deduplicated library per customer:

* The WooCommerce billing default, shipping default, and every additional saved address all live in one list.
* An address can be flagged as the billing default, the shipping default, both, or neither — there's no second copy to keep in sync.
* Reassigning a default is lossless: the address that gets replaced simply drops back into the shared library instead of disappearing.
* Duplicate addresses are detected automatically and merged, so customers never end up with the same address saved three times under three different labels.

Fewer lists to think through means fewer decisions at checkout — which is exactly where every extra click costs you conversions.

= Built natively for the block-based checkout =

Unwan is built for the block-based checkout from the ground up: the billing and shipping selectors are registered as genuine, forced inner blocks of WooCommerce's own Checkout Blocks, using the official Blocks integration registry and Store API — the same extension points WooCommerce itself recommends. Classic (shortcode) checkout gets the identical picker, behavior, and labels, so stores that haven't migrated yet lose nothing.

= A genuinely well-designed picker, not a repurposed dropdown =

The address picker is a real interface, not a `<select>` element with a new coat of paint:

* A collapsed summary with a one-tap "Change" action, so returning customers see their address and move on.
* An accessible radio-group list with full keyboard support (arrow keys, Home, End) for choosing between saved addresses.
* Instant search that appears automatically once a customer has enough saved addresses to need it — configurable, and identical at checkout and in My Account.
* A three-dot action menu in My Account for edit, delete, and "make default," with protected actions clearly disabled instead of hidden.
* Zero layout shift, zero custom elements, zero Shadow DOM — just clean, inspectable, standard-DOM HTML that any theme developer can read and override.

= Light, dark, and system themes — with your brand's accent color =

Unwan gives you light, dark, and system ("follow the customer's device") theme modes plus a full accent color: pick one, and every button, focus ring, badge, and selected state throughout the picker and My Account updates to match — no custom CSS required. Developers get a small, documented set of `--unwan-*` CSS variables and stable BEM classes if they want to go further.

= Fast, because it isn't doing anything it doesn't need to =

Unwan adds no custom database tables. It stores additional addresses in a single namespaced user-meta record and reads WooCommerce's own billing/shipping fields for the two profile defaults — nothing new for your host to back up or your site to slow down. Scripts and styles load only on the checkout and My Account pages, address-book results are cached for the duration of the request, and there is no telemetry, tracking, or external service call of any kind — not even a "phone home" for update checks beyond the standard WordPress.org channel.

Under the hood, Unwan is written with PSR-4 autoloading and namespaced classes (`Unwan\AddressLibrary`), and every PHP file is checked against the WordPress Coding Standards ruleset before release. That's not something most customers will ever see directly — but it's why the plugin stays fast, why it doesn't conflict with other well-built plugins, and why it's safe to build on.

= Every part of it is configurable =

Nothing here is hard-coded. From **WooCommerce → Settings → Accounts & Privacy → Unwan** you control:

* Whether the billing and/or shipping selector appears at checkout at all.
* The maximum number of additional addresses a customer may save (or unlimited).
* The address count at which the search field appears, at checkout and in My Account.
* Whether addresses entered at checkout are saved automatically, and whether a new address should become the matching profile default.
* Light, dark, or system color mode, plus a single accent color that reflows through the entire interface.
* Every customer-facing label and heading — page titles, button text, empty-state copy, picker headings — all editable, with translations still able to override your text.
* Whether uninstalling the plugin removes Unwan's data, or leaves it in place (off by default).

= Features at a glance =

* One combined address book: WooCommerce billing default, shipping default, and unlimited shared additional addresses.
* Every saved address is available in both the billing and shipping checkout selectors.
* Native Checkout Blocks integration via WooCommerce's Blocks registry and Store API, plus a matching classic-checkout experience.
* Add, edit, delete, and "make default" actions in My Account — nonce-protected, validated, and sanitized end to end.
* Lossless default-address swaps: reassigning a default never deletes the address it replaces.
* Automatic duplicate detection and merging for newly entered checkout addresses.
* Configurable saved-address limit and a shared checkout/My Account search threshold.
* Light, dark, and system color modes with a configurable accent color.
* A concise `--unwan-*` CSS variable API and stable BEM classes for theme developers.
* Fully keyboard-accessible picker (arrow keys, Home, End, proper `role="radio"` semantics).
* HPOS and Cart & Checkout Blocks compatibility declared and tested.
* Developer hooks for every filterable behavior — see the plugin's inline documentation.
* Optional, opt-in complete data cleanup on uninstall.
* No custom database tables, no tracking, no telemetry, no upsell nags.

Unwan is developed and maintained by [SmartWP Plugins](https://smartwpplugins.com/).

= More from SmartWP Plugins =

[**WP CodeKit**](https://www.wpcodekit.com/) — Every WordPress generator. One place. Zero guesswork. A growing library of WordPress code generators — CSS, PHP, hooks, and more — each with a live preview, so you see exactly what you're shipping before you copy a single line.

[**CSS Gradient Generator**](https://www.wpcodekit.com/tools/gradient) — The gradient tool that shows its work: build multi-stop, multi-angle CSS gradients visually and grab production-ready code, no manual color-stop math.

[**GrowQuest**](https://growquest.io/) — Custom WordPress development, for when off-the-shelf isn't enough. Our development studio for custom WordPress and WooCommerce builds — plugins, themes, and full-site work — for stores and businesses that have outgrown generic solutions.

== Installation ==

**Requirements:** WordPress 6.5+, PHP 7.4+, and WooCommerce 8.2+ active. Install and
activate WooCommerce first if you haven't already — Unwan will not activate without it.

= From your WordPress dashboard =

1. Go to **Plugins > Add New Plugin** and search for "Unwan".
2. Click **Install Now**, then **Activate**.
3. Go to **WooCommerce > Settings > Accounts & Privacy** and open the **Unwan** subsection
   to review or change the defaults (see "Configuration" below). The plugin works
   immediately after activation even if you skip this step.

= Manual upload =

1. Download `unwan-for-woocommerce.zip`.
2. In your dashboard, go to **Plugins > Add New Plugin > Upload Plugin** and choose the
   zip file, or upload the unzipped `unwan-for-woocommerce` directory to
   `/wp-content/plugins/` via FTP/SFTP.
3. Go to **Plugins**, find "Unwan – Address Library for WooCommerce", and click **Activate**.
4. Go to **WooCommerce > Settings > Accounts & Privacy > Unwan** to review the defaults.

= Configuration =

Every setting lives on the **WooCommerce > Settings > Accounts & Privacy > Unwan** subsection:

* Turn the billing and/or shipping checkout selector on or off.
* Set the maximum number of additional addresses a customer may save (`0` for unlimited).
* Set the address count at which the search field appears.
* Choose whether addresses entered at checkout save automatically, and whether a new
  address should also become the matching billing/shipping default.
* Pick light, dark, or system color mode and an accent color.
* Edit any customer-facing label or heading.
* Opt in to removing Unwan's data on uninstall (off by default).

No setting is required to get started — reasonable defaults are already in place after
activation.

= Using it =

Once active, signed-in customers can manage their addresses under **My Account > Address
book**: add a new address, edit or delete an existing one, and mark an address as the
billing and/or shipping default. At checkout, a saved-address picker appears above the
billing and/or shipping fields for any signed-in customer with at least one saved address,
letting them pick a saved address or enter a new one; it's hidden automatically for guests
and customers with nothing saved yet.

== Screenshots ==

1. My Account address book — billing and shipping default cards plus one combined, searchable list of every saved address, with default badges and a three-dot action menu for edit, make default, and delete.
2. Adding or editing an address — a single form for every address, with checkboxes to set it as the default shipping and/or billing address.
3. Checkout Blocks — the saved-address picker appears above the native shipping and billing fields, with search once the list grows and an "Enter a new address" option.
4. Classic checkout — the identical picker, search, and labels on the shortcode-based checkout for stores that haven't moved to Checkout Blocks.
5. Store settings — WooCommerce > Settings > Accounts & Privacy > Unwan, where every selector, limit, threshold, default behavior, color mode, and label is configurable.
6. Responsive on mobile — the address list becomes full-width cards and the checkout picker stays a comfortable, one-thumb-wide tap target.

== Frequently Asked Questions ==

= Where can I find full documentation? =

At [docs.smartwpplugins.com/unwan](https://docs.smartwpplugins.com/unwan) — setup, every setting, theming, and the complete developer hooks and JavaScript API reference.

= Is Unwan really free, with no premium tier? =

Yes. Every feature described here — unlimited saved addresses, Checkout Blocks and classic checkout support, light/dark/system theming, custom accent color, editable labels, and developer hooks — is included in the free plugin. There is no "Pro" version withholding functionality behind a paywall.

= Does Unwan support WooCommerce Checkout Blocks? =

Yes. Unwan registers forced billing and shipping inner blocks through WooCommerce's official Checkout Block integration registry and uses writable Store API extension data — not a shortcode or DOM hack layered on top of the block checkout.

= Will this slow down my store? =

No. Unwan creates no custom database tables, runs no extra queries beyond what WooCommerce already performs for customer data, caches its results for the duration of each request, and only loads its scripts and styles on the checkout and My Account pages. There is no tracking or external service call of any kind.

= Does selecting a saved address replace the customer's default? =

Not by default. A saved address is used for the current order without silently replacing the WooCommerce default. The store owner can optionally make newly entered checkout addresses the matching default under Accounts & Privacy > Unwan.

= Are newly entered checkout addresses saved automatically? =

Yes, by default. Store owners can disable automatic saving or make a newly entered address the matching profile default. An existing entry with the same normalized first name, last name, and first address line is reused instead of duplicated.

= What happens to the previous default address? =

When a different address becomes the billing or shipping default, the displaced address remains in the address book. It becomes a shared additional address unless it still holds the other default role.

= Can I customize the light/dark theme and accent color? =

Yes, directly from the settings screen — no CSS required. Choose light, dark, or "follow the customer's device," and pick any accent color; the entire interface, at checkout and in My Account, updates to match.

= Can developers customize the address interface further? =

Yes. Checkout and My Account share a concise set of `--unwan-*` variables, reusable BEM classes, and PHP filters. All presentation lives in `assets/css/unwan.css`, and the picker is standard light-DOM HTML with no custom elements or Shadow DOM to work around.

= What happens to data when Unwan is deleted? =

Data is retained by default. Store owners can enable "Uninstall cleanup" under WooCommerce > Settings > Accounts & Privacy > Unwan to permanently remove all Unwan settings and additional customer addresses during uninstall. WooCommerce billing and shipping profile addresses are never removed.

= Does the search field use browser address autocomplete? =

No. The saved-address filter uses `autocomplete="new-search"`, disables spelling and capitalization assistance, and includes opt-out hints for common password managers.

= Is Unwan built to WordPress coding standards? =

Yes. The codebase uses PSR-4 autoloading with namespaced classes, and every release is checked against the WordPress Coding Standards PHPCS ruleset (WordPress-Extra plus PHPCompatibilityWP) before publishing.

== Changelog ==

= 1.0.0 =

* Released Unwan with My Account address management, classic checkout support, and WooCommerce Checkout Block integration.
* Added the standard-DOM address picker with a scoped CSS reset, BEM classes, and a concise variable API.
* Added automatic checkout address saving, duplicate protection, configurable limits, and developer hooks.
* Added lossless billing and shipping default swaps.
* Added a dedicated WooCommerce settings subsection with editable labels, accent color, light/dark/system appearance, and checkout persistence preferences.
* Added opt-in uninstall cleanup and a direct Settings link on the Plugins screen.
* Reduced production files and added request-level address repository caching.

== Upgrade Notice ==
