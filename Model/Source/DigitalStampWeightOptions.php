<?php
/**
 * Get only the "No" option for in the MyParcel system settings
 * This option is used with settings that are not possible because an parent option is turned off.
 *
 * If you want to add improvements, please create a fork in our GitHub:
 * https://github.com/myparcelnl
 *
 * @author      Richard Perdaan <support@myparcel.nl>
 * @copyright   2010-2017 MyParcel
 * @license     http://creativecommons.org/licenses/by-nc-nd/3.0/nl/deed.en_US  CC BY-NC-ND 3.0 NL
 * @link        https://github.com/myparcelnl/magento
 * @since       File available since Release v0.1.0
 */

namespace MyParcelNL\Magento\Model\Source;

use Magento\Framework\Data\OptionSourceInterface;
use Magento\Sales\Model\Order;
use MyParcelNL\Magento\Helper\Data;
use MyParcelNL\Sdk\src\Model\Carrier\CarrierPostNL;

/**
 * @api
 * @since 100.0.2
 */
class DigitalStampWeightOptions implements OptionSourceInterface
{
    /**
     * @var Data
     */
    static private $helper;

    /**
     * Insurance constructor.
     *
     * @param $order Order
     * @param $helper Data
     */
    public function __construct(Data $helper)
    {
        self::$helper = $helper;
    }

    /**
     * @param $option
     *
     * @return bool
     */
    public function getDefault($option): bool
    {
        $settings = self::$helper->getStandardConfig(CarrierPostNL::NAME, 'options');

        return (bool) $settings[$option . '_active'];
    }

    /**
     * Options getter
     *
     * @return array
     */
    public function toOptionArray()
    {
        $digitalStampOptions = [
            ['value' => 0, 'label' => __('No standard weight')],
            ['value' => 20, 'label' => __('0 - 20 gram')],
            ['value' => 50, 'label' => __('20 - 50 gram')],
            ['value' => 100, 'label' => __('50 - 100 gram')],
            ['value' => 350, 'label' => __('100 - 350 gram')],
            ['value' => 2000, 'label' => __('350 - 2000 gram')]
        ];

        return $digitalStampOptions;
    }
}
