# Webcode_EurozonePrice

Dual price display for Bulgarian stores: shows the EUR equivalent next to BGN prices (or the other way round), with a configurable separator and a CLI command that converts catalog prices to EUR.

## How

- Every price Magento formats on the storefront — product pages, listings, cart, checkout, customer order views — gets the other currency appended: a BGN price shows its EUR equivalent and a EUR price its BGN one, at the fixed rate **1.95583**, rounded to two decimals. The second amount carries the class `eurozone-price` for styling.
- Only for the `bg_BG` locale; other store views are left untouched. The admin keeps Magento's single price.
- Prices formatted in JavaScript (a mixin on `Magento_Catalog/js/price-utils`: mini cart, checkout totals, configurable options) get the same treatment. Luma and Hyvä are both supported.
- BGN prices are grouped by three digits in the thousands.

## Configuration

Stores → Configuration → Catalog → Currency Setup → **Eurozone Price**:

- Currency Separator — the text between the two prices, default `/` (for example `|` or `или`).

## Switching the catalog to EUR

```bash
bin/magento webcode:eurozone:convert-prices
```

Run once, after a database backup. With BGN as the base currency it asks to switch the base currency to EUR, then divides by the fixed rate, in one transaction: product prices (`price`, `special_price`, `cost`, `msrp`, `tier_price`), open carts, fixed-amount catalog and cart price rules, and shipping prices. It records that the conversion was done and refuses to run a second time.

## Installation

Webcode's free modules are installed from our own Composer repository — they are not published on Packagist.

```bash
composer config repositories.webcode composer https://repo.webcode.bg/public
composer require webcode/module-eurozone-price
bin/magento module:enable Webcode_EurozonePrice
bin/magento setup:upgrade
```

## Compatibility

Magento Open Source and Mage-OS 2.4.7–2.4.9, PHP 8.3–8.5.

## License

[OSL-3.0](LICENSE.txt) and [AFL-3.0](LICENSE_AFL.txt), like Magento itself.

## About

Made by [Webcode](https://webcode.bg/), a Magento development and support studio from Bulgaria. Module page: <https://webcode.bg/en/dual-price-euro-leva>.
