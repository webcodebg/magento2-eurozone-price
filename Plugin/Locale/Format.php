<?php
/*
 * @author       Kostadin Bashev (bashev@webcode.bg)
 * @copyright Copyright © 2024–2026 Webcode (https://webcode.bg/)
 * @license   https://opensource.org/licenses/OSL-3.0 Open Software License (OSL 3.0)
 */

declare(strict_types=1);

namespace Webcode\EurozonePrice\Plugin\Locale;

use Magento\Framework\App\ScopeResolverInterface;
use Magento\Framework\Locale\Format as LocaleFormat;

class Format
{
    private const string CURRENCY_CODE_BULGARIA = 'BGN';

    /**
     * @param ScopeResolverInterface $scopeResolver
     */
    public function __construct(
        private readonly ScopeResolverInterface $scopeResolver
    ) {
    }

    /**
     * Force standard three-digit thousands grouping for BGN price formatting.
     *
     * @param LocaleFormat $subject
     * @param array $result
     * @return array
     * @SuppressWarnings(PHPMD.UnusedFormalParameter)
     */
    public function afterGetPriceFormat(LocaleFormat $subject, array $result): array
    {
        $currencyCode = $this->scopeResolver->getScope()->getCurrentCurrency()->getCode();
        if ($currencyCode === self::CURRENCY_CODE_BULGARIA) {
            $result['groupLength'] = 3;
        }

        return $result;
    }
}
