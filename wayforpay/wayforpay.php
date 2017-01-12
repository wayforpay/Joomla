<?php
#ini_set("display_errors", true);
#error_reporting(E_ALL);

if (!defined('_VALID_MOS') && !defined('_JEXEC')) {
    die('Direct Access to ' . basename(__FILE__) . ' is not allowed.');
}

if (!class_exists('vmPSPlugin')) {
    require(JPATH_VM_PLUGINS . DS . 'vmpsplugin.php');
}

class plgVmPaymentWayforpay extends vmPSPlugin
{

    // instance of class
    public static $_this = false;


    function __construct(& $subject, $config)
    {

        parent::__construct($subject, $config);

        $this->_loggable = TRUE;
        $varsToPush = $this->getVarsToPush();

        $this->setConfigParameterable($this->_configTableFieldName, $varsToPush);

    }


    function __getVmPluginMethod($method_id)
    {
        if (!($method = $this->getVmPluginMethod($method_id))) {
            return null;
        } else {
            return $method;
        }
    }

    function plgVmConfirmedOrder($cart, $order)
    {
        if (!($method = $this->getVmPluginMethod($order['details']['BT']->virtuemart_paymentmethod_id))) {
            return null; // Another method was selected, do nothing
        }
        if (!$this->selectedThisElement($method->payment_element)) {
            return false;
        }

        include_once(dirname(__FILE__) . DS . "/WayForPay.cls.php");
        if (!class_exists('VirtueMartModelCurrency')) {
            require(JPATH_VM_ADMINISTRATOR . DS . 'models' . DS . 'currency.php');
        }

        JFactory::getLanguage()->load($filename = 'com_virtuemart', JPATH_ADMINISTRATOR);
        $vendorId = 0;

        $html = "";

        if (!class_exists('VirtueMartModelOrders')) {
            require(JPATH_VM_ADMINISTRATOR . DS . 'models' . DS . 'orders.php');
        }

        $this->getPaymentCurrency($method);

        $currencyModel = new VirtueMartModelCurrency();
        $currencyObj = $currencyModel->getCurrency($order['details']['BT']->order_currency);
        $currency = $currencyObj->currency_code_3;

        $w4p = new WayForPay();
        $w4p->setSecretKey($method->wayforpay_secret_key);

        list($lang,) = explode('-', JFactory::getLanguage()->getTag());

        $paymentMethodID = $order['details']['BT']->virtuemart_paymentmethod_id;
        $returnUrl = JROUTE::_(JURI::root() . 'index.php?option=com_virtuemart&view=pluginresponse&task=pluginresponsereceived&pm=' . $paymentMethodID);
        $serviceUrl = JROUTE::_(JURI::root() . 'index.php?option=com_virtuemart&view=pluginresponse&task=pluginnotification&tmpl=component&pm=' . $paymentMethodID);

        $user = &$cart->BT;

        $orderDetails = $order['details']['BT'];
        $formFields = array(
            'orderReference' => $cart->order_number . WayForPay::ORDER_SEPARATOR . time(),
            'merchantAccount' => $method->wayforpay_merchant_account,
            'merchantAuthType' => 'simpleSignature',
            'merchantDomainName' => $_SERVER['HTTP_HOST'],
            'merchantTransactionSecureType' => 'AUTO',
            'orderDate' => strtotime($orderDetails->created_on),
            'amount' => round($orderDetails->order_total,2),
            'currency' => $method->wayforpay_currency,
            'serviceUrl' => $serviceUrl,
            'returnUrl' => $returnUrl,
            'language' => strtoupper($lang)
        );
        if ($currency != 'UAH') {
            //TODO
        }

        $productNames = array();
        $productQty = array();
        $productPrices = array();
        foreach ($order['items'] as $item) {
            $productNames[] = $item->order_item_name;
            $productPrices[] = round($item->product_final_price, 2);
            $productQty[] = $item->product_quantity;
        }

        $formFields['productName'] = $productNames;
        $formFields['productPrice'] = $productPrices;
        $formFields['productCount'] = $productQty;

        /**
         * Check phone
         */
        if (!empty($orderDetails->phone_1)) {
            $phone = $orderDetails->phone_1;
        } else {
            $phone = $orderDetails->phone_2;
        }
        $phone = str_replace(array('+', ' ', '(', ')'), array('', '', '', ''), $phone);
        if (strlen($phone) == 10) {
            $phone = '38' . $phone;
        } elseif (strlen($phone) == 11) {
            $phone = '3' . $phone;
        }

        $formFields['clientFirstName'] = $orderDetails->first_name;
        $formFields['clientLastName'] = $orderDetails->last_name;
        $formFields['clientEmail'] = $orderDetails->email;
        $formFields['clientPhone'] = $phone;
        $formFields['clientCity'] = $orderDetails->city;
        $formFields['clientAddress'] = $orderDetails->address_1 . ' ' . $orderDetails->address_2;
//        $option['clientCountry'] = 'UKR';

        $formFields['merchantSignature'] = $w4p->getRequestSignature($formFields);

        $wayforpayArgsArray = array();
        foreach ($formFields as $key => $value) {
            if (is_array($value)) {
                foreach ($value as $v) {
                    $wayforpayArgsArray[] = "<input type='hidden' name='{$key}[]' value='$v'/>";
                }
            } else {
                $wayforpayArgsArray[] = "<input type='hidden' name='$key' value='$value'/>";
            }
        }

        $html = '	<form action="' . Wayforpay::URL . '" method="post" id="wayforpay_payment_form">
  				' . implode('', $wayforpayArgsArray) .
            '</form>' .
            "<div><img src='/plugins/vmpayment/wayforpay/wayforpay/assets/images/loader.gif' width='50px' style='margin:20px 20px;'></div>" .
            "<script> setTimeout(function() {
                 document.getElementById('wayforpay_payment_form').submit();
             }, 100);
            </script>";
        // 	2 = don't delete the cart, don't send email and don't redirect
        return $this->processConfirmedOrderPaymentResponse(2, $cart, $order, $html, '');
    }

    function plgVmOnPaymentResponseReceived(&$html)
    {
        $method = $this->getVmPluginMethod(JRequest::getInt('pm', 0));
        if (!$this->selectedThisElement($method->payment_element)) {
            return false;
        }

        if (!class_exists('VirtueMartCart'))
            require(JPATH_VM_SITE . DS . 'helpers' . DS . 'cart.php');

        // get the correct cart / session
        $cart = VirtueMartCart::getCart();
        $cart->emptyCart();

        return true;
    }

    function plgVmOnUserPaymentCancel()
    {
        $data = JRequest::get('get');

        list($order_id,) = explode(Wayforpay::ORDER_SEPARATOR, $data['order_id']);
        $order = new VirtueMartModelOrders();

        $order_s_id = $order->getOrderIdByOrderNumber($order_id);
        $orderitems = $order->getOrder($order_s_id);

        $method = $this->getVmPluginMethod($orderitems['details']['BT']->virtuemart_paymentmethod_id);
        if (!$this->selectedThisElement($method->payment_element)) {
            return false;
        }

        if (!class_exists('VirtueMartModelOrders'))
            require(JPATH_VM_ADMINISTRATOR . DS . 'models' . DS . 'orders.php');

        $this->handlePaymentUserCancel($data['oid']);

        return true;
    }

    function plgVmOnPaymentNotification()
    {
        $data = json_decode(file_get_contents("php://input"), true);

        define('_JEXEC', 1);
        define('DS', DIRECTORY_SEPARATOR);
        $option = 'com_virtuemart';
        $my_path = dirname(__FILE__);
        $my_path = explode(DS . 'plugins', $my_path);
        $my_path = $my_path[0];
        if (file_exists($my_path . '/defines.php')) {
            include_once $my_path . '/defines.php';
        }
        if (!defined('_JDEFINES')) {
            define('JPATH_BASE', $my_path);
            require_once JPATH_BASE . '/includes/defines.php';
        }
        define('JPATH_COMPONENT', JPATH_BASE . '/components/' . $option);
        define('JPATH_COMPONENT_SITE', JPATH_SITE . '/components/' . $option);
        define('JPATH_COMPONENT_ADMINISTRATOR', JPATH_ADMINISTRATOR . '/components/' . $option);
        require_once JPATH_BASE . '/includes/framework.php';
        $app = JFactory::getApplication('site');
        $app->initialise();
        if (!class_exists('VmConfig')) require(JPATH_ADMINISTRATOR . DS . 'components' . DS . 'com_virtuemart' . DS . 'helpers' . DS . 'config.php');
        VmConfig::loadConfig();
        if (!class_exists('VirtueMartModelOrders'))
            require(JPATH_VM_ADMINISTRATOR . DS . 'models' . DS . 'orders.fphp');
        if (!class_exists('plgVmPaymentWayforpay'))
            require(dirname(__FILE__) . DS . 'wayforpay.php');

        require(dirname(__FILE__) . DS . 'Wayforpay.cls.php');

        list($order_id,) = explode(Wayforpay::ORDER_SEPARATOR, $data['orderReference']);

        $order = new VirtueMartModelOrders();
        $order_s_id = $order->getOrderIdByOrderNumber($order_id);
        $orderitems = $order->getOrder($order_s_id);


        if (!($method = $this->getVmPluginMethod($orderitems['details']['BT']->virtuemart_paymentmethod_id))) {
            return null; // Another method was selected, do nothing
        }

        $w4p = new WayForPay();
        $w4p->setSecretKey($method->wayforpay_secret_key);


        $response = $w4p->isPaymentValid($data);

        if ($response === true) {
            $orderitems['order_status'] = $method->status_success;
            $orderitems['customer_notified'] = 0;
            $orderitems['virtuemart_order_id'] = $order_s_id;
            $orderitems['comments'] = 'Wayforpay ID: ' . $order_id . " Ref ID : " . $data['orderReference'];
            $order->updateStatusForOneOrder($order_s_id, $orderitems, true);
            echo $w4p->getAnswerToGateWay($data);
        } else {
            echo $response;
        }
        exit();
    }

    /**
     * Check if the payment conditions are fulfilled for this payment method
     * @author: Valerie Isaksen
     *
     * @param $cart_prices : cart prices
     * @param $payment
     * @return true: if the conditions are fulfilled, false otherwise
     *
     */
    protected
    function checkConditions($cart, $method, $cart_prices)
    {
        return true;
    }

    /*
     * We must reimplement this triggers for joomla 1.7
     */

    /**
     * Create the table for this plugin if it does not yet exist.
     * This functions checks if the called plugin is active one.
     * When yes it is calling the standard method to create the tables
     * @author Valérie Isaksen
     *
     */
    function plgVmOnStoreInstallPaymentPluginTable($jplugin_id)
    {
        return $this->onStoreInstallPluginTable($jplugin_id);
    }

    /**
     * This event is fired after the payment method has been selected. It can be used to store
     * additional payment info in the cart.
     *
     * @author Max Milbers
     * @author Valérie isaksen
     *
     * @param VirtueMartCart $cart : the actual cart
     * @return null if the payment was not selected, true if the data is valid, error message if the data is not vlaid
     *
     */
    public
    function plgVmOnSelectCheckPayment(VirtueMartCart $cart)
    {
        return $this->OnSelectCheck($cart);
    }

    /**
     * plgVmDisplayListFEPayment
     * This event is fired to display the pluginmethods in the cart (edit shipment/payment) for exampel
     *
     * @param object $cart Cart object
     * @param integer $selected ID of the method selected
     * @return boolean True on succes, false on failures, null when this plugin was not selected.
     * On errors, JError::raiseWarning (or JError::raiseError) must be used to set a message.
     *
     * @author Valerie Isaksen
     * @author Max Milbers
     */
    public
    function plgVmDisplayListFEPayment(VirtueMartCart $cart, $selected = 0, &$htmlIn)
    {
        return $this->displayListFE($cart, $selected, $htmlIn);
    }

    /*
     * plgVmonSelectedCalculatePricePayment
     * Calculate the price (value, tax_id) of the selected method
     * It is called by the calculator
     * This function does NOT to be reimplemented. If not reimplemented, then the default values from this function are taken.
     * @author Valerie Isaksen
     * @cart: VirtueMartCart the current cart
     * @cart_prices: array the new cart prices
     * @return null if the method was not selected, false if the shiiping rate is not valid any more, true otherwise
     *
     *
     */

    public
    function plgVmonSelectedCalculatePricePayment(VirtueMartCart $cart, array &$cart_prices, &$cart_prices_name)
    {
        return $this->onSelectedCalculatePrice($cart, $cart_prices, $cart_prices_name);
    }

    function plgVmgetPaymentCurrency($virtuemart_paymentmethod_id, &$paymentCurrencyId)
    {

        if (!($method = $this->getVmPluginMethod($virtuemart_paymentmethod_id))) {
            return null; // Another method was selected, do nothing
        }
        if (!$this->selectedThisElement($method->payment_element)) {
            return false;
        }
        $this->getPaymentCurrency($method);

        $paymentCurrencyId = $method->payment_currency;
    }

    /**
     * plgVmOnCheckAutomaticSelectedPayment
     * Checks how many plugins are available. If only one, the user will not have the choice. Enter edit_xxx page
     * The plugin must check first if it is the correct type
     * @author Valerie Isaksen
     * @param VirtueMartCart cart: the cart object
     * @return null if no plugin was found, 0 if more then one plugin was found,  virtuemart_xxx_id if only one plugin is found
     *
     */
    function plgVmOnCheckAutomaticSelectedPayment(VirtueMartCart $cart, array $cart_prices = array())
    {
        return $this->onCheckAutomaticSelected($cart, $cart_prices);
    }

    /**
     * This method is fired when showing the order details in the frontend.
     * It displays the method-specific data.
     *
     * @param integer $order_id The order ID
     * @return mixed Null for methods that aren't active, text (HTML) otherwise
     * @author Max Milbers
     * @author Valerie Isaksen
     */
    public
    function plgVmOnShowOrderFEPayment($virtuemart_order_id, $virtuemart_paymentmethod_id, &$payment_name)
    {
        $this->onShowOrderFE($virtuemart_order_id, $virtuemart_paymentmethod_id, $payment_name);
    }


    /**
     * This method is fired when showing when priting an Order
     * It displays the the payment method-specific data.
     *
     * @param integer $_virtuemart_order_id The order ID
     * @param integer $method_id method used for this order
     * @return mixed Null when for payment methods that were not selected, text (HTML) otherwise
     * @author Valerie Isaksen
     */
    function plgVmonShowOrderPrintPayment($order_number, $method_id)
    {
        return $this->onShowOrderPrint($order_number, $method_id);
    }

    function plgVmDeclarePluginParamsPayment($name, $id, &$data)
    {
        return $this->declarePluginParams('payment', $name, $id, $data);
    }

    function plgVmSetOnTablePluginParamsPayment($name, $id, &$table)
    {
        return $this->setOnTablePluginParams($name, $id, $table);
    }

}

// No closing tag
