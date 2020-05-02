<?php

/**
 * @package       VM Payment - Korapay
 * @author        Korapay Developers
 * @copyright     Copyright (C) 2020 Korapay. All rights reserved.
 * @version       1.0.0,January 2020
 * @license       GNU General Public License version 2 or later; see LICENSE
 */

defined('_JEXEC') or die('Direct access to ' . basename(__FILE__) . ' is not allowed.');

if (!class_exists('vmPSPlugin'))
    require(JPATH_VM_PLUGINS . DIRECTORY_SEPARATOR . 'vmpsplugin.php');


class plgVmPaymentKorapay extends vmPSPlugin
{
      function __construct(&$subject, $config)
    {
        parent::__construct($subject, $config);

        $this->_loggable  = true;
        $this->_tablepkey = 'id';
        $this->_tableId   = 'id';

        $this->tableFields = array_keys($this->getTableSQLFields());

        $varsToPush = array(
            'test_mode' => array(
                1,
                'int'
            ), // korapay.xml (test_mode)
            'live_secret_key' => array(
                '',
                'char'
            ), // korapay.xml (live_secret_key)
            'live_public_key' => array(
                '',
                'char'
            ), // korapay.xml (live_public_key)
            'test_secret_key' => array(
                '',
                'char'
            ), // korapay.xml (test_secret_key)
            'test_public_key' => array(
                '',
                'char'
            ), // korapay.xml (test_public_key)
            'status_pending' => array(
                '',
                'char'
            ),
            'status_success' => array(
                '',
                'char'
            ),
            'status_canceled' => array(
                '',
                'char'
            ),

            'min_amount' => array(
                0,
                'int'
            ),
            'max_amount' => array(
                0,
                'int'
            ),
            'cost_per_transaction' => array(
                0,
                'int'
            ),
            'cost_percent_total' => array(
                0,
                'int'
            ),
            'tax_id' => array(
                0,
                'int'
            )
        );

        $this->setConfigParameterable($this->_configTableFieldName, $varsToPush);
    }
    public function getVmPluginCreateTableSQL()
    {
        return $this->createTableSQL('Payment Korapay Table');
    }
       function getTableSQLFields()
    {
        $SQLfields = array(
            'id' => 'tinyint(1) unsigned NOT NULL AUTO_INCREMENT',
            'virtuemart_order_id' => 'int(11) UNSIGNED DEFAULT NULL',
            'order_number' => 'char(32) DEFAULT NULL',
            'virtuemart_paymentmethod_id' => 'mediumint(1) UNSIGNED DEFAULT NULL',
            'payment_name' => 'char(255) NOT NULL DEFAULT \'\' ',
            'payment_order_total' => 'decimal(15,5) NOT NULL DEFAULT \'0.00000\' ',
            'payment_currency' => 'char(3) ',
            'cost_per_transaction' => ' decimal(10,2) DEFAULT NULL ',
            'cost_percent_total' => ' decimal(10,2) DEFAULT NULL ',
            'tax_id' => 'smallint(11) DEFAULT NULL',
            'korapay_transaction_reference' => 'char(32) DEFAULT NULL'
        );

        return $SQLfields;
    }

      function getKorapaySettings($payment_method_id)
    {
        $korapay_settings = $this->getPluginMethod($payment_method_id);

        if ($korapay_settings->test_mode) {
            $secret_key = $korapay_settings->test_secret_key;
            $public_key = $korapay_settings->test_public_key;
           
        } else {
            $secret_key = $korapay_settings->live_secret_key;
            $public_key = $korapay_settings->live_public_key;
        }

        $secret_key = str_replace(' ', '', $secret_key);
        $public_key = str_replace(' ', '', $public_key);
         $baseUrl = ' https://gateway.koraapi.com/merchant';
        $apiLink = ' https://gateway.koraapi.com/merchant';

        return array(
            'secret_key' => $secret_key,
            'public_key' => $public_key,
            'apiLink'=>$apiLink
        );
    }

    function plgVmConfirmedOrder($cart,$order)
    {
     
        if (!($method = $this->getVmPluginMethod($order['details']['BT']->virtuemart_paymentmethod_id))) {
            return null;
        }

        if (!$this->selectedThisElement($method->payment_element)) {
            return false;
        }   
        if (!class_exists('VirtueMartModelOrders'))
            require(JPATH_VM_ADMINISTRATOR . DIRECTORY_SEPARATOR . 'models' . DIRECTORY_SEPARATOR . 'orders.php');

        if (!class_exists('VirtueMartModelCurrency'))
            require(JPATH_VM_ADMINISTRATOR . DIRECTORY_SEPARATOR . 'models' . DIRECTORY_SEPARATOR . 'currency.php');
        
        // Get current order info
        $order_info = $order['details']['BT'];
        $country_code = ShopFunctions::getCountryByID($order_info->virtuemart_country_id, 'country_3_code');
    

           // Get payment currency
        $this->getPaymentCurrency($method);
        $db = JFactory::getDbo();
        $query = $db->getQuery(true);
        $query->select('currency_code_3');
        $query->from($db->quoteName('#__virtuemart_currencies'));
        $query->where($db->quoteName('virtuemart_currency_id')
                . ' = ' . $db->quote($method->payment_currency));
        $db->setQuery($query);
        $currency_code = $db->loadResult();

    // Get total amount for the current payment currency
        $totalInPaymentCurrency = vmPSPlugin::getAmountInCurrency($order['details']['BT']->order_total, $method->payment_currency);

         // Prepare data that should be stored in the database
        $dbValues['order_number'] = $order['details']['BT']->order_number;
        $dbValues['payment_name'] = $this->renderPluginName($method, $order);
        $dbValues['virtuemart_paymentmethod_id'] = $order['details']['BT']->virtuemart_paymentmethod_id;
        $dbValues['cost_per_transaction'] = $method->cost_per_transaction;
        $dbValues['cost_percent_total'] = $method->cost_percent_total;
        $dbValues['payment_currency'] = $method->payment_currency;
        $dbValues['payment_order_total'] = $totalInPaymentCurrency;
        $dbValues['tax_id'] = $method->tax_id;
        $dbValues['korapay_transaction_reference'] = $dbValues['order_number'] . '-' . date('YmdHis');
     
         $this->storePSPluginInternalData($dbValues);

         // Redirect URL - Verify Korapay payment
        $redirect_url = JURI::root() . 'index.php?option=com_virtuemart&view=pluginresponse&task=pluginresponsereceived&on=' . $order['details']['BT']->order_number . '&pm=' . $order['details']['BT']->virtuemart_paymentmethod_id . '&Itemid=' . vRequest::getInt('Itemid') . '&lang=' . vRequest::getCmd('lang', '');

           // Korapay Settings
        $payment_method_id = $dbValues['virtuemart_paymentmethod_id'];//vRequest::getInt('virtuemart_paymentmethod_id');
        $korapay_settings = $this->getKorapaySettings($payment_method_id);

        $transactionData = array();
        $transactionData['public_key'] = $korapay_settings['public_key'];
        $transactionData['customer_email'] = $order_info->email;
        $transactionData['customer_firstname'] = $order_info->firstname;
        $transactionData['customer_lastname'] = $order_info->lastname;
        $transactionData['customer_phone'] = $order_info->phone;
        $transactionData['redirect_url'] = $redirect_url;
        $transactionData['reference'] = $dbValues['korapay_transaction_reference'];
        $transactionData['amount'] = $totalInPaymentCurrency['value'] + 0;
        //Korapay gateway html code
        $html = "
        <script type='text/javascript' src='https://korablobstorage.blob.core.windows.net/modal-bucket/korapay-collections.min.js'></script>
        <script>
           document . addEventListener('DOMContentLoaded', function (event) {
            var data = JSON.parse('" . json_encode($transactionData) . "')
            Korapay.initialize({
                  key: data.public_key,
                  reference:data.reference,
                  amount: Number(data.amount),
                  currency: 'NGN',
                  customer:{
                      name: data.customer_firstname + ' ' + data.customer_lastname,
                      email: data.customer_email
                  },
                  onClose: function () {
                        
                  },
                  onSuccess: function (res) {
                     window.location.replace(data.redirect_url + '&status=success&reference=' + res.reference + '&amount=' + res.amount);
                  },
                  onFailed: function (res) {
                     if(!res.reference){
                        return  window.location.replace(data.redirect_url + '&status=down&amount=' + res.amount);
                     }
                     window.location.replace(data.redirect_url + '&status=failed&reference=' + res.reference + '&amount=' + res.amount);
                  }
                })
            })
        </script>
        ";
        
        $cart->_confirmDone = false;
        $cart->_dataValidated = false;
        $cart->setCartIntoSession();

        vRequest::setVar('html', $html);
    }

    function plgVmOnPaymentResponseReceived(&$html)
    {
         if (!class_exists('VirtueMartCart')) {
            require(VMPATH_SITE . DIRECTORY_SEPARATOR . 'helpers' . DIRECTORY_SEPARATOR . 'cart.php');
        }
        if (!class_exists('shopFunctionsF')) {
            require(VMPATH_SITE . DIRECTORY_SEPARATOR . 'helpers' . DIRECTORY_SEPARATOR . 'shopfunctionsf.php');
        }
        if (!class_exists('VirtueMartModelOrders')) {
            require(VMPATH_ADMIN . DIRECTORY_SEPARATOR . 'models' . DIRECTORY_SEPARATOR . 'orders.php');
        }

        VmConfig::loadJLang('com_virtuemart_orders', true);
        $post_data = vRequest::getPost();

        // The payment itself should send the parameter needed.
        $virtuemart_paymentmethod_id = vRequest::getInt('pm', 0);

        $order_number = vRequest::getString('on', 0);
        if (!($method = $this->getVmPluginMethod($virtuemart_paymentmethod_id))) {
            return null;
        }

        if (!$this->selectedThisElement($method->payment_element)) {
            return null;
        }

        if (!($virtuemart_order_id = VirtueMartModelOrders::getOrderIdByOrderNumber($order_number))) {
            return null;
        }

        if (!($paymentTable = $this->getDataByOrderId($virtuemart_order_id))) {
            return '';
        }


        VmConfig::loadJLang('com_virtuemart');
        $orderModel = VmModel::getModel('orders');
        $order = $orderModel->getOrder($virtuemart_order_id);

        $reference = $_GET['reference'];
        $status = $_GET['status'];
        $amount = $_GET['amount'];

        $payment_name = $this->renderPluginName($method);
        $html = '<table>' . "\n";
        $html .= $this->getHtmlRow('Payment Name', $payment_name);
        $html .= $this->getHtmlRow('Order Number', $order_number);
        $html .= $this->getHtmlRow('Transaction Reference',$reference);

     

        if($status==='success'){
            //update order status from  pending to complete
             $order['order_status'] = 'C';
            $order['customer_notified'] = 1;
            $orderModel->updateStatusForOneOrder($order['details']['BT']->virtuemart_order_id, $order, true);

            $html .= $this->getHtmlRow('Total Amount', number_format($amount, 2));
            $html .= $this->getHtmlRow('Status', $status);
            $html .= '</table>' . "\n";
            // add order url
            $url = JRoute::_('index.php?option=com_virtuemart&view=orders&layout=details&order_number=' . $order_number, false);
            $html .= '<a href="' . JRoute::_('index.php?option=com_virtuemart&view=orders&layout=details&order_number=' . $order_number, false) . '" class="vm-button-correct">' . vmText::_('COM_VIRTUEMART_ORDER_VIEW_ORDER') . '</a>';

            // Empty cart
            $cart = VirtueMartCart::getCart();
            $cart->emptyCart();

            return true;
        }
        if($status === 'down'){
            die("Your payment couldn't be completed,Please try again or contact the site administrator");
        }

        if($status === 'failed'){
            $html .= $this->getHtmlRow('Total Amount', number_format($transData->amount, 2));
            $html .= $this->getHtmlRow('Status', $status);
            $html .= '</table>' . "\n";
            $html .= '<a href="' . JRoute::_('index.php?option=com_virtuemart&view=cart', false) . '" class="vm-button-correct">' . vmText::_('CART_PAGE') . '</a>';

            // Update order status - From pending to canceled
            $order['order_status'] = 'X';
            $order['customer_notified'] = 1;
            $orderModel->updateStatusForOneOrder($order['details']['BT']->virtuemart_order_id, $order, true);
        }


        return false;
    }

      function plgVmOnUserPaymentCancel()
    {
        return true;
    }

   /**
     * Required functions by Joomla or VirtueMart. Removed code comments due to 'file length'.
     * All copyrights are (c) respective year of author or copyright holder, and/or the author.
     */
    function getCosts(VirtueMartCart $cart, $method, $cart_prices)
    {
        if (preg_match('/%$/', $method->cost_percent_total)) {
            $cost_percent_total = substr($method->cost_percent_total, 0, -1);
        } else {
            $cost_percent_total = $method->cost_percent_total;
        }
        return ($method->cost_per_transaction + ($cart_prices['salesPrice'] * $cost_percent_total * 0.01));
    }
      protected function checkConditions($cart, $method, $cart_prices)
    {
        $this->convert_condition_amount($method);
        $address = (($cart->ST == 0) ? $cart->BT : $cart->ST);
        $amount = $this->getCartAmount($cart_prices);
        $amount_cond = ($amount >= $method->min_amount and $amount <= $method->max_amount or ($method->min_amount <= $amount and ($method->max_amount == 0)));
        $countries = array();
        if (!empty($method->countries)) {
            if (!is_array($method->countries)) {
                $countries[0] = $method->countries;
            } else {
                $countries = $method->countries;
            }
        }
        if (!is_array($address)) {
            $address = array();
            $address['virtuemart_country_id'] = 0;
        }
        if (!isset($address['virtuemart_country_id'])) {
            $address['virtuemart_country_id'] = 0;
        }
        if (in_array($address['virtuemart_country_id'], $countries) || count($countries) == 0) {
            if ($amount_cond) {
                return true;
            }
        }
        return false;
    }

     function plgVmOnStoreInstallPaymentPluginTable($jplugin_id)
    {
        return $this->onStoreInstallPluginTable($jplugin_id);
    }

    public function plgVmOnSelectCheckPayment(VirtueMartCart $cart)
    {
        return $this->OnSelectCheck($cart);
    }

    public function plgVmDisplayListFEPayment(VirtueMartCart $cart, $selected = 0, &$htmlIn)
    {
        return $this->displayListFE($cart, $selected, $htmlIn);
    }

    public function plgVmonSelectedCalculatePricePayment(VirtueMartCart $cart, array &$cart_prices, &$cart_prices_name)
    {
        return $this->onSelectedCalculatePrice($cart, $cart_prices, $cart_prices_name);
    }

    function plgVmOnCheckAutomaticSelectedPayment(VirtueMartCart $cart, array $cart_prices = array(), &$paymentCounter)
    {
        return $this->onCheckAutomaticSelected($cart, $cart_prices, $paymentCounter);
    }

    public function plgVmOnShowOrderFEPayment($virtuemart_order_id, $virtuemart_paymentmethod_id, &$payment_name)
    {
        $this->onShowOrderFE($virtuemart_order_id, $virtuemart_paymentmethod_id, $payment_name);
    }

    function plgVmonShowOrderPrintPayment($order_number, $method_id)
    {
        return $this->onShowOrderPrint($order_number, $method_id);
    }

    function plgVmDeclarePluginParamsPaymentVM3(&$data)
    {
        return $this->declarePluginParams('payment', $data);
    }

    function plgVmSetOnTablePluginParamsPayment($name, $id, &$table)
    {
        return $this->setOnTablePluginParams($name, $id, $table);
    }
}