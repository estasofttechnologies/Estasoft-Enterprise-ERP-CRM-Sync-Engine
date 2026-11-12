=== Estasoft Enterprise ERP/CRM Sync Engine ===
Contributors: estasoft
Tags: woocommerce, erp, crm, accounting, sync
Requires at least: 6.4
Tested up to: 7.1
Requires PHP: 8.1
Requires Plugins: woocommerce
Stable tag: 0.1.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Keeps WooCommerce in step with your ERP, accounting system or CRM through a queue, so checkout never waits. Orders, customers, stock and tracking.

== Description ==

Estasoft Enterprise ERP/CRM Sync Engine sends WooCommerce orders, customers and refunds to the system your business runs on, and reads stock, delivery tracking and invoice status back. Every order event is saved to a queue in your own database, in the same step that saves the order, so a slow or offline ERP never holds up checkout.

The plugin is free, with every feature included: no paid tier, licence key, trial or usage limit, no telemetry and no Estasoft server. It talks only to the system you connect (see External services). It never signs or certifies a tax invoice: the connected system does that.

= Supported systems =

* ERP: Odoo, ERPNext, Microsoft Dynamics 365 Business Central, Dynamics 365 Finance and Supply Chain, NetSuite, SAP Business One, SAP S/4HANA, Oracle Fusion Cloud ERP, Sage 200, Sage 300, Sage X3, Acumatica, Holded.
* Accounting: QuickBooks Online, Xero, Zoho Books, MYOB, Sage Business Cloud Accounting, Qoyod, Wafeq, Daftra, Lexware Office, sevDesk, Exact Online, Moneybird, Fortnox.
* CRM: HubSpot, Zoho CRM, Salesforce, Dynamics 365 Sales, Pipedrive, SuiteCRM.
* Files you import yourself (nothing is sent anywhere): DATEV, Sage 50, TallyPrime.

Pennylane and SMACC are listed but cannot be selected yet.

Before you go live: every connector was built from the vendor's public documentation and tested against a simulated copy of its API; none has been tried with a live account yet. Start with Shadow mode, a dry run that changes nothing in either system, and compare the results first.

= Features =

* Durable queue with retries, a circuit breaker and no duplicate orders: each order has a reference (WC-{order number}) checked before anything is created.
* Preview what your system will receive, and a Shadow mode that changes nothing.
* Readable mapping rules with tax mapping, SKU matching and rollback; failed orders grouped by cause, with a fix.
* Stock, tracking and invoice status back, a nightly reconciliation, a dashboard and email alerts.
* Encrypted credentials. HPOS and the block checkout are supported.

= Trademarks =

All product and company names are trademarks of their owners, used only to say which systems the plugin works with. The plugin is independent of them and includes no vendor logos.

== External services ==

The plugin has no telemetry, no analytics, no update server and no Estasoft server. It contacts only the system you connect, and only after you have entered that system's credentials and saved the connection. It sends only the data your mapping selects, to create orders, customers and credit notes there and read stock, tracking and status back. Full card numbers are never read, and nothing is sent to anyone else. Where a system has a sign-in page, your own browser goes to it. A host written `*.example.com` stands for the address of your own account with that vendor. Private network addresses are refused unless a site owner allows them.

For every system below the plugin does the same thing. It creates the records named under "Used for" for your WooCommerce orders and reads stock, tracking and status back where the system offers them. It sends the credentials or sign-in tokens named under "Sends", plus the order, customer and product data your mapping selects (order number, date, totals, line items with SKU, quantity, price and tax, shipping, fees, coupons, name, email, phone, addresses and buyer tax number); nothing else. It does so when you press Test connection, for each queued order, refund or customer event, and for the scheduled stock, tracking, status and reconciliation reads.

= Acumatica =
Used for: sales orders, customers and credit notes. Sends: the client ID and secret of your own app, and its sign-in tokens. Contacts: only the address you enter (your own server or your provider's).

Terms: https://www.acumatica.com/terms-of-use/ | Privacy: https://www.acumatica.com/website-privacy-statement

= Dynamics 365 Finance and Supply Chain =
Used for: sales orders, customers and credit notes. Sends: your app's client ID and secret (or signed assertion), and its access tokens. Contacts: `*.dynamics.com`, `login.microsoftonline.com`.

Terms: https://learn.microsoft.com/en-us/legal/microsoft-apis/terms-of-use | Privacy: https://privacy.microsoft.com/privacystatement

= ERPNext =
Used for: sales orders, customers and credit notes. Sends: your API key or token. Contacts: only the address you enter (your own server or your provider's).

Terms: https://frappe.io/terms | Privacy: https://docs.frappe.io/legal/policies/privacy-policy

= Holded =
Used for: sales orders, customers and credit notes. Sends: your API key or token. Contacts: `api.holded.com`.

Terms: https://www.holded.com/tac | Privacy: https://www.holded.com/privacy

= Microsoft Dynamics 365 Business Central =
Used for: sales orders, customers and credit notes. Sends: your app's client ID and secret (or signed assertion), and its access tokens. Contacts: `api.businesscentral.dynamics.com`, `login.microsoftonline.com`.

Terms: https://learn.microsoft.com/en-us/legal/microsoft-apis/terms-of-use | Privacy: https://privacy.microsoft.com/privacystatement

= NetSuite =
Used for: sales orders, customers and credit notes. Sends: your app's client ID and secret (or signed assertion), and its access tokens. Contacts: `*.suitetalk.api.netsuite.com`.

Terms: https://www.oracle.com/legal/terms/ | Privacy: https://www.oracle.com/legal/privacy/privacy-policy.html

= Odoo =
Used for: sales orders, customers and credit notes. Sends: your API key or token. Contacts: only the address you enter (your own server or your provider's).

Terms: https://www.odoo.com/legal | Privacy: https://www.odoo.com/privacy

= Oracle Fusion Cloud ERP =
Used for: sales orders, customers and credit notes. Sends: your app's client ID and secret (or signed assertion), and its access tokens. Contacts: `*.oraclecloud.com`.

Terms: https://www.oracle.com/legal/terms/ | Privacy: https://www.oracle.com/legal/privacy/privacy-policy.html

= Sage 200 =
Used for: sales orders, customers and credit notes. Sends: the client ID and secret of your own app, and its sign-in tokens. Contacts: `id.sage.com`, `api.columbus.sage.com`.

Terms: https://www.sage.com/en-gb/legal/ | Privacy: https://www.sage.com/en-gb/legal/privacy-and-cookies/

= Sage 300 =
Used for: sales orders, customers and credit notes. Sends: your user name and password. Contacts: only the address you enter (your own server or your provider's).

Terms: https://developer.sage.com/300/ | Privacy: https://www.sage.com/en-gb/legal/privacy-and-cookies/

= Sage X3 =
Used for: sales orders, customers and credit notes. Sends: your sign-in details. Contacts: only the address you enter (your own server or your provider's).

Terms: https://www.sage.com/en-gb/legal/ | Privacy: https://www.sage.com/en-gb/legal/privacy-and-cookies/

= SAP Business One =
Used for: sales orders, customers and credit notes. Sends: your user name and password. Contacts: only the address you enter (your own server or your provider's).

Terms: https://www.sap.com/about/legal/terms-of-use.html | Privacy: https://www.sap.com/about/legal/privacy.html

= SAP S/4HANA =
Used for: sales orders, customers and credit notes. Sends: your app's client ID and secret (or signed assertion), and its access tokens. Contacts: `*.s4hana.cloud.sap`.

Terms: https://www.sap.com/about/legal/terms-of-use.html | Privacy: https://www.sap.com/about/legal/privacy.html

= Daftra =
Used for: invoices or sales orders, credit notes and customers. Sends: your API key or token. Contacts: `*.daftara.com`, `*.daftra.com`.

Terms: https://www.daftra.com/en/terms-conditions/ | Privacy: https://www.daftra.com/en/privacy-policy/

= Exact Online =
Used for: invoices or sales orders, credit notes and customers. Sends: the client ID and secret of your own app, and its sign-in tokens. Contacts: `start.exactonline.nl`, `start.exactonline.be`, `start.exactonline.de`, `start.exactonline.fr`, `start.exactonline.co.uk`, `start.exactonline.es`, `start.exactonline.com`.

Terms: https://files.exact.com/static/downloads/terms-and-conditions/2018/Exact-Exact-Online-App-Center-Terms-and-Conditions-of-Use-October-2018.pdf | Privacy: https://files.exact.com/static/web/pdf/Privacy-Policy-for-Exact-Services-EN.pdf

= Fortnox =
Used for: invoices or sales orders, credit notes and customers. Sends: the client ID and secret of your own app, and its sign-in tokens. Contacts: `api.fortnox.se`, `apps.fortnox.se`.

Terms: https://www.fortnox.se/developer/guides-and-good-to-know/legal-and-change-policies | Privacy: https://www.fortnox.se/om-fortnox/integritet-och-sakerhet

= Lexware Office =
Used for: invoices or sales orders, credit notes and customers. Sends: your API key or token. Contacts: `api.lexware.io`.

Terms: https://agb.lexware.de/lexware-office/public-api-lizenz--und-nutzungsbedingungen | Privacy: https://datenschutz.lexware.de/

= Moneybird =
Used for: invoices or sales orders, credit notes and customers. Sends: your API key or token. Contacts: `moneybird.com`.

Terms: https://www.moneybird.nl/terms/ | Privacy: https://www.moneybird.nl/privacy/

= MYOB =
Used for: invoices or sales orders, credit notes and customers. Sends: the client ID and secret of your own app, and its sign-in tokens. Contacts: `api.myob.com`, `arl2.api.myob.com`, `secure.myob.com`.

Terms: https://developer.myob.com/program/terms-conditions/ | Privacy: https://www.myob.com/au/legal/privacy-policy

= Qoyod =
Used for: invoices or sales orders, credit notes and customers. Sends: your API key or token. Contacts: `api.qoyod.com`.

Terms: https://www.qoyod.com/en/terms-and-conditions/ | Privacy: https://www.qoyod.com/privacy-policy/

= QuickBooks Online =
Used for: invoices or sales orders, credit notes and customers. Sends: the client ID and secret of your own app, and its sign-in tokens. Contacts: `quickbooks.api.intuit.com`, `sandbox-quickbooks.api.intuit.com`, `appcenter.intuit.com`, `oauth.platform.intuit.com`.

Terms: https://developer.intuit.com/app/developer/qbo/docs/legal-agreements/intuit-terms-of-service-for-intuit-developer-services | Privacy: https://www.intuit.com/privacy/statement/

= Sage Business Cloud Accounting =
Used for: invoices or sales orders, credit notes and customers. Sends: the client ID and secret of your own app, and its sign-in tokens. Contacts: `api.accounting.sage.com`, `oauth.accounting.sage.com`, `www.sageone.com`.

Terms: https://www.sage.com/en-gb/legal/ | Privacy: https://www.sage.com/en-gb/legal/privacy-and-cookies/

= sevDesk =
Used for: invoices or sales orders, credit notes and customers. Sends: your API key or token. Contacts: `my.sevdesk.de`.

Terms: https://sevdesk.de/agb/ | Privacy: https://sevdesk.de/datenschutz/

= Wafeq =
Used for: invoices or sales orders, credit notes and customers. Sends: your API key or token. Contacts: `api.wafeq.com`.

Terms: https://wafeq.com/en/terms-of-service | Privacy: https://wafeq.com/en/privacy-policy

= Xero =
Used for: invoices or sales orders, credit notes and customers. Sends: the client ID and secret of your own app, and its sign-in tokens. Contacts: `api.xero.com`, `identity.xero.com`, `login.xero.com`.

Terms: https://developer.xero.com/xero-developer-platform-terms-conditions/ | Privacy: https://www.xero.com/legal/privacy/

= Zoho Books =
Used for: invoices or sales orders, credit notes and customers. Sends: the client ID and secret of your own app, and its sign-in tokens. Contacts: `accounts.zoho.com`, `accounts.zoho.eu`, `accounts.zoho.in`, `accounts.zoho.com.au`, `accounts.zoho.jp`, `accounts.zoho.sa`, `accounts.zohocloud.ca`, `accounts.zoho.com.cn`, `www.zohoapis.com`, `www.zohoapis.eu`, `www.zohoapis.in`, `www.zohoapis.com.au`, `www.zohoapis.jp`, `www.zohoapis.sa`, `www.zohoapis.ca`, `www.zohoapis.com.cn`.

Terms: https://www.zoho.com/terms.html | Privacy: https://www.zoho.com/privacy.html

= Dynamics 365 Sales =
Used for: deals or opportunities, contacts and refund notes. Sends: your app's client ID and secret (or signed assertion), and its access tokens. Contacts: `*.dynamics.com`, `login.microsoftonline.com`.

Terms: https://learn.microsoft.com/en-us/legal/microsoft-apis/terms-of-use | Privacy: https://privacy.microsoft.com/privacystatement

= HubSpot =
Used for: deals or opportunities, contacts and refund notes. Sends: your API key or token. Contacts: `api.hubapi.com`.

Terms: https://legal.hubspot.com/developer-terms | Privacy: https://legal.hubspot.com/privacy-policy

= Pipedrive =
Used for: deals or opportunities, contacts and refund notes. Sends: your API key or token. Contacts: `*.pipedrive.com`.

Terms: https://www.pipedrive.com/en/terms-of-service | Privacy: https://www.pipedrive.com/en/privacy

= Salesforce =
Used for: deals or opportunities, contacts and refund notes. Sends: the client ID and secret of your own app, and its sign-in tokens. Contacts: `*.salesforce.com`.

Terms: https://www.salesforce.com/company/legal/program-agreement/ | Privacy: https://www.salesforce.com/company/privacy/

= SuiteCRM =
Used for: deals or opportunities, contacts and refund notes. Sends: your API key or token. Contacts: only the address you enter (your own server or your provider's).

Terms: none; SuiteCRM is open source software (AGPL) that runs on your own server, so no vendor processes your data | Privacy: https://suitecrm.com/privacy

= Zoho CRM =
Used for: deals or opportunities, contacts and refund notes. Sends: the client ID and secret of your own app, and its sign-in tokens. Contacts: `accounts.zoho.com`, `accounts.zoho.eu`, `accounts.zoho.in`, `accounts.zoho.com.au`, `accounts.zoho.jp`, `accounts.zoho.sa`, `accounts.zohocloud.ca`, `accounts.zoho.com.cn`, `www.zohoapis.com`, `www.zohoapis.eu`, `www.zohoapis.in`, `www.zohoapis.com.au`, `www.zohoapis.jp`, `www.zohoapis.sa`, `www.zohoapis.ca`, `www.zohoapis.com.cn`.

Terms: https://www.zoho.com/crm/zohocrm-terms.html | Privacy: https://www.zoho.com/privacy.html

= File exports: DATEV, Sage 50, TallyPrime =
These connectors contact no server and send nothing over the network. They write an import file in a protected folder of your uploads directory, which you download and import yourself. Files are deleted after 30 days and with the plugin's data.

= Not available in this version =
Pennylane (Pennylane requires OAuth for production integrations (its company tokens are for sandbox testing) and its API terms limit use to internal business purposes, so this connector cannot be offered until both are cleared with Pennylane); SMACC (SMACC has not published an API reference, so there is nothing for the plugin to connect to yet). The plugin lists them but cannot connect to them and contacts nothing for them.

== Third-party libraries and services ==

* The plugin contains no third-party PHP library. Encryption uses libsodium, which is part of PHP.
* The country, state and phone tables in `includes/Mapping/Transforms/Data/` are the plugin's own compilation of public codes (ISO 3166, ITU E.164).
* The admin screen is built with React and WordPress components that WordPress itself provides. They are not bundled.
* Background tasks use Action Scheduler as shipped with WooCommerce, and fall back to WP-Cron.
* The plugin loads no font, script, style or image from another site.
* Source code: the admin screen is written in TypeScript and compiled with @wordpress/scripts. The readable source and the build steps (`npm ci`, then `npm run build`) are in the public repository: https://github.com/estasofttechnologies/estasoft-enterprise-erp-crm-sync-engine

== Installation ==

1. Install and activate WooCommerce 8.2 or later.
2. Install this plugin from the Plugins screen and activate it.
3. Nothing has to be added to wp-config.php: credentials are encrypted with your WordPress security keys. Optionally add a key of its own above "That's all, stop editing!": `define( 'ESTASOFT_SYNC_KEY', 'a long random value' );`. It keeps saved credentials readable if you change the WordPress keys.
4. Open WooCommerce > ESTASOFT SYNC and follow the setup: check your store, choose a system, connect, choose what to sync, preview an order, run Shadow mode, go live.
5. Each system's setup screen says what you need first (an API key, an app of your own, a server login). For Odoo, create an API key under your user's Account Security.

== Frequently Asked Questions ==

= Which systems does it work with? =
The 35 connectors listed under Supported systems (34 setup entries, because some entries hold several products, such as the Sage ones). None has been tried with a live account yet, so use Shadow mode first.

= How does each system connect? =
By API key or login (Odoo, ERPNext, Holded, HubSpot, Pipedrive, SuiteCRM, Daftra, Lexware Office, Moneybird, Qoyod, sevDesk, Wafeq). By an app of your own and a sign-in page (QuickBooks Online, Xero, Zoho Books, Zoho CRM, MYOB, Exact Online, Fortnox, Sage Business Cloud Accounting, Sage 200, Salesforce, Acumatica). By a cloud app that signs in without a page (Business Central, Dynamics 365 Finance and Supply Chain, Dynamics 365 Sales, NetSuite, Oracle Fusion Cloud ERP, SAP S/4HANA). By a server login (SAP Business One, Sage 300, Sage X3). By a file you import (DATEV, Sage 50, TallyPrime).

= Why do I have to create my own app? =
Because the plugin has no relay server. With an app of your own your site talks straight to the vendor, and the secret stays with you. A shared app would send your orders through someone else's server.

= What data leaves my site? =
Only to the system you connect: your credentials, and the order, customer and product data your mapping selects. Never card numbers, and nothing to Estasoft or anyone else. See External services for each system.

= Does it work with HPOS and the block checkout? =
Yes. Both are declared compatible, and orders are read through WooCommerce's CRUD functions, never with SQL.

= Which versions does it need? =
PHP 8.1 or later with the sodium extension, WordPress 6.4 or later and WooCommerce 8.2 or later.

= Where is the key stored, and what is encrypted? =
API keys, passwords and client secrets are encrypted with libsodium before they are saved in the plugin's own table. The key is the constant ESTASOFT_SYNC_KEY in wp-config.php if you added one, otherwise it comes from the WordPress security keys in the same file. It is never stored in the database. If you change those keys and have no key of your own, enter the credentials again. Order data in the queue and log is not encrypted; the log redacts emails, phone numbers and tokens by default.

= What happens when my ERP is down? =
Checkout is not affected. Deliveries retry with growing delays; after repeated failures the connection pauses and orders wait in the queue. Failed orders are listed under Needs attention.

= Does it handle Arabic text? =
Yes. Names, addresses and notes are stored and sent as UTF-8 exactly as typed, and Arabic digits in phone and tax numbers are understood. The screens are in English only.

= Is Pennylane available? Is SMACC? =
Not yet. Both are listed but cannot be selected, and the plugin contains no code that contacts either of them. Pennylane requires a sign-in app for production use and its API terms limit how integrations may be used; SMACC publishes no way to connect. They will be enabled when that changes.

= Does it sign or issue tax invoices? =
No. It sends orders to your system, and the certified invoicing module of that system issues the invoice. The plugin never claims that a store is compliant with any tax authority's rules.

= How do I remove everything? =
Deactivate and delete the plugin. Your queue, mappings and settings are kept by default; to remove them on deletion, first turn on "Delete all data when the plugin is deleted" in the plugin's settings.

= Where do I get help? =
In this plugin's support forum on WordPress.org. Bug reports and connector requests are welcome on GitHub.

== Screenshots ==

1. The dashboard: what synced, what is waiting, what needs a person.
2. Needs attention: failed orders grouped by cause, fixed in one step.
3. Choose from 34 systems: how you connect, what you need first.
4. Preview exactly what your system will receive before anything is sent.
5. Reconciliation: a nightly check lists every order that differs between WooCommerce and your system, ready to repair.
6. Settings: email notifications, performance, access and a readiness check, with no usage tracking.

== Changelog ==

= 0.1.0 =
* First release: the queue and worker, 35 connectors for 34 systems on the setup screen, mapping with preview and rollback, Shadow mode, stock, tracking and invoice read-back, reconciliation, logs and the admin screens.

== Upgrade Notice ==

= 0.1.0 =
First release. Run Shadow mode before you go live.
