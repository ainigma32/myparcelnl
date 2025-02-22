<?php
/**
 * This class contain all functions to check type of package
 *
 * LICENSE: This source file is subject to the Creative Commons License.
 * It is available through the world-wide-web at this URL:
 * http://creativecommons.org/licenses/by-nc-nd/3.0/nl/deed.en_US
 *
 * If you want to add improvements, please create a fork in our GitHub:
 * https://github.com/myparcelnl/magento
 *
 * @author      Reindert Vetter <info@myparcel.nl>
 * @copyright   2010-2019 MyParcel
 * @license     http://creativecommons.org/licenses/by-nc-nd/3.0/nl/deed.en_US  CC BY-NC-ND 3.0 NL
 * @link        https://github.com/myparcelnl/magento
 * @since       File available since Release 2.0.0
 */

namespace MyParcelNL\Magento\Model\Sales\Repository;

use MyParcelNL\Magento\Model\Sales\Package;
use MyParcelNL\Sdk\src\Model\Consignment\AbstractConsignment;

class PackageRepository extends Package
{
    public const DEFAULT_MAXIMUM_MAILBOX_WEIGHT = 2000;
    public const MAXIMUM_DIGITAL_STAMP_WEIGHT   = 2000;
    public const DEFAULT_LARGE_FORMAT_WEIGHT    = 2300;

    /**
     * @var bool
     */
    public $deliveryOptionsDisabled = false;

    /**
     * @param array  $products
     * @param string $carrierPath
     *
     * @return string
     */
    public function selectPackageType(array $products, string $carrierPath): string
    {
        // When age check is enabled, only packagetype 'package' is possible
        if ($this->getAgeCheck($products, $carrierPath)) {
            return AbstractConsignment::PACKAGE_TYPE_PACKAGE_NAME;
        }

        $this->setMailboxPercentage(0);
        $weight       = 0;
        $digitalStamp = true;
        foreach ($products as $product) {
            $productQty    = $product->getQty();
            $productWeight = (float) $product->getWeight();

            if ($productQty < 1) {
                continue;
            }

            if ($productWeight > 0) {
                $weight += $productWeight * $productQty;
            }

            if ($digitalStamp && ! $this->getAttributesProductsOptions($product, 'digital_stamp')) {
                $digitalStamp = false;
            }

            if (100 < $this->getMailboxPercentage()) {
                continue;
            }

            $mailboxQty = $this->getAttributesProductsOptions($product, 'fit_in_mailbox');

            if (-1 === $mailboxQty) {
                $this->setMailboxPercentage(101);
                continue;
            }

            if (0 === $mailboxQty && 0.0 !== $productWeight) {
                $mailboxQty = (int) ($this->getMaxMailboxWeight() / $productWeight);
            }

            if (0 !== $mailboxQty) {
                $productPercentage = $productQty * 100 / $mailboxQty;
                $mailboxPercentage = $this->getMailboxPercentage() + $productPercentage;
                $this->setMailboxPercentage($mailboxPercentage);
            }
        }
        $this->setWeight($weight);

        if ($digitalStamp && $this->fitInDigitalStamp()) {
            return AbstractConsignment::PACKAGE_TYPE_DIGITAL_STAMP_NAME;
        }

        if ($this->fitInMailbox()) {
            return AbstractConsignment::PACKAGE_TYPE_MAILBOX_NAME;
        }

        return AbstractConsignment::PACKAGE_TYPE_PACKAGE_NAME;
    }

    /**
     * @param array $products
     *
     * @return \MyParcelNL\Magento\Model\Sales\Repository\PackageRepository
     */
    public function productWithoutDeliveryOptions(array $products): PackageRepository
    {
        foreach ($products as $product) {
            $this->isDeliveryOptionsDisabled($product);
        }

        return $this;
    }

    /**
     * @return bool
     */
    public function fitInMailbox(): bool
    {
        return $this->getCurrentCountry() === AbstractConsignment::CC_NL
            && $this->isMailboxActive()
            && $this->getWeight() <= $this->getMaxMailboxWeight()
            && $this->getMailboxPercentage() <= 100;
    }

    /**
     * @return bool
     */
    public function fitInDigitalStamp(): bool
    {
        $orderWeight               = $this->getWeightTypeOfOption($this->getWeight());
        $maximumDigitalStampWeight = $this->getMaxDigitalStampWeight();

        return $this->getCurrentCountry() === AbstractConsignment::CC_NL
            && $this->isDigitalStampActive()
            && $orderWeight <= $maximumDigitalStampWeight;
    }

    /**
     * Init all mailbox settings
     *
     * @param string $carrierPath
     *
     * @return $this
     * @return $this
     */
    public function setMailboxSettings(string $carrierPath = self::XML_PATH_POSTNL_SETTINGS): PackageRepository
    {
        $settings = $this->getConfigValue("{$carrierPath}mailbox");

        if (null === $settings || ! array_key_exists('active', $settings)) {
            $this->_logger->critical("Can't set settings with path: {$carrierPath}mailbox");
        }

        $this->setMailboxActive('1' === $settings['active']);
        if (true === $this->isMailboxActive()) {
            $weight = abs((float) str_replace(',', '.', $settings['weight'] ?? ''));
            $unit   = $this->getGeneralConfig('print/weight_indication');

            if ('kilo' === $unit) {
                $epsilon = 0.00001;
                $default = self::DEFAULT_MAXIMUM_MAILBOX_WEIGHT / 1000.0;
                if ($weight < $epsilon) {
                    $weight = $default;
                }
                $this->setMaxMailboxWeight($weight);
            } else {
                $weight = (int)$weight;
                $this->setMaxMailboxWeight($weight ?: self::DEFAULT_MAXIMUM_MAILBOX_WEIGHT);
            }

            $pickupMailbox = (bool) $this->getConfigValue("{$carrierPath}mailbox/pickup_mailbox");
            $this->setPickupMailboxActive($pickupMailbox);
        }

        return $this;
    }

    /**
     * @param $products
     *
     * @return \MyParcelNL\Magento\Model\Sales\Repository\PackageRepository
     */
    public function isDeliveryOptionsDisabled($products)
    {
        $deliveryOptionsEnabled = $this->getAttributesProductsOptions($products, 'disable_checkout');

        if ($deliveryOptionsEnabled) {
            $this->deliveryOptionsDisabled = true;
        }

        return $this;
    }

    /**
     * @param array $products
     *
     * @return int|null
     */
    public function getProductDropOffDelay(array $products): ?int
    {
        $highestDropOffDelay = null;

        foreach ($products as $product) {
            $dropOffDelay = $this->getAttributesProductsOptions($product, 'dropoff_delay');

            if ($dropOffDelay > $highestDropOffDelay) {
                $highestDropOffDelay = $dropOffDelay;
            }
        }

        return $highestDropOffDelay > 0 ? $highestDropOffDelay : null;
    }

    /**
     * @param array  $products
     * @param string $carrierPath
     *
     * @return bool
     */
    public function getAgeCheck(array $products, string $carrierPath): bool
    {
        foreach ($products as $product) {
            $productAgeCheck  = (bool) $this->getAttributesProductsOptions($product, 'age_check');

            if ($productAgeCheck) {
                return true;
            }
        }

        return (bool) $this->getConfigValue($carrierPath . 'default_options/age_check_active');
    }

    /**
     * Init all digital stamp settings
     *
     * @param  string $carrierPath
     *
     * @return $this
     */
    public function setDigitalStampSettings(string $carrierPath = self::XML_PATH_POSTNL_SETTINGS): PackageRepository
    {
        $settings = $this->getConfigValue("{$carrierPath}digital_stamp");
        if (null === $settings || ! array_key_exists('active', $settings)) {
            $this->_logger->critical("Can't set settings with path: {$carrierPath}digital_stamp");

            return $this;
        }

        $this->setDigitalStampActive('1' === $settings['active']);
        if ($this->isDigitalStampActive()) {
            $this->setMaxDigitalStampWeight(self::MAXIMUM_DIGITAL_STAMP_WEIGHT);
        }

        return $this;
    }

    /**
     * @param \Magento\Quote\Model\Quote\Item $product
     * @param string                          $column
     *
     * @return null|int
     */
    private function getAttributesProductsOptions($product, string $column): ?int
    {
        $attributeValue = $this->getAttributesFromProduct('catalog_product_entity_varchar', $product, $column);
        if (empty($attributeValue)) {
            $attributeValue = $this->getAttributesFromProduct('catalog_product_entity_int', $product, $column);
        }

        if (isset($attributeValue)) {
            return (int) $attributeValue;
        }

        return null;
    }

    /**
     * @param string                          $tableName
     * @param \Magento\Quote\Model\Quote\Item $product
     * @param string                          $column
     *
     * @return null|string
     */
    private function getAttributesFromProduct(string $tableName, $product, string $column): ?string
    {
        /**
         * @var \Magento\Catalog\Model\ResourceModel\Product $resourceModel
         */
        $objectManager = \Magento\Framework\App\ObjectManager::getInstance();
        $resource      = $objectManager->get('Magento\Framework\App\ResourceConnection');
        $entityId      = $product->getProduct()->getEntityId();
        $connection    = $resource->getConnection();

        $attributeId    = $this->getAttributeId($connection, $resource->getTableName('eav_attribute'), $column);
        $attributeValue = $this
            ->getValueFromAttribute(
                $connection,
                $resource->getTableName($tableName),
                $attributeId,
                $entityId
            );

        return $attributeValue;
    }

    /**
     * @param        $connection
     * @param string $tableName
     * @param string $databaseColumn
     *
     * @return mixed
     */
    private function getAttributeId($connection, string $tableName, string $databaseColumn)
    {
        $sql = $connection
            ->select('entity_type_id')
            ->from($tableName)
            ->where('attribute_code = ?', 'myparcel_' . $databaseColumn);

        return $connection->fetchOne($sql);
    }

    /**
     * @param        $connection
     * @param string $tableName
     * @param string $attributeId
     * @param string $entityId
     *
     * @return mixed
     */
    private function getValueFromAttribute($connection, string $tableName, string $attributeId, string $entityId)
    {
        $sql = $connection
            ->select()
            ->from($tableName, ['value'])
            ->where('attribute_id = ?', $attributeId)
            ->where('entity_id = ?', $entityId);

        return $connection->fetchOne($sql);
    }
}
