<?php
/*
   Plugin Name: Оплата через Модульбанк
   Description: Платежный модуль WooCommerce для приема платежей с помощью Модульбанка.
   Version: 0.1
*/

function init_modulbank() {
    if (!class_exists('FPaymentsForm')) {
        include(dirname(__FILE__) . '/inc/fpayments.php');
    }

    class ModulbankCallback extends AbstractFPaymentsCallbackHandler {
        private $plugin;
        function __construct(WC_Gateway_Modulbank $plugin)  {
            $this->plugin = $plugin;
        }
        protected function get_fpayments_form()             {
            return $this->plugin->get_fpayments_form();
        }
        protected function load_order($order_id) {
            return wc_get_order($order_id);
        }
        protected function get_order_currency($order) {
            return $order->get_currency();
        }
        protected function get_order_amount($order) {
            return $order->get_total();
        }
        protected function is_order_completed($order) {
            return $order->is_paid();
        }
        protected function mark_order_as_completed($order, array $data) {
            return $order->payment_complete();
        }
        protected function mark_order_as_error($order, array $data) {
            //
        }
    }

    class WC_Gateway_Modulbank extends WC_Payment_Gateway {
        private $callback_url;

        function __construct() {
            $this->id = FPaymentsConfig::PREFIX;
            $this->method_title = __("Модульбанк");
            $this->method_description = __("Оплата банковскими картами");

            $this->callback_url = get_home_url() . '/?modulbank=callback';
            $this->success_url =  FPaymentsForm::abs('/success');
            $this->fail_url =  FPaymentsForm::abs('/success');

            $this->has_fields = false;
            $this->init_form_fields();
            $this->init_settings();

            $this->title = __("Оплата банковской картой через Модульбанк");
            $this->description = '';

            if (version_compare(WOOCOMMERCE_VERSION, '2.0.0', '>=')) {
                add_action(
                    'woocommerce_update_options_payment_gateways_'.$this->id,
                    array($this, 'process_admin_options')
                );
            } else {
                add_action('woocommerce_update_options_payment_gateways', array($this, 'process_admin_options'));
            }
            add_action('woocommerce_receipt_'.$this->id, array($this, 'receipt_page'));
        }

        public function get_callback_url()
        {
            return $this->callback_url;
        }

        public function get_success_url($order)
        {
           if($this->settings['custom_success_page'] == 'yes')
           {
                return $this->settings['success_url'] ?: $this->success_url;
           }

           return $this->get_return_url($order) ?: $this->success_url;
        }


        function init_form_fields() {
            $this->form_fields = array(
                'enabled' => array(
                    'title' => __('Платежный метод активен', 'modulbank'),
                    'type' => 'checkbox',
                    'label' => ' ',
                    'default' => 'yes',
                    'description' => '',
                ),
                'merchant_id' => array(
                    'title' => 'Идентификатор магазина',
                    'type' => 'text',
                    'description' => __('merchant_id из личного кабинета Модульбанка', 'modulbank'),
                    'default' => '',
                ),
                'secret_key' => array(
                    'title' => 'Секретный ключ',
                    'type' => 'text',
                    'description' => __('secret_key из личного кабинета Модульбанка', 'modulbank'),
                    'default' => '',
                ),
                'custom_success_page' => array(
                    'title' => 'Включить собственную страницу «платёж прошёл»',
                    'type' => 'checkbox',
                    'label' => 'Собственная страница «платёж прошёл»',
                    'default' => 'no',
                ),
                'success_url' => array(
                    'title' => 'Страница «платёж прошёл»',
                    'type' => 'text',
                    'default' => FPaymentsForm::abs('/success'),
                ),
                'fail_url' => array(
                    'title' => 'Страница «платёж не удался»',
                    'type' => 'text',
                    'default' => FPaymentsForm::abs('/fail'),
                ),
                'test_mode' => array(
                    'title' => __('Тестовый режим', 'modulbank'),
                    'type' => 'checkbox',
                    'label' => __('Тестовый режим', 'modulbank'),
                    'default' => 'yes',
                    'description' => __('Тестовый режим используется для проверки работы интеграции. При выполнении тестовых транзакций реального зачисления среств на счет магазина не производится.',
                        'modulbank'
                ),
                ),



                'sno' =>  array(
                    'title' => __('Система налогообложения', 'modulbank'),
                    'type' => 'select',
                    'options' => array(
                        'osn' => 'Общая',
                        'usn_income' => 'Упрощенная СН (доходы)',
                        'usn_income_outcome' => 'Упрощенная СН (доходы минус расходы)',
                        'envd' => 'Единый налог на вмененный доход',
                        'esn' => 'Единый сельскохозяйственный налог',
                        'patent' => 'Патентная СН',
                    ),
                    'default' => 'osn'
                ),
                'payment_object' =>  array(
                    'title' => __('Предмет расчета', 'modulbank'),
                    'type' => 'select',
                    'options' => array(
                        'commodity' => 'Товар',
                        'excise' => 'Подакцизный товар',
                        'job' => 'Работа',
                        'service' => 'Услуга',
                        'gambling_bet' => 'Ставка азартной игры',
                        'gambling_prize' => 'Выигрыш азартной игры',
                        'lottery' => 'Лотерейный билет',
                        'lottery_prize' => 'Выигрыш лотереи',
                        'intellectual_activity' => 'Предоставление результатов интеллектуальной деятельности',
                        'payment' => 'Платеж',
                        'agent_commission' => 'Агентское вознаграждение',
                        'composite' => 'Составной предмет расчета',
                        'another' => 'Другое'
                    ),
                    'default' => 'commodity'
                ),
                'payment_method' =>  array(
                    'title' => __('Метод платежа', 'modulbank'),
                    'type' => 'select',
                    'options' => array(
                        'full_prepayment' => 'Предоплата 100%',
                        'prepayment' => 'Предоплата',
                        'advance' => 'Аванс',
                        'full_payment' => 'Полный расчет',
                        'partial_payment' => 'Частичный расчет и кредит',
                        'credit' => 'Передача в кредит',
                        'credit_payment' => 'Оплата кредита'
                    ),
                    'default' => 'full_prepayment'
                ),
                'vat' =>  array(
                    'title' => __('Ставка НДС', 'modulbank'),
                    'type' => 'select',
                    'options' => array(
                        'none' => 'Без НДС',
                        'vat0' => 'НДС по ставке 0%',
                        'vat10' => 'НДС чека по ставке 10%',
                        'vat18' => 'НДС чека по ставке 18%',
                        'vat20' => 'НДС чека по ставке 20%',
                        'vat110' => 'НДС чека по расчетной ставке 10% ',
                        'vat118' => 'НДС чека по расчетной ставке 18% ',
                        'vat120' => 'НДС чека по расчетной ставке 20% ',
                    ),
                    'default' => 'full_prepayment'
                )

#                'title' => array(
#                    'title' => __('Заголовок', 'modulbank'),
#                    'type' => 'text',
#                    'description' => __('Название, которое пользователь видит во время оплаты', 'modulbank'),
#                    'default' => "Оплатить банковской картой через Модульбанк",
#                ),
#                'description' => array(
#                    'title' => __('Описание', 'modulbank'),
#                    'type' => 'textarea',
#                    'description' => __('Описание, которое пользователь видит во время оплаты', 'modulbank'),
#                    'default' => '',
#                ),
            );
        }

        /**
         *  There are no payment fields, but we want to show the description if set.
         */
        public function payment_fields() {
            if ($this->description) {
                echo wpautop(wptexturize($this->description));
            }
        }

        public function get_fpayments_form() {

            return new FPaymentsForm(
                $this->settings['merchant_id'],
                $this->settings['secret_key'],
                $this->settings['test_mode'] == 'yes',
                '',
                'WordPress ' . get_bloginfo('version')
            );
        }


        public function process_payment( $order_id ) {
            return array(
                'result'    => 'success',
                'redirect'  => get_home_url() . '/?modulbank=submit&order_id='. $order_id,
            );
        }

        function get_current_url() {
            return add_query_arg( $_SERVER['QUERY_STRING'], '', get_home_url($_SERVER['REQUEST_URI']) . '/');
        }
    }

    function add_modulbank_gateway_class( $methods ) {
        $methods[] = 'WC_Gateway_Modulbank';
        return $methods;
    }

    add_filter( 'woocommerce_payment_gateways', 'add_modulbank_gateway_class' );

    add_action('parse_request', 'parse_modulbank_request');

    function parse_modulbank_request() {
        if (array_key_exists('modulbank', $_GET)) {
            $gw = new WC_Gateway_Modulbank();
            if ($_GET['modulbank'] == 'callback') {
                $callback_handler = new ModulbankCallback($gw);
                $callback_handler->show($_POST);
            } elseif ($_GET['modulbank'] == 'submit') {

                $order_id = $_GET['order_id'];

                $order = wc_get_order($order_id);
                $ff = $gw->get_fpayments_form();
                $meta = '';
                $description = '';

                $sno = $gw->settings['sno'];
                $payment_object = $gw->settings['payment_object'];
                $payment_method = $gw->settings['payment_method'];
                $vat = $gw->settings['vat'];

                $receipt_contact = $order->get_billing_email() ?: $order->get_billing_phone() ?: '';
                $receipt_items = array();
                $order_items = $order->get_items();
                foreach( $order_items as $product ) {
                    $receipt_items[] = new FPaymentsRecieptItem(
                        $product->get_name(),
                        $product->get_total() / $product->get_quantity(),
                        $product->get_quantity(),
                        $vat,
                        $sno,
                        $payment_object,
                        $payment_method
                    );

                }

                // Iterating through order fee items ONLY
                foreach( $order->get_items('fee') as $item_id => $item_fee ){

                    // The fee name
                    $fee_name = $item_fee->get_name();
                    // The fee total amount
                    $fee_total = $item_fee->get_total();
                    // The fee total tax amount
                    $fee_total_tax = $item_fee->get_total_tax();

                    $receipt_items[] = new FPaymentsRecieptItem(
                        $fee_name,
                        $fee_total,
                        1,
                        $vat,
                        $sno,
                        'service',
                        $payment_method
                    );
                }


                $shipping_total = $order->get_shipping_total();
                if ($shipping_total) {
                    $receipt_items[] = new FPaymentsRecieptItem(
                        'Доставка',
                        $shipping_total,
                        1,
                        $vat,
                        $sno,
                        'service',
                        $payment_method);
                }

                $data = $ff->compose(
                    $order->get_total(),
                    $order->get_currency(),
                    $order->get_id(),
                    $order->get_billing_email(),
                    '',  # name
                    $order->get_billing_phone(),
                    $gw->get_success_url($order),
                    $gw->settings['fail_url'] ?: $gw->fail_url,
                    '',
                    $gw->get_callback_url(),
                    $meta,
                    $description,
                    $receipt_contact,
                    $receipt_items
                );

                try {
                    wc_reduce_stock_levels($order_id);
                } catch (Exception $e)
                {

                }

                $templates_dir = dirname(__FILE__) . '/templates/';
                include $templates_dir . 'submit.php';
            } else {
                echo("wrong action");
            }
            die();
        }
    }
}

add_action( 'plugins_loaded', 'init_modulbank');
