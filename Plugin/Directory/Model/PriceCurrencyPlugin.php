<?php
/*
 * @author       Kostadin Bashev (bashev@webcode.bg)
 * @copyright Copyright © 2024–2026 Webcode (https://webcode.bg/)
 * @license   https://opensource.org/licenses/OSL-3.0 Open Software License (OSL 3.0)
 */

declare(strict_types=1);

namespace Webcode\EurozonePrice\Plugin\Directory\Model;

use Magento\Directory\Model\PriceCurrency;
use Magento\Framework\Pricing\PriceCurrencyInterface;
use Webcode\EurozonePrice\Service\EurozonePriceCurrency;

class PriceCurrencyPlugin
{
    /**
     * @param EurozonePriceCurrency $eurozonePriceCurrency
     */
    public function __construct(
        private readonly EurozonePriceCurrency $eurozonePriceCurrency
    ) {
    }

    /**
     * Add EUR equivalent to BGN currency formatting
     *
     * @param PriceCurrency $subject
     * @param string $result
     * @param mixed $amount
     * @param bool $includeContainer
     * @param int $precision
     * @param mixed $scope
     * @param mixed $currency
     * @return string
     * @SuppressWarnings(PHPMD.UnusedFormalParameter)
     */
    public function afterFormat(
        PriceCurrency $subject,
        string        $result,
        $amount,
        $includeContainer = true,
        $precision = PriceCurrencyInterface::DEFAULT_PRECISION,
        $scope = null,
        $currency = null
    ) {
        if (!$currency) {
            $currency = $subject->getCurrency()->getCode();
        }

        $this->eurozonePriceCurrency->setCurrencyCode($currency);

        return $this->eurozonePriceCurrency->getFormattedPrice(
            $result,
            $amount,
            $precision,
            $includeContainer
        );
    }
}
