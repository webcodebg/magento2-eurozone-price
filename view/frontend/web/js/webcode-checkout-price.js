/**
 * @copyright Copyright © 2024–2026 Webcode (https://webcode.bg/)
 * @license   https://opensource.org/licenses/OSL-3.0 Open Software License (OSL 3.0)
 *
 * Secondary-currency decorator for Webcode_Checkout (the custom, RequireJS/KO-free
 * checkout). That checkout formats its dynamic rows through a private `money()`
 * helper that never touches the three EurozonePrice seams (PHP PriceCurrency, the
 * price-utils mixin, or hyva.formatPrice), so its prices would otherwise render in
 * a single currency.
 *
 * Webcode_Checkout's `money()` calls `window.webcodeEurozoneFormat(formatted,
 * amount, currencyCode)` when it exists; this file provides it, mirroring the
 * Hyva seam (idempotent, bg_BG-only, config-driven exchange rate/separator).
 */
(function () {
    'use strict';

    if (typeof window.webcodeEurozoneFormat === 'function') {
        return;
    }

    /**
     * @param {string} formatted    Already-formatted primary price, e.g. "24.00 €".
     * @param {number} amount       The numeric value the primary price was built from.
     * @param {string} [currencyCode] ISO code of the primary currency (EUR/BGN).
     * @returns {string} The price with the second currency appended, or the input
     *                   unchanged when the module is not applicable.
     */
    window.webcodeEurozoneFormat = function (formatted, amount, currencyCode) {
        var locale = window.LOCALE || 'bg-BG';
        if (locale !== 'bg-BG') {
            return formatted;
        }

        var config = window.webcodeEurozonePriceConfig || {};
        var rate = parseFloat(config.exchangeRate);
        if (!rate || isNaN(amount)) {
            return formatted;
        }

        var secondCurrency = null;
        var secondSymbol = null;
        if (currencyCode === 'BGN') {
            secondCurrency = 'EUR';
            secondSymbol = '€';
        } else if (currencyCode === 'EUR') {
            secondCurrency = 'BGN';
            secondSymbol = 'лв';
        } else if (/лв|BGN/.test(formatted)) {
            // No/unknown code: fall back to detecting the primary from the string.
            secondCurrency = 'EUR';
            secondSymbol = '€';
        } else if (/€|EUR/.test(formatted)) {
            secondCurrency = 'BGN';
            secondSymbol = 'лв';
        }

        if (!secondCurrency) {
            return formatted;
        }

        // Idempotency: never append the second currency twice (mirrors the Hyva
        // seam's indexOf guard).
        if (formatted.indexOf(secondSymbol) > -1 || formatted.indexOf(secondCurrency) > -1) {
            return formatted;
        }

        var secondAmount = secondCurrency === 'BGN' ? amount * rate : amount / rate;
        var secondFormatted = secondAmount.toLocaleString(locale, {
            minimumFractionDigits: 2,
            maximumFractionDigits: 2,
            currency: secondCurrency,
            style: 'currency'
        });

        var separator = ' ';
        if (config.currencySeparator && config.currencySeparator.length > 0) {
            separator += config.currencySeparator + ' ';
        }

        return formatted + separator + secondFormatted;
    };
})();
