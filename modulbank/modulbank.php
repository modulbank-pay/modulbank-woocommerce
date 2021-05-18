<?php
/*
   Plugin Name: Оплата через Модульбанк
   Description: Платежный модуль WooCommerce для приема платежей с помощью Модульбанка.
   Version: 2.2
*/

function init_modulbank() {
    if ( ! class_exists( 'WC_Payment_Gateway' ) ) {
        return;
    }
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
            return version_compare(WOOCOMMERCE_VERSION, '3.0', '>=')?$order->get_currency():get_woocommerce_currency();
        }
        protected function get_order_amount($order) {
            $order_total = version_compare(WOOCOMMERCE_VERSION, '3.0', '>=')?$order->get_total():number_format($order->order_total, 2, '.', '');
            return $order_total;
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

            $this->title = $this->settings['title'];
            $this->description = $this->settings['description'];

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
                'title' => array(
                    'title' => __('Заголовок', 'modulbank'),
                    'type' => 'text',
                    'description' => __('Название, которое пользователь видит во время оплаты', 'modulbank'),
                    'default' => "Оплатить банковской картой через Модульбанк",
                ),
                'description' => array(
                    'title' => __('Описание', 'modulbank'),
                    'type' => 'textarea',
                    'description' => __('Описание, которое пользователь видит во время оплаты', 'modulbank'),
                    'default' => '',
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

                'preauth' => array(
                    'title' => __('Предавторизация', 'modulbank'),
                    'type' => 'checkbox',
                    'label' => __('Предавторизация', 'modulbank'),
                    'default' => 'no',
                    'description' => __('',
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
                ),

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

    function modulbank_insert_transaction($data)
    {
        global $wpdb;
        $wpdb->query("INSERT INTO {$wpdb->prefix}modulbankpayment (transaction_id, rrn, auth_number, amount, original_amount, created_datetime, auth_code, state, order_id, pan_mask, message) values (
                '" . esc_sql($data['transaction_id']) . "',
                '" . esc_sql($data['rrn']) . "',
                '" . esc_sql($data['auth_number']) . "',
                '" . esc_sql($data['amount']) . "',
                '" . esc_sql($data['original_amount']) . "',
                '" . esc_sql($data['created_datetime']) . "',
                '" . esc_sql($data['auth_code']) . "',
                '" . esc_sql($data['state']) . "',
                '" . esc_sql($data['order_id']) . "',
                '" . esc_sql($data['pan_mask']) . "',
                '" . esc_sql($data['message']) . "'
            )
            ON DUPLICATE KEY UPDATE
            rrn = values(rrn),
            auth_number = values(auth_number),
            amount = values(amount),
            original_amount = values(original_amount),
            created_datetime = values(created_datetime),
            auth_code = values(auth_code),
            state = values(state),
            message = values(message),
            pan_mask = values(pan_mask)
            ");

    }

    function parse_modulbank_request() {
        global $woocommerce;
        if (array_key_exists('modulbank', $_GET)) {
            $gw = new WC_Gateway_Modulbank();
            if ($_GET['modulbank'] == 'callback') {
                modulbank_insert_transaction($_POST);
                $callback_handler = new ModulbankCallback($gw);
                $callback_handler->show($_POST);
            } elseif ($_GET['modulbank'] == 'submit') {

                $order_id = $_GET['order_id'];

                $order = wc_get_order($order_id);
                $ff = $gw->get_fpayments_form();
                $meta = '';
                $description = '';

                if (version_compare($woocommerce->version, "3.0", ">=")) {
                    $billing_email = $order->get_billing_email();
                    $billing_phone = $order->get_billing_phone();
                    $order_total = $order->get_total();
                    $currency = $order->get_currency();
                } else {
                    $billing_email = $order->billing_email;

                    $billing_phone = $order->billing_phone;
                    $order_total = number_format($order->order_total, 2, '.', '');
                    $currency = get_woocommerce_currency();
                }

                $billing_phone = modulbank_normalize_phone($billing_phone);

                $receipt_contact = modulbank_get_receipt_contact($order);

                $receipt_items = modulbank_get_receipt_items($order, $gw);


                $data = $ff->compose(
                    $order_total,
                    $currency,
                    $order_id,
                    $billing_email,
                    '',  # name
                    $billing_phone,
                    $gw->get_success_url($order),
                    $gw->settings['fail_url'] ?: $gw->fail_url,
                    '',
                    $gw->get_callback_url(),
                    $meta,
                    $description,
                    $receipt_contact,
                    $receipt_items,
                    '',
                    '',
                    $gw->settings['preauth'] == 'yes'?'1':'0'
                );

                try {
                    if (function_exists("wc_reduce_stock_levels")){
                        wc_reduce_stock_levels($order_id);
                    } else {
                        $order->reduce_order_stock();
                    }

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

    function modulbank_get_receipt_contact($order) {
        global $woocommerce;
        if (version_compare($woocommerce->version, "3.0", ">=")) {
            $billing_email = $order->get_billing_email();
            $billing_phone = $order->get_billing_phone();
        } else {
            $billing_email = $order->billing_email;
            $billing_phone = $order->billing_phone;
        }

        $billing_phone = modulbank_normalize_phone($billing_phone);

        $receipt_contact = $billing_email ?: $billing_phone ?: '';
        return $receipt_contact;
    }

    function modulbank_get_receipt_items($order, $gw) {
        global $woocommerce;
        $sno = $gw->settings['sno'];
        $payment_object = $gw->settings['payment_object'];
        $payment_method = $gw->settings['payment_method'];
        $vat = $gw->settings['vat'];

        if (version_compare($woocommerce->version, "3.0", ">=")) {
            $shipping_total = $order->get_shipping_total();
        } else {
            $shipping_total = $order->get_total_shipping();
        }

        $receipt_items = array();
        $order_items = $order->get_items();

        if (version_compare($woocommerce->version, "3.0", ">=")) {
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
        } else {

            foreach( $order_items as $product ) {
                $receipt_items[] = new FPaymentsRecieptItem(
                    $product['name'],
                    $item_line_total  = $order->get_item_subtotal( $product, false ),
                    $product['qty'],
                    $vat,
                    $sno,
                    $payment_object,
                    $payment_method
                );

            }
            foreach( $order->get_items('fee') as $item_id => $item_fee ){

                // The fee name
                $fee_name = $item_fee['name'];
                // The fee total amount
                $fee_total = $item_fee['line_total'];
                // The fee total tax amount

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

        }



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
        return $receipt_items;
    }

    function modulbank_normalize_phone($phone)
    {
        $phone = preg_replace('/[^0-9]/', '', $phone);
        if (preg_match('/^[87]{0,1}9(\d{9})$/', $phone, $m)) {
            $phone = '+79' . $m[1];
        }
        if (!preg_match('/^\+79\d{9}$/', $phone)) {
            $phone = '';
        }
        return $phone;
    }
}

add_action( 'plugins_loaded', 'init_modulbank');

function install_modulbankpayment()
{
    global $wpdb;

    $table_name = $wpdb->prefix . 'modulbankpayment';

    $charset_collate = $wpdb->get_charset_collate();

    $sql = "CREATE TABLE $table_name
(
    `transaction_id` varchar(32)  NOT NULL,
    `order_id` int(11) NOT NULL,
    `state` varchar(20)  NOT NULL,
    `created_datetime` datetime NOT NULL,
    `rrn` varchar(12) NOT NULL,
    `amount` varchar(10) NOT NULL,
    `original_amount` varchar(10) NOT NULL,
    `auth_code` varchar(5) NOT NULL,
    `auth_number` varchar(10) NOT NULL,
    `pan_mask` varchar(16) NOT NULL,
    `message` varchar(255) NOT NULL,
    PRIMARY KEY (transaction_id)
) $charset_collate;
";

    require_once ABSPATH . 'wp-admin/includes/upgrade.php';
    dbDelta($sql);
}

register_activation_hook(__FILE__, 'install_modulbankpayment');


add_action('admin_menu', 'modulbankpayment_menu');

function modulbankpayment_menu()
{
    $icon = 'data:image/svg+xml;base64,' . base64_encode(file_get_contents(dirname(__FILE__) . '/assets/images/icons/menu.svg'));
    add_menu_page('Модульбанк: транзакции', 'Модульбанк: транзакции', 'manage_woocommerce', 'modulbankpayment', "modulbankpayment_page_handler", $icon, '55.6');
}

function modulbankpayment_page_handler()
{
    modulbankpayment_transactions();
}

function modulbankpayment_return_payment() {
    return modulbankpayment_cancel_payment();
}

function modulbankpayment_confirm_payment() {
    global $wpdb;
    $data   = $wpdb->get_row("SELECT * FROM {$wpdb->prefix}modulbankpayment WHERE transaction_id='" . esc_sql($_POST['transaction_id'])."'");

    $gw = new WC_Gateway_Modulbank();
    $order_id = $data->order_id;
    $order = wc_get_order($order_id);
    $ff = $gw->get_fpayments_form();
    $receipt_items = modulbank_get_receipt_items($order, $gw);
    $receipt_contact = modulbank_get_receipt_contact($order);
    if($ff->confirm_payment($data->transaction_id, $_POST['sum'],$receipt_contact, $receipt_items)) {
        $wpdb->query("UPDATE {$wpdb->prefix}modulbankpayment set state='COMPLETE',amount='".esc_sql($_POST['sum'])."' where transaction_id='" . esc_sql($data->transaction_id) . "'");
    }
    return true;
}

function modulbankpayment_cancel_payment() {
    global $wpdb;
    $data   = $wpdb->get_row("SELECT * FROM {$wpdb->prefix}modulbankpayment WHERE transaction_id='" . esc_sql($_POST['transaction_id'])."'");

    $gw = new WC_Gateway_Modulbank();
    $order_id = $data->order_id;
    $order = wc_get_order($order_id);
    $ff = $gw->get_fpayments_form();
    if($ff->refund_payment($data->transaction_id, $_POST['sum'])) {
        $wpdb->query("UPDATE {$wpdb->prefix}modulbankpayment set state='CANCELED',amount='".esc_sql($_POST['sum'])."' where transaction_id='" . esc_sql($data->transaction_id) . "'");
    }
    return true;
}

function modulbankpayment_transactions() {
    global $wpdb;
    if (!current_user_can('manage_woocommerce')) {
        wp_die(__('You do not have sufficient permissions to access this page.'));
    }
    $message = '';
    $config  = get_option('woocommerce_modulbankpayment_settings', null);
    if (isset($_POST['action'])) {
        try {
            switch ($_POST['action']) {
                case 'return':modulbankpayment_return_payment();
                    break;
                case 'cancel':modulbankpayment_cancel_payment();
                    break;
                case 'confirm':modulbankpayment_confirm_payment();
                    break;
            }
        } catch (Exception $e) {
            $message = $e->getMessage();
        }
    }

    $paged   = isset($_GET['paged']) ? intval($_GET['paged']) : 1;
    $perpage = 25;
    $from    = ($paged - 1) * $perpage;
    $where   = 'WHERE 1 = 1';
    if ((isset($_POST['filter_order']) && $_POST['filter_order'])) {
        $where = array();
        if ($_POST['filter_order']) {
            $where[] = 'order_id=' . intval($_POST['filter_order']);
        }
        $where = 'WHERE ' . implode(' and ', $where);
    }
    $transactions    = $wpdb->get_results("SELECT * FROM {$wpdb->prefix}modulbankpayment $where order by order_id DESC limit $from,$perpage");
    $count           = $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}modulbankpayment $where;");
    $total_items     = $count;
    $total_pages     = ceil($total_items / $perpage);
    $infinite_scroll = false;
    echo "<div class='wrap'>";
    if ($message) {
        echo '<div id="message" class="notice notice-info"><p>' . $message . '</p></div>';
    }
    echo "<h1>Платежные транзакции</h1>";
    if (!$count) {
        echo "- нет данных -";
        echo "</div>";
        return;
    }

    $output = '<span class="displaying-num">' . sprintf(_n('%s item', '%s items', $total_items), number_format_i18n($total_items)) . '</span>';

    $current = $paged;

    $removable_query_args = wp_removable_query_args();

    $current_url = set_url_scheme('http://' . $_SERVER['HTTP_HOST'] . $_SERVER['REQUEST_URI']);

    $current_url = remove_query_arg($removable_query_args, $current_url);

    $page_links = array();

    $total_pages_before = '<span class="paging-input">';
    $total_pages_after  = '</span></span>';

    $disable_first = $disable_last = $disable_prev = $disable_next = false;

    if ($current == 1) {
        $disable_first = true;
        $disable_prev  = true;
    }
    if ($current == 2) {
        $disable_first = true;
    }
    if ($current == $total_pages) {
        $disable_last = true;
        $disable_next = true;
    }
    if ($current == $total_pages - 1) {
        $disable_last = true;
    }

    if ($disable_first) {
        $page_links[] = '<span class="tablenav-pages-navspan" aria-hidden="true">&laquo;</span>';
    } else {
        $page_links[] = sprintf("<a class='first-page' href='%s'><span class='screen-reader-text'>%s</span><span aria-hidden='true'>%s</span></a>",
            esc_url(remove_query_arg('paged', $current_url)),
            __('First page'),
            '&laquo;'
        );
    }

    if ($disable_prev) {
        $page_links[] = '<span class="tablenav-pages-navspan" aria-hidden="true">&lsaquo;</span>';
    } else {
        $page_links[] = sprintf("<a class='prev-page' href='%s'><span class='screen-reader-text'>%s</span><span aria-hidden='true'>%s</span></a>",
            esc_url(add_query_arg('paged', max(1, $current - 1), $current_url)),
            __('Previous page'),
            '&lsaquo;'
        );
    }

    if ('bottom' === $which) {
        $html_current_page  = $current;
        $total_pages_before = '<span class="screen-reader-text">' . __('Current Page') . '</span><span id="table-paging" class="paging-input"><span class="tablenav-paging-text">';
    } else {
        $html_current_page = sprintf("%s<input class='current-page' id='current-page-selector' type='text' name='paged' value='%s' size='%d' aria-describedby='table-paging' /><span class='tablenav-paging-text'>",
            '<label for="current-page-selector" class="screen-reader-text">' . __('Current Page') . '</label>',
            $current,
            strlen($total_pages)
        );
    }
    $html_total_pages = sprintf("<span class='total-pages'>%s</span>", number_format_i18n($total_pages));
    $page_links[]     = $total_pages_before . sprintf(_x('%1$s of %2$s', 'paging'), $html_current_page, $html_total_pages) . $total_pages_after;

    if ($disable_next) {
        $page_links[] = '<span class="tablenav-pages-navspan" aria-hidden="true">&rsaquo;</span>';
    } else {
        $page_links[] = sprintf("<a class='next-page' href='%s'><span class='screen-reader-text'>%s</span><span aria-hidden='true'>%s</span></a>",
            esc_url(add_query_arg('paged', min($total_pages, $current + 1), $current_url)),
            __('Next page'),
            '&rsaquo;'
        );
    }

    if ($disable_last) {
        $page_links[] = '<span class="tablenav-pages-navspan" aria-hidden="true">&raquo;</span>';
    } else {
        $page_links[] = sprintf("<a class='last-page' href='%s'><span class='screen-reader-text'>%s</span><span aria-hidden='true'>%s</span></a>",
            esc_url(add_query_arg('paged', $total_pages, $current_url)),
            __('Last page'),
            '&raquo;'
        );
    }

    $pagination_links_class = 'pagination-links';
    if (!empty($infinite_scroll)) {
        $pagination_links_class = ' hide-if-js';
    }
    $output .= "\n<span class='$pagination_links_class'>" . join("\n", $page_links) . '</span>';

    if ($total_pages) {
        $page_class = $total_pages < 2 ? ' one-page' : '';
    } else {
        $page_class = ' no-pages';
    }
    echo '<div class="tablenav top">';
    ?>
<form method="POST" action="">
<div class="alignleft actions bulkactions">
    <label class="screen-reader-text" for="search-transaction">Номер заказа:</label>
    <input placeholder="Номер заказа" type="search" id="search-transaction" class="wp-filter-search" name="filter_order" value="<?php echo htmlspecialchars(@$_POST['filter_order'], ENT_QUOTES, 'UTF-8'); ?>" />
    <?php submit_button('Фильтр', '', '', false, array('id' => 'search-submit'));?>
</div>
</form>
<?php
echo "<div class='tablenav-pages{$page_class}'>$output</div>";
    echo "</div>";
    $history_link = add_query_arg('section', 'history', $current_url);
    ?>

<table class="wp-list-table widefat striped posts">
<thead>
    <tr>
        <th>Id платежа</th>
        <th>Id заказа</th>
        <th>Сумма заказа</th>
        <th>Дата транзакции</th>
        <th>Статус</th>
        <th>Действие</th>
    </tr>
    </thead>
    <tbody>
    <?php foreach ($transactions as $data):
        $amount = number_format(round($data->amount, 2), 2, '.', '');
        ?>
        <tr class="transactionrow" >
            <td><?php echo $data->transaction_id ?></td>
            <td><?php echo $data->order_id ?></td>
            <td><?php echo $amount ?></td>
            <td><?php echo $data->created_datetime ?></td>
            <td><?php
    $status = modulbankpayment_get_status_name($data->state);
        echo $status;
        ?>
        </td>
            <td><?php
    $currentStatus = $data->state;
        $action = '';
        if (in_array($currentStatus, array("COMPLETE", "AUTHORIZED")) && $data->amount > 0) {
            $action = '<form method="POST" action="">
                <input type="text" name="sum" value="' . $amount . '" style="width:100%" size="9">
                <input type="hidden" name="transaction_id" value="' . $data->transaction_id . '">
                <br>';
            $action .= "<div style='white-space:nowrap'>";
            if (in_array($currentStatus, array("COMPLETE"))) {
                $action .= '
                    <button class="button" type="submit" name="action" value="return">Возврат</button>';
            }
            if (in_array($currentStatus, array("AUTHORIZED"))) {
                $action .= '
                    <button class="button" type="submit" name="action" value="cancel">Отмена</button>';
                $action .= '
                    <button class="button" type="submit" name="action" value="confirm">Завершение</button>';
            }
            $action .= "</div>";
            $action .= '</form>';
        }
        echo $action;
        ?>
        </td>
        </tr>
    <?php endforeach;?>
    </tbody>
</table>
</div>
<?php
}

function modulbankpayment_get_status_name($status)
{
    switch ($status) {
        case 'COMPLETE':$name = 'Оплачен';
            break;
        case 'AUTHORIZED':$name = 'Сумма заблокирована';
            break;
        case 'PROCESSING':$name = 'В процессе';
            break;
        case 'CANCELED':$name = 'Отменен';
            break;
        case 'FAILED':$name = 'Платёж неуспешен: Недостаточно средств у плательщика';
            break;
    }
    return $name;
}