<?php
/*
 * @author       Kostadin Bashev (bashev@webcode.bg)
 * @copyright Copyright © 2024–2026 Webcode (https://webcode.bg/)
 * @license   https://opensource.org/licenses/OSL-3.0 Open Software License (OSL 3.0)
 */

namespace Webcode\EurozonePrice\Block;

use Magento\Framework\View\Element\Template;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Store\Model\ScopeInterface;

class Config extends Template
{
    public const CONFIG_PATH_EXCHANGE_RATE = 'webcode_eurozoneprice/general/exchange_rate';

    public const CONFIG_PATH_CONVERTED_TO_EURO = 'webcode_eurozoneprice/general/converted_to_euro';

    public const CONFIG_PATH_CURRENCY_SEPARATOR = 'webcode_eurozoneprice/general/currency_separator';

    /**
     * Config constructor.
     * @param Template\Context $context
     * @param ScopeConfigInterface $scopeConfig
     * @param array $data
     */
    public function __construct(
        Template\Context                      $context,
        private readonly ScopeConfigInterface $scopeConfig,
        array                                 $data = []
    ) {
        parent::__construct($context, $data);
    }

    /**
     * Get module configuration as JSON
     *
     * @return string
     */
    public function getConfigJson(): string
    {
        $config = [
            'exchangeRate' => $this->scopeConfig->getValue(
                self::CONFIG_PATH_EXCHANGE_RATE,
                ScopeInterface::SCOPE_STORE
            ) ?: 1.95583,
            'currencySeparator' => (string)$this->_escaper->escapeHtml($this->scopeConfig->getValue(
                self::CONFIG_PATH_CURRENCY_SEPARATOR,
                ScopeInterface::SCOPE_STORE
            ))
        ];

        return json_encode($config);
    }
}
