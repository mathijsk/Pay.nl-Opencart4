# Pay. (pay.nl) for OpenCart 4

An OpenCart 4 payment extension for [Pay.](https://www.pay.nl) (pay.nl),
covering iDEAL, credit/debit cards, PayPal, Bancontact, Klarna, gift
cards, and every other payment method your Pay. account supports (105
methods total). Ported from Pay.nl's own official OpenCart 3 plugin.

## License & attribution

This is an **unofficial, community-made port**, not affiliated with,
endorsed by, or supported by Pay. (pay.nl). Pay. retains all rights to
their original OpenCart 3 plugin that this was ported from - no
license is granted or implied by this repository, and none is claimed.
If Pay. would prefer this not be distributed, it will be taken down on
request.

## What's included

- All Pay.-supported payment methods, each installable individually
  from Marketplace > Extensions > Payments
- Shared settings screen (API credentials, test mode, auto void/
  capture, refund handling, and more) on every method's own settings
  page - configuring one configures them all
- Refund / capture / void buttons on the admin order page
- Automatic void/capture when an order's status changes
- Fast Checkout express-buy button (iDEAL only - see **Known
  limitations** below)

## Requirements

- OpenCart **4.x**
- PHP **8.1+**
- A [Pay.](https://www.pay.nl) merchant account (a free sandbox/test
  account is enough to try this out - you don't need to have completed
  Pay.'s own business verification to configure and test the
  integration, though you will need it before accepting real, live
  payments)

## Installation

Two ways to install this - pick whichever is easier for you, both end
up in the same place.

### Option A: the release zip (recommended, no command line needed)

1. Download `paynl.ocmod.zip` from this repository's
   [Releases page](../../releases/latest).
2. In your admin panel, go to **Marketplace > Extensions > Installer**
   and upload it there. OpenCart handles both the file placement and
   the one-time extension registration automatically as part of that
   upload.

### Option B: from source (git clone / download)

#### 1. Copy the files into place

Copy (or `git clone`) this entire `paynl` folder into your OpenCart 4
store's `extension/` directory, so you end up with:

```
<your-opencart-store>/extension/paynl/
```

#### 2. Register the extension

OpenCart 4 needs a one-time database registration step before it will
recognize a new extension folder on every page load (this is normally
handled automatically when installing through Marketplace's own upload
screen, per Option A above - since a plain file copy/git clone skips
that screen, this script does the same registration directly). From
your store's root directory, run:

```
php extension/paynl/install.php
```

This is safe to run more than once - it checks whether the extension is
already registered before doing anything.

**Building your own zip instead of using the published one:** zip up
the *contents* of this `paynl` folder (not the folder itself -
`install.json`, `admin/`, `catalog/`, `system/` should sit at the root
of the zip) into a file named exactly `paynl.ocmod.zip`, then upload it
via Marketplace > Extensions > Installer, same as Option A.

### Then, either way:

### 3. Install the payment methods you want

In your admin panel, go to **Marketplace > Extensions**, filter by
**Payments**, and click **Install** next to each Pay.-supported method
you want to offer customers (e.g. "Pay. - iDEAL"). You only need to do
this for the methods you actually want to accept - install as many or
as few as you like.

### 4. Enter your Pay. credentials

Open any installed method's settings page (e.g. **Pay. - iDEAL**) and
fill in, under **Pay. General Settings**:

- **Token Code** (format `AT-XXXX-XXXX`)
- **API token**
- **Sales location** (your Service ID, format `SL-XXXX-XXXX`)

All three are found in your Pay. dashboard: the Token Code and API
token under **Company > Tokens**
(<https://admin.pay.nl/company/tokens>), and your Service ID under
**Settings > Sales locations** (<https://admin.pay.nl/programs/programs>).
Turn **Test mode** on while you're testing.

Saving valid credentials automatically pulls in your account's real
configured payment methods list from Pay. - if that succeeds without an
error, your credentials are working.

### 5. Configure the Pay. Exchange settings

In your Pay. dashboard, under the settings for the sales location
you're using, go to **Exchange settings** and set:

- **Setup exchange url**: "Yes, set by API or PLUGIN" (**not** a static
  URL) - this extension sends its own exchange URL with every
  transaction, specific to whichever payment method was used
- **Request method**: SIGNED JSON POST (recommended - this extension
  fully supports HMAC-signed webhook verification)

### 6. Set your store's Session Samesite Cookie to Lax

In your admin panel, go to **Settings > Server** and set **Session
Samesite Cookie** to **Lax** (OpenCart 4 ships with this set to
**Strict** by default).

This isn't specific to this extension - it affects **any** payment
method that redirects the customer off-site and back (iDEAL, most
card/3-D Secure flows, PayPal, etc.), not just Pay.'s methods. With
`Strict`, browsers block the session cookie on the cross-site
navigation back from the payment provider's domain, so OpenCart can't
recognize the returning customer and starts a brand-new (empty-cart)
session - this is exactly what an empty cart after cancelling a
payment looks like, confirmed live. `Lax` is the standard, widely-used
setting for e-commerce sites with external payment redirects: it still
blocks the cookie on cross-site POST requests, AJAX/fetch calls, and
embedded content (so real CSRF protection is unaffected), and only
allows it on top-level GET navigations - exactly what a payment
gateway's return redirect is.

## Known limitations

- **Fast Checkout only has a real button for iDEAL.** The original
  Pay.nl plugin also supported PayPal's own Fast Checkout, but that
  needs a completely separate PayPal Developer app (its own client
  ID/secret) and a client-side PayPal JS SDK integration - genuinely
  different from every other payment method here, and wasn't ported.
  Other methods can have the Fast Checkout toggle enabled in their
  settings, but won't actually show a button until (if ever) their own
  layout is added.
- **Fast Checkout button placement depends on your theme.** The button
  is injected into the cart, mini-cart, and product page by searching
  the rendered HTML for the native OpenCart checkout button/add-to-cart
  button. If your theme heavily customizes those pages (custom markup,
  a JS framework, etc.), the button may not appear, or may appear at
  the bottom of the page instead of inline. This doesn't affect normal
  checkout in any way - only the optional express-buy button.
- **If your theme overrides OpenCart's native `product/product` or
  `checkout/cart` routes** (some custom themes rewrite these to their
  own controller, via an event hook that changes which route actually
  renders), the Fast Checkout button's event hooks won't fire on those
  pages by default, since they're registered against the native route
  names. This extension is deliberately kept theme-agnostic - it
  doesn't know about your theme's specific routes, and shouldn't - so
  if this applies to your store, add the extra event registration in
  **your own theme's code** instead (wherever it already does its own
  route rewriting is the natural place), not by editing this
  extension. It only needs to insert one row per route into the
  `oc_event` table:
  ```php
  $this->db->query(
      "INSERT INTO `" . DB_PREFIX . "event` SET "
      . "`code` = 'your_unique_code', "
      . "`description` = 'Pay. fast checkout button on our theme route', "
      . "`trigger` = 'catalog/view/your/actual/route/after', "
      . "`action` = 'extension/paynl/paynl.addFastCheckoutButtons', " // or addFastCheckoutProductPageButtons for a product page
      . "`status` = '1', `sort_order` = '0'"
  );
  ```
  One real gotcha if you do this from a **catalog-side** controller
  (which a theme's own route-rewriting hook usually is): don't use
  `$this->load->model('setting/event')` for this - the catalog-side
  `setting/event` model only has a read-only `getEvents()`; the
  `getEventByCode()`/`addEvent()` methods only exist on the admin-side
  model, which isn't even autoloadable from a catalog request at all.
  Direct `$this->db->query()`, as above, sidesteps this entirely.
- Refund/capture/void and auto void/capture rely on OpenCart's event
  system firing correctly for your store - this has been extensively
  live-tested, but if your store has unusual customizations to its
  order-management flow, verify these work as expected before relying
  on them for real orders.

## Uninstalling

Uninstall each payment method individually from Marketplace >
Extensions > Payments first, then delete the `extension/paynl/` folder
and its `oc_extension_install` row (Marketplace > Extensions >
Installer, if you used that flow to install - or `DELETE FROM
oc_extension_install WHERE code = 'paynl'` directly otherwise).

## No warranty

This is provided as-is, with no guarantee that it works correctly for
your specific store, theme, or use case. It's been genuinely tested
live against a real OpenCart 4 store and a real Pay. account (not just
written and hoped for), but every OpenCart install is different, and
you're responsible for testing it thoroughly - including a real test
transaction - before relying on it for actual payments. Back up your
store before installing anything, this extension included.

## Support

This is a community port of Pay.'s official plugin, not an official
Pay. product. For questions about your Pay. account itself (credentials,
sales locations, payment method availability), contact
[Pay. support](https://www.pay.nl) directly.

For issues with this extension specifically, feel free to open an
issue on this repository - support is provided on a best-effort,
whenever-there's-time basis, not guaranteed or on any particular
timeline.
