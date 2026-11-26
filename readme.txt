=== Answely – AI Answers for Site Search ===
Contributors: lwitsolutions
Tags: search, ai, answers, site search, faq
Requires at least: 6.3
Tested up to: 7.2
Requires PHP: 7.4
Stable tag: 1.4.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Short AI answers with sources from your own pages, right in your site search. Processing in the EU.

== Description ==

[Answely](https://answely.com/) shows a short, AI-generated answer above the results of your site search, with links to the pages of your website that back it. If none of your pages backs an answer, the box says so instead of inventing one. Every answer is labelled as AI-generated, and your normal search keeps working at all times.

This plugin connects your WordPress site to your Answely account. You need an Answely account (a free plan is available) and a verified domain.

= What the plugin does =

* **Connection:** paste the site key, check the connection with one click (does not use your answer quota) and ask a test question right in the admin.
* **Search field:** the answer box attaches to the standard WordPress search field automatically. Design, colours, texts, position and the search fields it uses are set in your Answely account with a preview.
* **Answer field block and shortcode:** add a question field that shows the answer right below it, for example on a help or FAQ page: the block "Answely answer field" or `[answely]`. Without JavaScript or Answely it falls back to the normal search results page.
* **Where to load:** on every page, or only on search results and pages with the answer field.
* **Language:** follows the language of each page (works with Polylang and WPML) or a fixed language.
* **Consent:** optionally wait for the visitor's consent: the Answely script file is still loaded, but it fetches neither its configuration nor any answer before the visitor agrees. IAB TCF v2 and consented.eu are detected automatically; the plugin also takes consent from the WP Consent API (Complianz, CookieYes, Cookiebot and others) and offers a filter for any other tool.
* **Content sync (optional, Pro plan or higher):** sends new, changed and removed public content right away through the Answely Push API, with content type selection, excluded paths, a per-page exclusion, noindex detection (Yoast SEO, Rank Math, SEOPress), retries and index status.
* **Customer orders (optional, WooCommerce):** signed-in customers can open "My orders" next to the answer box. Order data stays on your site.

= Privacy =

* The plugin and the Answely box set no cookies and use no visitor identifier.
* On every page where it loads, the visitor's browser requests the Answely script file, and the script fetches the configuration of the box (site key and language) from Answely. With "Wait for consent", the script file is still requested, but the configuration and answers are fetched only after the visitor agrees.
* When a visitor searches or asks, the box sends the question, the language, the site key, the address of the page and the referring page (both without parameters) to Answely; for follow-up questions optionally also earlier questions and answers of the same box. By default Answely stores neither questions nor answers.
* Answely processes data on servers in the EU.
* Mention Answely in your privacy policy; text modules are in your Answely account.

== External services ==

This plugin connects to Answely, an AI answer service operated by LW IT Solutions (Answely). Terms of use: https://answely.com/en/legal/terms – Privacy policy: https://answely.com/en/legal/privacy – Data processing agreement: https://answely.com/en/legal/dpa

1. **Answer script for visitors** (`https://cdn.answely.com/answely.js`): loaded on the pages of your site once you have saved a site key (on every page, or only on search results and answer field pages, as you choose). The visitor's browser requests the script file like any file from another host, which transmits the IP address, the user agent and, as far as the browser's referrer policy allows, the address of the page; this also happens with "Wait for consent". On each page where it is loaded, the script then fetches the configuration of the box from `https://api.answely.com` (site key and language); with "Wait for consent" it fetches the configuration and answers only after the visitor agrees. When a visitor searches or asks, the script sends the question (at most 300 characters), the language, your site key, the page address and the referring page address (both without parameters) to `https://api.answely.com`. Optionally, earlier questions and answers of the same box are sent for follow-up questions. No cookies are set.
2. **Connection check** (`https://api.answely.com`): only when an administrator clicks "Check connection". Sends the site key and the language; returns whether Answely answers for the site.
3. **Test question** (`https://api.answely.com`): only when an administrator asks a test question in the admin. Sends the question, the language and the site key. A generated answer counts towards the monthly quota of your plan.
4. **Content sync** (`https://api.answely.com`): only after an administrator switches it on and sets a push token. When public content of the selected types is published, changed, unpublished or deleted, your server sends its address, title, plain text and language (or a deletion request for the address) with the push token. Switching the sync off, or changing the selection so that content is no longer sent, sends a deletion request for each address sent before. Queued deliveries and deletion requests are sent in the background through WP-Cron and continue after the plugin is deactivated and activated again. "Check index status" sends a status request with the push token.
5. **Customer orders** (`https://answely.com`): only with WooCommerce and after an administrator enters a one-time connection code. Pairing sends the code and your site address; Answely then reads the public route `/wp-json/answely/v1/connection` of your site (site key, whether WooCommerce is active, whether the customer panel is connected and, only during pairing, a hash of the code). Once, the first time a signed-in customer opens "My orders" after pairing, your server sends a signed request confirming that the panel works; when an administrator disconnects the customer orders, it sends a signed disconnect request. These two requests contain only the site key, the time, the kind of request and whether it confirms the pairing. Showing the orders does not depend on Answely: they are read from WooCommerce on your site and are never sent to Answely.

The plugin makes no other requests and contains no tracking. As with every request WordPress makes, requests from your server carry the WordPress version and the address of your site in the user agent. A developer can point the plugin to another Answely installation (for example a test installation) by defining the constant `ANSWELY_API_BASE` in wp-config.php; without that constant only the answely.com hosts above are contacted.

== Installation ==

1. Create a website in your [Answely account](https://answely.com/register) and verify your domain.
2. Install and activate the plugin.
3. Go to **Settings → Answely**, paste the site key from the "Embed" tab of your website in the Answely account and save.
4. Click **Check connection**, then ask a test question.
5. Optional: under **Display & privacy** choose where Answely loads and whether it waits for consent; under **Content sync** enter the push token and switch the sync on.

== Frequently Asked Questions ==

= Do I need an Answely account? =

Yes. The answers are generated by the Answely service. Plans, including a free plan, are listed at https://answely.com/pricing.

= Where do the answers come from? =

Only from sections of your own website that Answely has indexed for your verified domain (by crawling it, or through the content sync of this plugin). Each answer links to these sources. Without a backing source, the box shows your notice text instead.

= The box does not appear. What can I check? =

Click "Check connection" under Settings → Answely. The domain must be verified in your account, the box switched on and the first index ready (large sites take a while). On a page with a search field, run `Answely.diagnose()` in the browser console. A Content Security Policy must allow the Answely script and API hosts, and optimization plugins should not combine or defer the Answely script.

= Does the plugin work with my consent tool? =

IAB TCF v2 tools and consented.eu are detected by the Answely script itself. Tools that support the WP Consent API (for example Complianz, CookieYes or Cookiebot) work through the plugin; choose the consent category under Display & privacy. Any other tool can call `Answely('zustimmung', true)` on consent and `Answely('zustimmung', false)` on withdrawal; developers can add such a bridge with the `answely_inline_script` filter.

= Is private content sent to Answely? =

No. The content sync sends only published, public content of the selected types: no drafts, private or password-protected posts, nothing marked as noindex, nothing on excluded paths or excluded in the editor. For membership plugins, return false from the `answely_sync_oeffentlich` filter for protected content. The Answely answer API is public, so never send confidential content.

= Where is the push token stored? =

In the WordPress database, not autoloaded and never shown again; or define `ANSWELY_PUSH_TOKEN` in wp-config.php, which takes precedence. The token never appears in your pages.

= What happens when I delete the plugin? =

All settings of the plugin are removed. Documents sent by the content sync stay at Answely: switch the sync off and wait until nothing is pending before deleting the plugin.

== Screenshots ==

1. Connection tab: setup checklist, site key, connection check and test question.
2. Display & privacy: where Answely loads, answer language and consent options.
3. Content sync: status, content types, excluded paths and push token.
4. The Answely answer field block in the editor.

== Changelog ==

= 1.4.0 =
* Settings page: status badge next to the title, a progress ring for the setup steps and icons on the tabs, which now work as an accessible tab list (arrow keys, Home, End).
* Content sync: tiles and a share bar for accepted, pending and rejected documents, a status badge, and rejected content as a table with edit links.
* Checks and test questions show a loading indicator and a coloured result with an icon; the test question shows a character counter.
* Copy buttons for the shortcode and the consent calls, with feedback and a fallback that selects the text.
* Resetting the sync state and disconnecting customer orders ask for confirmation in a dialog of the admin.
* The Help tab shows how Answely works as four steps; the answer field has clearer focus rings, larger touch targets and works on light and dark themes.

= 1.3.1 =
* Readme and settings texts describe exactly what is sent and when, including the configuration request of the script and what "Wait for consent" does.
* Customer orders are shown without asking Answely first; Answely is only told once that the panel works and when it is disconnected.
* The JavaScript of the settings page, the block and the answer field is now in readable files.
* The service address can only be changed with the ANSWELY_API_BASE constant in wp-config.php; the test question waits at most 30 seconds; content sync works without the mbstring extension.
* Requires WordPress 6.3 (block API version 3).

= 1.3.0 =
* New settings page with tabs, setup checklist, connection check and test question.
* New: answer field block and `[answely]` shortcode.
* New: load on every page or only where needed; fixed or automatic answer language.
* New: consent from the WP Consent API; filter for other consent tools.
* Content sync: content type selection, excluded paths, per-page exclusion, SEOPress noindex, page language from Polylang/WPML, headings kept, rejected documents no longer block the queue, "Process queue now", index status, push token can be saved in the settings.
* Customer orders: clearer setup and messages; the connection uses the same service as the rest of the plugin.
* The script is now enqueued the WordPress way (in the footer, async).

= 1.2.0 =
* Optional WooCommerce customer orders panel.

= 1.1.0 =
* Optional public content synchronization with changes, withdrawals, retry queue and delivery status.

= 1.0.0 =
* First version: site key, consent mode, script tag in the page head.

== Upgrade Notice ==

= 1.4.0 =
Clearer settings page with status, progress and accessible tabs. No change to what is sent. Existing settings are kept.

= 1.3.1 =
Clearer privacy texts, customer orders without a status request to Answely, readable JavaScript files. Requires WordPress 6.3.

= 1.3.0 =
New settings page with connection check, test question, answer field block and more consent and sync options. Existing settings are kept.
