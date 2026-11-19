<?php
/*
 * @author       Kostadin Bashev (bashev@webcode.bg)
 * @copyright Copyright © 2024–2026 Webcode (https://webcode.bg/)
 * @license   https://opensource.org/licenses/OSL-3.0 Open Software License (OSL 3.0)
 */

declare(strict_types=1);

namespace Webcode\EurozonePrice\Plugin\Sales\Model;

use Exception;
use Magento\Framework\App\Area;
use Magento\Framework\App\State;
use Magento\Sales\Model\Order;
use Webcode\EurozonePrice\Service\EurozonePriceCurrency;

readonly class OrderPlugin
{
    /**
     * @param EurozonePriceCurrency $eurozonePriceCurrency
     * @param State $state
     */
    public function __construct(
        private EurozonePriceCurrency $eurozonePriceCurrency,
        private State $state
    ) {
    }

    /**
     * Append the EUR/BGN counterpart to a formatted order price on the storefront.
     *
     * @param Order $subject
     * @param string $result
     * @param mixed $price
     * @return string
     */
    public function afterFormatPrice(
        Order  $subject,
        string $result,
        $price,
    ): string {
        try {
            if ($this->state->getAreaCode() === Area::AREA_ADMINHTML) {
                return $result;
            }
            // phpcs:ignore Magento2.CodeAnalysis.EmptyBlock
        } catch (Exception) {
            // Area not resolved yet (early bootstrap) → treat as storefront and continue.
        }

        $this->eurozonePriceCurrency->setCurrencyCode($subject->getOrderCurrencyCode());

        if ($output = $this->eurozonePriceCurrency->getFormattedPrice($result, (float)$price)) {
            return $output;
        }

        return $result;
    }
}
