/**
 * @copyright Copyright © 2024–2026 Webcode (https://webcode.bg/)
 * @license   https://opensource.org/licenses/OSL-3.0 Open Software License (OSL 3.0)
 */
define([
    'mage/utils/wrapper',
    'underscore'
], function (wrapper, _) {
    'use strict';

    return function (priceUtils) {

        var config = window.webcodeEurozonePriceConfig || {};

        var locale = window.LOCALE || 'bg-BG';

        priceUtils.formatPriceLocale = wrapper.wrap(priceUtils.formatPriceLocale, function (originalFormatPriceLocale, amount, format, isShowSign) {
            var originalResult = originalFormatPriceLocale(amount, format, isShowSign);

            if (!config || !config.exchangeRate) {
                return originalResult;
            }

            if (locale !== 'bg-BG') {
                return originalResult;
            }

            if (!format || !format.pattern) {
                return originalResult;
            }

            let secondCurrency, secondCurrencySymbol;
            if (format.pattern.indexOf('BGN') > -1 || format.pattern.indexOf('лв') > -1) {
                secondCurrency = 'EUR';
                secondCurrencySymbol = '€';
            }

            if (format.pattern.indexOf('EUR') > -1 || format.pattern.indexOf('€') > -1) {
                secondCurrency = 'BGN';
                secondCurrencySymbol = 'лв';
            }

            if (secondCurrency &&
                secondCurrency.length > 0 &&
                format.pattern.indexOf(secondCurrency) === -1 &&
                format.pattern.indexOf(secondCurrencySymbol) === -1
            ) {
                var secondCurrencyResult = calculateSecondCurrencyAmount(amount, secondCurrency).toLocaleString(locale, {
                    minimumFractionDigits: format.precision,
                    maximumFractionDigits: format.precision,
                    currency: secondCurrency,
                    style: 'currency'
                });

                let separator = ' ';
                if (config.currencySeparator.length > 0) {
                    separator += config.currencySeparator + ' ';
                }

                return originalResult + separator + secondCurrencyResult;
            }

            return originalResult;
        });

        function calculateSecondCurrencyAmount (amount, currency) {
            if (currency === 'BGN') {
                return amount * config.exchangeRate;
            } else if (currency === 'EUR') {
                return amount / config.exchangeRate;
            }

            return amount;
        }

        return priceUtils;
    };
});
