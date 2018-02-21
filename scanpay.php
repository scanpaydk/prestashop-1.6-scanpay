<?php

if (!defined('_PS_VERSION_')) {
  exit();
}

require_once(dirname(__FILE__) . '/classes/spdb.php');

class Scanpay extends PaymentModule
{
    protected $_html = '';
    protected $_postErrors = array();
    const DFLT_TITLE = 'Credit/Debit Card';

    public function __construct()
    {
        $this->name = 'scanpay';
        $this->tab = 'payments_gateways';
        $this->version = '0.1.1';
        $this->ps_versions_compliancy = array('min' => '1.6', 'max' => _PS_VERSION_);
        $this->author = 'Scanpay ApS';
        $this->need_instance = 0;
        $this->is_eu_compatible = 1;
        $this->bootstrap = true;
        parent::__construct();
        $this->displayName = $this->l('Scanpay');
        $this->description = $this->l('Accept payments using the Scanpay payment gateway');
        $this->confirmUninstall = $this->l('Are you sure?');
    }

    public function install()
    {
        if (!SPDB_Seq::mktable()) {
            return false;
        }
        if (!SPDB_Carts::mktable()) {
            return false;
        }
        if (Shop::isFeatureActive()) { Shop::setContext(Shop::CONTEXT_ALL); }
        return parent::install() &&
            $this->registerHook('paymentReturn') &&
            $this->registerHook('payment');
    }

    public function uninstall()
    {
        return parent::uninstall();
    }

    public function log($msg, $severity = 0, $objectType = null, $objectId = null)
    {
        Logger::addLog($msg, $severity, 0, $objectType, $objectId);
        error_log('Scanpay module error: ' . $msg);
    }

    /* Extract the shopid from an apikey */
    public function extractshopid($apikey)
    {
        $shopid = explode(':', $apikey)[0];
        if (!ctype_digit($shopid)) {
            return false;
        }
        return (int)$shopid;
    }

    /* Create array of payment options to be shown in checkout */
    public function hookPayment($params)
    {
        if (!$this->active) {
            return;
        }
        $title = Configuration::get('SCANPAY_TITLE');
        if (!$title) {
            $title = self::DFLT_TITLE;
        }

        $this->context->smarty->assign([
            'cards_title' =>  $title,
            'cards_link'  => $this->context->link->getModuleLink($this->name, 'newurl', [], true),

            'mobilepay_enabled' => Configuration::get('SCANPAY_MOBILEPAY'),
            'mobilepay_link'    => $this->context->link->getModuleLink($this->name, 'newurl', ['paymentmethod' => 'mobilepay'], true),
        ]);
        return $this->context->smarty->fetch($this->getLocalPath() . 'views/templates/hook/payment.tpl');
    }

    /* Handle the order confirmation page (post-payment) */
    public function hookPaymentReturn($params) 
    { 
        if (!isset($params['order']) || ($params['order']->module != $this->name)) {
            return false;
        }
        $order = $params['order'];
        if (Validate::isLoadedObject($order) && isset($order->valid)) {
            $this->smarty->assign([
                'id_order' => $order->id,
                'valid'    => $order->valid,
            ]);
        }
        if (isset($order->reference) && !empty($order->reference)) {
            $this->smarty->assign('reference', $order->reference);
        }
        $this->smarty->assign([
            'shop_name'   => $this->context->shop->name,
            'reference'   => $order->reference,
            'contact_url' => $this->context->link->getPageLink('contact', true)
        ]);
        return $this->fetch('module:scanpay/views/templates/hook/payment_return.tpl'); 
    }

    /* Configuration handling (Settings) */
    public function getContent()
    {
        $output = null;
        $settings = [
            'SCANPAY_TITLE'       => Configuration::get('SCANPAY_TITLE'),
            'SCANPAY_APIKEY'      => Configuration::get('SCANPAY_APIKEY'),
            'SCANPAY_LANGUAGE'    => Configuration::get('SCANPAY_LANGUAGE'),
            'SCANPAY_AUTOCAPTURE' => Configuration::get('SCANPAY_AUTOCAPTURE'),
            'SCANPAY_MOBILEPAY'   => Configuration::get('SCANPAY_MOBILEPAY'),
        ];


        /* Update configuration if config is submitted */
        if (Tools::isSubmit('submit' . $this->name))
        {
            $settings = [
                'SCANPAY_TITLE'       => strval(Tools::getValue('SCANPAY_TITLE')),
                'SCANPAY_APIKEY'      => strval(Tools::getValue('SCANPAY_APIKEY')),
                'SCANPAY_LANGUAGE'    => strval(Tools::getValue('SCANPAY_LANGUAGE')),
                'SCANPAY_AUTOCAPTURE' => intval(Tools::getValue('SCANPAY_AUTOCAPTURE')),
                'SCANPAY_MOBILEPAY'   => intval(Tools::getValue('SCANPAY_MOBILEPAY')),
            ];
            foreach($settings as $key => $value) {
                Configuration::updateValue($key, $value);
            }
        }

        if (!$settings['SCANPAY_TITLE']) {
            $settings['SCANPAY_TITLE'] = self::DFLT_TITLE;
        }

        /* Setup the configuration form */
        $helper = new HelperForm();
        $helper->table = $this->table;
        $helper->module = $this;
        $helper->default_form_language = $this->context->language->id;
        $helper->allow_employee_form_lang = Configuration::get('PS_BO_ALLOW_EMPLOYEE_FORM_LANG', 0);

        $helper->identifier = $this->identifier;
        $helper->submit_action = 'submit'.$this->name;
        $helper->currentIndex = $this->context->link->getAdminLink('AdminModules', false) . '&configure=' . $this->name . '&tab_module=' . $this->tab . '&module_name=' . $this->name;
        $helper->token = Tools::getAdminTokenLite('AdminModules');

        /* Create Ping URL graphic */
        $shopid = $this->extractshopid($settings['SCANPAY_APIKEY']);
        if ($shopid) {
            $lastpingtime = SPDB_Seq::load($shopid)['mtime'];
        } else {
            $lastpingtime = 0;
        }
        ob_start();
        include($this->local_path . 'views/pingurl.php');
        $pingUrlContent = ob_get_contents();
        ob_end_clean();

        $this->context->controller->addCSS($this->local_path . 'views/pingurl.css');
        $this->context->controller->addJS($this->local_path . 'views/pingurl.js');

        /* Define the configuration form inputs */
        $formdata[0]['form'] = [
            'legend' => [
                'title' => $this->l('Settings'),
            ],
            'input' => [
                [
                    'type'     => 'text',
                    'label'    => $this->l('Title'),
                    'name'     => 'SCANPAY_TITLE',
                    'desc'     => $this->l('This controls the title which the user sees during checkout'),
                    'required' => true,
                ],
                [
                    'type'     => 'text',
                    'label'    => $this->l('API-key'),
                    'name'     => 'SCANPAY_APIKEY',
                    'desc'     => $this->l('Copy your API key from the <a href="https://dashboard.scanpay.dk/settings/api">dashboard API settings</a>'),
                    'required' => true,
                ],
                [
                    'type'     => 'text',
                    'label'    => $this->l('Ping URL'),
                    'name'     => 'pingurl',
                    'desc'     => $this->l('This is the URL Scanpay can use to notify Magento of changes in transaction status.') . $pingUrlContent,
                    'id'       => 'scanpay--pingurl--input',
                    'readonly' => true,
                ],
                [
                    'type'     => 'select',
                    'label'    => $this->l('Language'),
                    'name'     => 'SCANPAY_LANGUAGE',
                    'desc'     => $this->l('Set the payment window language. \'Automatic\' allows Scanpay to choose a language based on the browser of the customer.'),
                    'options'  => [
                        'id'    => 'language',
                        'name'  => 'name',
                        'query' => [
                            [
                                'language' => '',
                                'name'        => $this->l('Automatic'),
                            ],
                            [
                                'language' => 'da',
                                'name'        => $this->l('Danish'),
                            ],
                            [
                                'language' => 'en',
                                'name'        => $this->l('English'),
                            ],
                        ],
                    ],
                ],
                [
                    'type'     => 'switch',
                    'label'    => $this->l('Auto-capture'),
                    'name'     => 'SCANPAY_AUTOCAPTURE',
                    'desc'     => $this->l('Automatically capture transactions upon authorization. Only enable this if you sell services or immaterial goods.'),
                    'is_bool' => true,
                    'values'  => [
                        [
                            'id'    => 'on',
                            'value' => 1,
                            'name'  => $this->l('Enabled'),
                        ],
                        [
                            'id'    => 'off',
                            'value' => 0,
                            'name'  => $this->l('Disabled'),
                        ],
                    ],
                ],
                [
                    'type'     => 'switch',
                    'label'    => $this->l('MobilePay'),
                    'name'     => 'SCANPAY_MOBILEPAY',
                    'desc'     => $this->l('Enable MobilePay in checkout. You must also enable MobilePay in the Scanpay dashboard.'),
                    'is_bool' => true,
                    'values'  => [
                        [
                            'id'    => 'on',
                            'value' => 1,
                            'name'  => $this->l('Enabled'),
                        ],
                        [
                            'id'    => 'off',
                            'value' => 0,
                            'name'  => $this->l('Disabled'),
                        ],
                    ],
                ],
            ],
            'submit' => [
                'title' => $this->l('Save'),
                'class' => 'btn btn-default pull-right'
            ],
        ];

        foreach($settings as $key => $value) {
            $helper->fields_value[$key] = $value; 
        }
        $helper->fields_value['pingurl'] = $this->context->link->getModuleLink($this->name, 'ping', [], true);

        return $helper->generateForm($formdata);

    }

}