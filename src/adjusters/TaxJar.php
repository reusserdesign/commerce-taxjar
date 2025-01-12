<?php
/**
 * @link https://craftcms.com/
 * @copyright Copyright (c) Pixel & Tonic, Inc.
 * @license https://craftcms.github.io/license/
 */

namespace craft\commerce\taxjar\adjusters;

use Craft;
use craft\base\Component;
use craft\commerce\adjusters\Shipping;
use craft\commerce\base\AdjusterInterface;
use craft\commerce\elements\Order;
use craft\commerce\models\Address;
use craft\commerce\models\OrderAdjustment;
use craft\commerce\Plugin;
use craft\commerce\taxjar\TaxJar as TaxJarPlugin;
use DvK\Vat\Validator;
use TaxJar\Exception;

/**
 * Tax Adjustments
 *
 * @author Pixel & Tonic, Inc. <support@pixelandtonic.com>
 * @since 1.0
 *
 * @property Validator $vatValidator
 */
class TaxJar extends Component implements AdjusterInterface
{
    const ADJUSTMENT_TYPE = 'tax';

    /**
     * @var Order
     */
    private $_order;

    /**
     * @var Address
     */
    private $_address;

    /**
     * @var mixed
     */
    private $_taxesByOrderHash;

    /**
     * @inheritdoc
     */
    public function adjust(Order $order): array
    {
        $this->_order = $order;

        $this->_address = $this->_order->getShippingAddress();

        if (Plugin::getInstance()->getSettings()->useBillingAddressForTax) {
            $this->_address = $this->_order->getBillingAddress();
        }

        if (!$this->_address) {
            $this->_address = $order->getEstimatedShippingAddress();
        }

        if (Plugin::getInstance()->getSettings()->useBillingAddressForTax) {
            if (!$this->_address) {
                $this->_address = $this->_order->getEstimatedBillingAddress();
            }
        }

        if (!$this->_address) {
            return [];
        }

        try {
            $orderTaxes = $this->_getOrderTaxData();
        } catch (Exception $e) {
            $message = 'TaxJar API error code: ' . $e->getStatusCode() . ' Message: ' . $e->getMessage();
            Craft::error($message, 'commerce-taxjar');

            if (TaxJarPlugin::getInstance()->getSettings()->useSandbox) {
                $adjustment = new OrderAdjustment();
                $adjustment->type = self::ADJUSTMENT_TYPE;
                $adjustment->name = Craft::t('commerce', 'TaxJar Error');
                $adjustment->amount = 0;
                $adjustment->description = $message;
                $adjustment->setOrder($this->_order);
                $adjustment->sourceSnapshot = [];

                return [$adjustment];
            }
            return [];
        }

        $adjustment = new OrderAdjustment();
        $adjustment->type = self::ADJUSTMENT_TYPE;
        $adjustment->name = Craft::t('commerce', 'Tax');
        $adjustment->amount = $orderTaxes->amount_to_collect;
        $adjustment->description = '';
        $adjustment->setOrder($this->_order);
        $adjustment->sourceSnapshot = json_decode(json_encode($orderTaxes), true);

        return [$adjustment];
    }

    /**
     * @param Order $order
     */
    private function _getOrderHash()
    {
        $number = $this->_order->number;
        $lineItems = '';
        $address = '';
        $count = 0;
        foreach ($this->_order->getLineItems() as $item) {
            $count++;
            $lineItems = $count . ':' . $item->getOptionsSignature() . ':' . $item->qty . ':' . $item->getSubtotal();
        }
        $price = $this->_order->getTotalPrice();

        if ($this->_address) {
            $address .= $this->_address->address1;
            $address .= $this->_address->address2;
            $address .= $this->_address->address3;
            $address .= $this->_address->zipCode;
            $address .= $this->_address->stateText;
            $address .= $this->_address->countryText;
        }

        return md5($number . ':' . $lineItems . ':' . $address . ':' . $price);
    }

    private function _getOrderTaxData()
    {
        $orderHash = $this->_getOrderHash();
        $storeLocation = Plugin::getInstance()->getAddresses()->getStoreLocationAddress();
        $client = TaxJarPlugin::getInstance()->getApi()->getClient();

        // Do we already have it on this request?
        if (isset($this->_taxesByOrderHash[$orderHash]) && $this->_taxesByOrderHash[$orderHash] != false) {
            return $this->_taxesByOrderHash[$orderHash];
        }

        $lineItems = [];

        foreach ($this->_order->getLineItems() as $lineItem) {
            $category = Plugin::getInstance()->getTaxCategories()->getTaxCategoryById($lineItem->taxCategoryId);
            $lineItems[] = [
                'id' => $lineItem->id,
                'quantity' => $lineItem->qty,
                'unit_price' => $lineItem->salePrice,
                'discount' => $lineItem->getDiscount() * -1,
                'product_tax_code' => $category ? $category->handle : null
            ];
        }

        $cacheKey = 'taxjar-' . $orderHash;
        // Is it in the cache? if not, get it from the api.
        $orderData = Craft::$app->getCache()->get($cacheKey);

        if (!$orderData) {

            $orderData = $client->taxForOrder([
                'from_country' => $storeLocation->getCountry()->iso ?? '',
                'from_zip' => $storeLocation->zipCode ?? '',
                'from_state' => $storeLocation->getState()->abbreviation ?? '',
                'to_country' => $this->_address->getCountry()->iso ?? '',
                'to_zip' => $this->_address->zipCode ?? '',
                'to_state' => $this->_address->getState()->abbreviation ?? '',
                //'amount' => We pass line items so not needed
                'shipping' => $this->_order->getTotalShippingCost(),
                'line_items' => $lineItems
            ]);

            // Save data into cache
            Craft::$app->getCache()->set($cacheKey, $orderData);
        }

        $this->_taxesByOrderHash[$orderHash] = $orderData;

        return $this->_taxesByOrderHash[$orderHash];
    }
}
