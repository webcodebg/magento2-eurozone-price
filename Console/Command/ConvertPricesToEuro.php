<?php
/*
 * @author       Kostadin Bashev (bashev@webcode.bg)
 * @copyright Copyright © 2024–2026 Webcode (https://webcode.bg/)
 * @license   https://opensource.org/licenses/OSL-3.0 Open Software License (OSL 3.0)
 */

declare(strict_types=1);

namespace Webcode\EurozonePrice\Console\Command;

use Exception;
use Magento\Directory\Model\Currency;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\App\Config\Storage\WriterInterface;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\Console\Cli;
use Magento\Framework\DB\Adapter\AdapterInterface;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Helper\QuestionHelper;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Question\ConfirmationQuestion;
use Webcode\EurozonePrice\Block\Config;

/**
 * One-time CLI migration that rescales every BGN monetary value to EUR across the
 * catalog, quote, price-rule, config and shipping tables and flips the base currency.
 *
 * Direct SQL is intentional: this is a bulk one-shot data migration over many core
 * tables where the ORM would be impractically slow; every statement is parameterised.
 * The raw-query and nesting sniffs are therefore suppressed for this class.
 *
 * phpcs:disable Magento2.SQL.RawQuery
 * phpcs:disable Generic.Metrics.NestingLevel
 */
class ConvertPricesToEuro extends Command
{
    /**
     * @param ResourceConnection $resourceConnection
     * @param ScopeConfigInterface $scopeConfig
     * @param WriterInterface $configWriter
     */
    public function __construct(
        private readonly ResourceConnection $resourceConnection,
        private readonly ScopeConfigInterface $scopeConfig,
        private readonly WriterInterface $configWriter,
    ) {
        parent::__construct();
    }

    /**
     * Configure the command.
     *
     * @return void
     */
    protected function configure(): void
    {
        $this->setName('webcode:eurozone:convert-prices');
        $this->setDescription('Convert all BGN prices to EUR');
        parent::configure();
    }

    /**
     * Execute the command.
     *
     * @param InputInterface $input
     * @param OutputInterface $output
     * @return int
     */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $output->writeln('<info>Starting price conversion from BGN to EUR...</info>');

        if ($this->scopeConfig->getValue(Config::CONFIG_PATH_CONVERTED_TO_EURO) == 1) {
            $output->writeln('<error>Price conversion already completed.</error>');
            return Cli::RETURN_FAILURE;
        }

        // Check if base currency is EUR or BGN
        $baseCurrency = $this->scopeConfig->getValue(Currency::XML_PATH_CURRENCY_BASE);
        if ($baseCurrency === 'BGN') {
            $output->writeln(
                '<comment>Base currency is currently BGN. To proceed with price conversion, '
                . 'it needs to be changed to EUR.</comment>'
            );

            /** @var QuestionHelper $questionHelper */
            $questionHelper = $this->getHelper('question');
            $question = new ConfirmationQuestion(
                '<question>Do you want to change the base currency from BGN to EUR and '
                . 'proceed with price conversion? (y/N)</question> ',
                false
            );

            if (!$questionHelper->ask($input, $output, $question)) {
                $output->writeln('<info>Price conversion cancelled.</info>');
                return Cli::RETURN_SUCCESS;
            }

            // Update base currency to EUR
            $this->updateBaseCurrency($output);
            $output->writeln('<info>Base currency changed to EUR.</info>');

        } elseif ($baseCurrency !== 'EUR') {
            $output->writeln(
                '<error>Base currency is not EUR or BGN. Current base currency: '
                . $baseCurrency . '</error>'
            );
            return Cli::RETURN_FAILURE;
        }

        $output->writeln('<info>Configuration check passed. Ready to proceed with conversion.</info>');

        $connection = $this->resourceConnection->getConnection();

        try {
            $connection->beginTransaction();

            // Update product prices
            $this->convertProductPrices($output, $connection);

            // Update shopping cart prices
            $this->convertShoppingCartPrices($output, $connection);

            // Update catalog price rules
            $this->convertCatalogPriceRules($output, $connection);

            // Update sales price rules
            $this->convertSalesRules($output, $connection);

            // Update shipping prices
            $this->convertShippingPrices($output, $connection);

            // Set configuration flag to 1
            $this->configWriter->save(Config::CONFIG_PATH_CONVERTED_TO_EURO, 1);

            $connection->commit();
            $output->writeln('<info>Price conversion completed successfully!</info>');

        } catch (Exception $e) {
            $connection->rollBack();
            $output->writeln('<error>Error during price conversion: ' . $e->getMessage() . '</error>');
            return Cli::RETURN_FAILURE;
        }

        return Cli::RETURN_SUCCESS;
    }

    /**
     * Convert product prices.
     *
     * @param OutputInterface $output
     * @param AdapterInterface $connection
     * @return void
     */
    private function convertProductPrices(OutputInterface $output, AdapterInterface $connection): void
    {
        $output->writeln('<info>Converting product prices...</info>');

        $priceAttributes = ['price', 'special_price', 'cost', 'msrp', 'tier_price'];

        try {
            $entityTypeId = $connection->fetchOne(
                "SELECT entity_type_id FROM eav_entity_type WHERE entity_type_code = 'catalog_product'"
            );
        } catch (Exception $e) {
            $output->writeln("<error>Failed to get product entity type: {$e->getMessage()}</error>");
            return;
        }

        $rate = $this->scopeConfig->getValue(Config::CONFIG_PATH_EXCHANGE_RATE);

        foreach ($priceAttributes as $attributeCode) {
            try {
                $attributeId = $connection->fetchOne(
                    "SELECT attribute_id FROM eav_attribute WHERE attribute_code = ? AND entity_type_id = ?",
                    [$attributeCode, $entityTypeId]
                );

                if ($attributeId) {
                    $updateQuery = "UPDATE catalog_product_entity_decimal
                                   SET value = ROUND(value / ?, 4)
                                   WHERE attribute_id = ? AND value > 0";
                    $result = $connection->query($updateQuery, [$rate, $attributeId]);
                    $output->writeln("<info>Updated {$result->rowCount()} {$attributeCode} values</info>");
                } else {
                    $output->writeln("<comment>Attribute {$attributeCode} not found, skipping</comment>");
                }
            } catch (Exception $e) {
                $output->writeln("<error>Failed to update {$attributeCode}: {$e->getMessage()}</error>");
            }
        }
    }

    /**
     * Convert shopping cart prices.
     *
     * @param OutputInterface $output
     * @param AdapterInterface $connection
     * @return void
     */
    private function convertShoppingCartPrices(OutputInterface $output, AdapterInterface $connection): void
    {
        $output->writeln('<info>Converting shopping cart prices...</info>');

        $rate = $this->scopeConfig->getValue(Config::CONFIG_PATH_EXCHANGE_RATE);

        // Update quote table
        $quoteFields = [
            'grand_total', 'base_grand_total', 'subtotal', 'base_subtotal',
            'subtotal_with_discount', 'base_subtotal_with_discount'
        ];
        foreach ($quoteFields as $field) {
            $updateQuery = "UPDATE quote SET {$field} = ROUND({$field} / ?, 4) WHERE {$field} > 0";
            $result = $connection->query($updateQuery, [$rate]);
            $output->writeln("<info>Updated {$result->rowCount()} quote {$field} values</info>");
        }

        $quoteCurrencyFields = ['base_currency_code', 'store_currency_code', 'quote_currency_code'];
        foreach ($quoteCurrencyFields as $field) {
            $updateQuery = "UPDATE quote SET {$field} = 'EUR' WHERE {$field} = 'BGN'";
            $output->writeln("<info>Updated {$result->rowCount()} quote currencies {$field} values</info>");
        }

        // Update quote_item table
        $quoteItemFields = [
            'price', 'base_price', 'custom_price', 'discount_amount', 'base_discount_amount',
            'tax_amount', 'base_tax_amount', 'row_total', 'base_row_total', 'row_total_with_discount',
            'base_tax_before_discount', 'tax_before_discount',
            'original_custom_price', 'base_cost', 'price_incl_tax', 'base_price_incl_tax',
            'row_total_incl_tax', 'base_row_total_incl_tax', 'discount_tax_compensation_amount'
        ];
        foreach ($quoteItemFields as $field) {
            $updateQuery = "UPDATE quote_item SET {$field} = ROUND({$field} / ?, 4) WHERE {$field} > 0";
            $result = $connection->query($updateQuery, [$rate]);
            $output->writeln("<info>Updated {$result->rowCount()} quote_item {$field} values</info>");
        }
    }

    /**
     * Convert catalog price rules.
     *
     * @param OutputInterface $output
     * @param AdapterInterface $connection
     * @return void
     */
    private function convertCatalogPriceRules(OutputInterface $output, AdapterInterface $connection): void
    {
        $output->writeln('<info>Converting catalog price rules...</info>');

        $rate = $this->scopeConfig->getValue(Config::CONFIG_PATH_EXCHANGE_RATE);

        // Update catalogrule table
        try {
            $updateQuery = "UPDATE catalogrule SET discount_amount = ROUND(discount_amount / ?, 4)
                           WHERE simple_action IN ('fixed_amount', 'by_fixed') AND discount_amount > 0";
            $result = $connection->query($updateQuery, [$rate]);
            $output->writeln("<info>Updated {$result->rowCount()} catalog rule discount amounts</info>");
        } catch (Exception $e) {
            $output->writeln("<error>Failed to update catalog rule discount amounts: {$e->getMessage()}</error>");
        }

        // Update catalogrule_product_price table
        $priceFields = ['rule_price'];
        foreach ($priceFields as $field) {
            try {
                $updateQuery = "UPDATE catalogrule_product_price
                               SET {$field} = ROUND({$field} / ?, 4) WHERE {$field} > 0";
                $result = $connection->query($updateQuery, [$rate]);
                $output->writeln(
                    "<info>Updated {$result->rowCount()} catalog rule product {$field} values</info>"
                );
            } catch (Exception $e) {
                $output->writeln(
                    "<error>Failed to update catalog rule product {$field}: {$e->getMessage()}</error>"
                );
            }
        }
    }

    /**
     * Convert sales rules (cart price rules).
     *
     * @param OutputInterface $output
     * @param AdapterInterface $connection
     * @return void
     */
    private function convertSalesRules(OutputInterface $output, AdapterInterface $connection): void
    {
        $output->writeln('<info>Converting sales rules...</info>');

        $rate = $this->scopeConfig->getValue(Config::CONFIG_PATH_EXCHANGE_RATE);

        // Update salesrule table
        try {
            $updateQuery = "UPDATE salesrule SET discount_amount = ROUND(discount_amount / ?, 4)
                           WHERE simple_action IN ('cart_fixed', 'by_fixed') AND discount_amount > 0";
            $result = $connection->query($updateQuery, [$rate]);
            $output->writeln("<info>Updated {$result->rowCount()} sales rule discount amounts</info>");
        } catch (Exception $e) {
            $output->writeln("<error>Failed to update sales rule discount amounts: {$e->getMessage()}</error>");
        }
    }

    /**
     * Convert shipping prices.
     *
     * @param OutputInterface $output
     * @param AdapterInterface $connection
     * @return void
     */
    private function convertShippingPrices(OutputInterface $output, AdapterInterface $connection): void
    {
        $output->writeln('<info>Converting shipping prices...</info>');

        $rate = $this->scopeConfig->getValue(Config::CONFIG_PATH_EXCHANGE_RATE);

        // Update core_config_data for shipping prices
        $shippingConfigPaths = [
            'carriers/flatrate/price',
            'carriers/tablerate/price',
            'carriers/freeshipping/free_shipping_subtotal',
            'webcode_freeshippingreminder/general/free_shipping_subtotal'
        ];

        foreach ($shippingConfigPaths as $path) {
            try {
                $updateQuery = "UPDATE core_config_data
                               SET value = ROUND(CAST(value AS DECIMAL(12,4)) / ?, 4)
                               WHERE path = ? AND value REGEXP '^[0-9]+\.?[0-9]*$'
                               AND CAST(value AS DECIMAL(12,4)) > 0";
                $result = $connection->query($updateQuery, [$rate, $path]);
                $output->writeln(
                    "<info>Updated {$result->rowCount()} shipping config values for {$path}</info>"
                );
            } catch (Exception $e) {
                $output->writeln("<error>Failed to update shipping config {$path}: {$e->getMessage()}</error>");
            }
        }

        // Update JSON pricing tables for webcode modules
        try {
            $this->convertJsonPricingTables($output, $connection);
        } catch (Exception $e) {
            $output->writeln("<error>Failed to convert JSON pricing tables: {$e->getMessage()}</error>");
        }

        // Update shipping_tablerate table if it exists
        try {
            if ($connection->isTableExists('shipping_tablerate')) {
                $updateQuery = "UPDATE shipping_tablerate SET price = ROUND(price / ?, 4) WHERE price > 0";
                $result = $connection->query($updateQuery, [$rate]);
                $output->writeln("<info>Updated {$result->rowCount()} table rate shipping prices</info>");
            }
        } catch (Exception $e) {
            $output->writeln("<error>Failed to update table rate shipping prices: {$e->getMessage()}</error>");
        }
    }

    /**
     * Convert JSON pricing tables.
     *
     * @param OutputInterface $output
     * @param AdapterInterface $connection
     * @return void
     */
    private function convertJsonPricingTables(OutputInterface $output, AdapterInterface $connection): void
    {
        $output->writeln('<info>Converting JSON pricing tables...</info>');

        $rate = (float)$this->scopeConfig->getValue(Config::CONFIG_PATH_EXCHANGE_RATE);

        $jsonPricingPaths = [
            'carriers/econt/pricing_table',
            'carriers/speedy/pricing_table',
            'carriers/boxnow/pricing_table',
        ];

        foreach ($jsonPricingPaths as $path) {
            $selectQuery = "SELECT config_id, value FROM core_config_data
                           WHERE path = ? AND value IS NOT NULL AND value != ''";
            $configRows = $connection->fetchAll($selectQuery, [$path]);

            $updatedCount = 0;
            foreach ($configRows as $row) {
                $originalValue = $row['value'];
                $configId = $row['config_id'];

                try {
                    $pricingData = json_decode($originalValue, true);

                    if (json_last_error() !== JSON_ERROR_NONE) {
                        $output->writeln("<error>Invalid JSON in config {$path}, ID: {$configId}</error>");
                        continue;
                    }

                    $updated = $this->rescalePricingRows($pricingData, $rate);

                    if ($updated) {
                        $newValue = json_encode($pricingData, JSON_UNESCAPED_UNICODE);
                        $updateQuery = "UPDATE core_config_data SET value = ? WHERE config_id = ?";
                        $connection->query($updateQuery, [$newValue, $configId]);
                        $updatedCount++;
                    }

                } catch (Exception $e) {
                    $output->writeln(
                        "<error>Error processing config {$path}, ID: {$configId}: {$e->getMessage()}</error>"
                    );
                }
            }

            $output->writeln("<info>Updated {$updatedCount} JSON pricing table entries for {$path}</info>");
        }
    }

    /**
     * Rescale every positive `price` entry in a decoded pricing table in place.
     *
     * @param mixed $pricingData
     * @param float $rate
     * @return bool
     */
    private function rescalePricingRows(mixed &$pricingData, float $rate): bool
    {
        if (!is_array($pricingData)) {
            return false;
        }

        $updated = false;
        foreach ($pricingData as &$priceRule) {
            if (isset($priceRule['price']) && is_numeric($priceRule['price']) && $priceRule['price'] > 0) {
                $priceRule['price'] = number_format((float)$priceRule['price'] / $rate, 4, '.', '');
                $updated = true;
            }
        }

        return $updated;
    }

    /**
     * Update base currency from BGN to EUR.
     *
     * @param OutputInterface $output
     * @return void
     */
    private function updateBaseCurrency(OutputInterface $output): void
    {
        $output->writeln('<info>Updating base currency configuration from BGN to EUR...</info>');

        // Update base and default currency configurations for default scope only
        $currencyPaths = [
            'currency/options/base',
            'currency/options/default'
        ];

        foreach ($currencyPaths as $path) {
            $this->configWriter->save($path, 'EUR', ScopeConfigInterface::SCOPE_TYPE_DEFAULT, 0);
        }

        // Update allowed currencies - replace BGN with EUR in default scope only
        $allowedCurrencies = $this->scopeConfig->getValue(
            'currency/options/allow',
            ScopeConfigInterface::SCOPE_TYPE_DEFAULT,
            0
        );

        if ($allowedCurrencies) {
            $currencyArray = explode(',', $allowedCurrencies);

            // Replace BGN with EUR if BGN exists, otherwise add EUR
            $bgnIndex = array_search('BGN', $currencyArray);
            if ($bgnIndex !== false) {
                $currencyArray[$bgnIndex] = 'EUR';
            } elseif (!in_array('EUR', $currencyArray)) {
                $currencyArray[] = 'EUR';
            }

            // Remove duplicates and save
            $currencyArray = array_unique($currencyArray);
            $newAllowedCurrencies = implode(',', $currencyArray);

            $this->configWriter->save(
                'currency/options/allow',
                $newAllowedCurrencies,
                ScopeConfigInterface::SCOPE_TYPE_DEFAULT,
                0
            );
        }

        // Update quote currency codes in database
        $this->updateQuoteCurrencyCodes($output);

        $output->writeln('<info>Base currency configuration updated successfully.</info>');
    }

    /**
     * Update currency codes in quote table only.
     *
     * @param OutputInterface $output
     * @return void
     */
    private function updateQuoteCurrencyCodes(OutputInterface $output): void
    {
        $output->writeln('<info>Updating quote table currency codes...</info>');

        $connection = $this->resourceConnection->getConnection();

        // Update quote table currency codes
        $quoteCurrencyFields = [
            'base_currency_code',
            'store_currency_code',
            'quote_currency_code',
            'base_to_quote_rate',
            'store_to_quote_rate'
        ];

        foreach ($quoteCurrencyFields as $field) {
            try {
                if (in_array($field, ['base_to_quote_rate', 'store_to_quote_rate'])) {
                    // Update currency rates - set to 1 when converting from BGN to EUR
                    $updateQuery = "UPDATE quote SET {$field} = 1
                                   WHERE {$field} != 1
                                   AND (base_currency_code = 'BGN' OR store_currency_code = 'BGN'
                                   OR quote_currency_code = 'BGN')";
                } else {
                    // Update currency codes
                    $updateQuery = "UPDATE quote SET {$field} = 'EUR' WHERE {$field} = 'BGN'";
                }
                $result = $connection->query($updateQuery);
                $output->writeln("<info>Updated {$result->rowCount()} quote {$field} values</info>");
            } catch (Exception $e) {
                $output->writeln("<error>Failed to update quote {$field}: {$e->getMessage()}</error>");
            }
        }
    }
}
