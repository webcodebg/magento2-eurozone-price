<?php
/*
 * @author       Kostadin Bashev (bashev@webcode.bg)
 * @copyright Copyright © 2024–2026 Webcode (https://webcode.bg/)
 * @license   https://opensource.org/licenses/OSL-3.0 Open Software License (OSL 3.0)
 */

declare(strict_types=1);

namespace Webcode\EurozonePrice\Service;

use Magento\Directory\Model\Currency;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\Locale\ResolverInterface;
use Magento\Framework\Pricing\PriceCurrencyInterface;
use Webcode\EurozonePrice\Block\Config;

class EurozonePriceCurrency
{
    /**
     * @var string
     */
    private string $currencyCode;

    /**
     * @param Currency $currency
     * @param ResolverInterface $localeResolver
     * @param ScopeConfigInterface $scopeConfig
     */
    public function __construct(
        private readonly Currency             $currency,
        private readonly ResolverInterface    $localeResolver,
        private readonly ScopeConfigInterface $scopeConfig
    ) {
    }

    /**
     * Set the currency code of the price currently being formatted.
     *
     * @param string $currency
     * @return void
     */
    public function setCurrencyCode(string $currency): void
    {
        $this->currencyCode = $currency;
    }

    /**
     * Get the currency code of the price currently being formatted.
     *
     * @return string
     */
    public function getCurrencyCode(): string
    {
        return $this->currencyCode;
    }

    /**
     * Append the secondary-currency amount (EUR/BGN) to an already formatted price.
     *
     * @param string $result
     * @param mixed $amount
     * @param int $precision
     * @param bool $includeContainer
     * @return string
     */
    public function getFormattedPrice(
        string $result,
        $amount,
        int $precision = PriceCurrencyInterface::DEFAULT_PRECISION,
        bool $includeContainer = true
    ) {
        // Only execute for bg_BG locale
        if ($this->localeResolver->getLocale() !== 'bg_BG') {
            return $result;
        }

        if ($this->getCurrencyCode() === 'BGN') {
            $secondCurrency = 'EUR';
        } elseif ($this->getCurrencyCode() === 'EUR') {
            $secondCurrency = 'BGN';
        }

        if (isset($secondCurrency) &&
            ($calculatedAmount = $this->calculate((float)$amount, $secondCurrency)) !== null
        ) {
            $secondAmount = $this->currency
                ->setData('currency_code', $secondCurrency)
                ->formatPrecision(
                    $calculatedAmount,
                    (int)$precision,
                    null,
                    $includeContainer
                );

            if (str_contains($result, $secondAmount)) {
                return $result;
            }

            $secondAmount = str_replace('class="price"', 'class="price eurozone-price"', $secondAmount);

            $separator = ' ';
            $currencySeparator = $this->scopeConfig->getValue(Config::CONFIG_PATH_CURRENCY_SEPARATOR);
            if (!empty($currencySeparator)) {
                $separator .= $currencySeparator . ' ';
            }

            return $result . $separator . $secondAmount;
        }

        return $result;
    }

    /**
     * Calculate second currency amount
     *
     * @param float $amount
     * @param string|null $currency
     * @return float|null
     */
    public function calculate(float $amount, ?string $currency = null): ?float
    {
        $rate = $this->scopeConfig->getValue(Config::CONFIG_PATH_EXCHANGE_RATE);

        if ($currency === 'BGN') {
            $amount = round($amount * $rate, 2);
        } elseif ($currency === 'EUR') {
            $amount = round($amount / $rate, 2);
        } else {
            return null;
        }

        return (float)$amount;
    }
}
