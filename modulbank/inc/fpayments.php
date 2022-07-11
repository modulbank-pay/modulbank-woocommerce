<?php
if (!function_exists('mb_str_split')) {
    function mb_str_split($string, $split_length = 1, $encoding = null) {
        if (is_null($encoding)) {
            $encoding = mb_internal_encoding();
        }

        if ($split_length < 1) {
            return false;
        }

        $return_value = array();
        $string_length  = mb_strlen($string, $encoding);
        for ($i = 0; $i < $string_length; $i += $split_length)
        {
            $return_value[] = mb_substr($string, $i, $split_length, $encoding);
        }
        return $return_value;
    }
}

if (!function_exists('stripslashes_gpc')) {
    function stripslashes_gpc(&$value) {
        $value = stripslashes($value);
    }
}

require_once "fpayments_config.php";


class FPaymentsError extends Exception {}


class FPaymentsForm {
    private $merchant_id;
    private $secret_key;
    private $is_test;
    private $plugininfo;
    private $cmsinfo;

    function __construct(
        $merchant_id,
        $secret_key,
        $is_test,
        $plugininfo = '',
        $cmsinfo = ''
    ) {
        $this->merchant_id = $merchant_id;
        $this->secret_key = $secret_key;
        $this->is_test = (bool) $is_test;
        $this->plugininfo = $plugininfo ?: 'FPayments/PHP v.' . phpversion();
        $this->cmsinfo = $cmsinfo;
    }

    public static function abs($path) {
        return FPaymentsConfig::HOST . $path;
    }

    function get_url() {
        return self::abs('/pay/');
    }

    function get_rebill_url() {
        return self::abs('/api/v1/rebill/');
    }

    function get_capture_url() {
        return self::abs('/api/v1/capture');
    }

    function get_refund_url() {
        return self::abs('/api/v1/refund');
    }

    function compose(
        $amount,
        $currency,
        $order_id,
        $client_email,
        $client_name,
        $client_phone,
        $success_url,
        $fail_url,
        $cancel_url,
        $callback_url,
        $meta = '',
        $description = '',
        $receipt_contact = '',
        array $receipt_items = null,
        $recurring_frequency = '',
        $recurring_finish_date = '',
        $preauth = 0,
        $payment_methods = []
    ) {
        if (!$description) {
            $description = "Заказ №$order_id";
        }
        $form = array(
            'testing'               => (int) $this->is_test,
            'merchant'              => $this->merchant_id,
            'unix_timestamp'        => time(),
            'salt'                  => $this->get_salt(32),
            'amount'                => $amount,
            'currency'              => $currency,
            'description'           => $description,
            'order_id'              => $order_id,
            'client_email'          => $client_email,
            'client_name'           => $client_name,
            'client_phone'          => $client_phone,
            'success_url'           => $success_url,
            'fail_url'              => $fail_url,
            'cancel_url'            => $cancel_url,
            'callback_url'          => $callback_url,
            'preauth'               => $preauth,
            'meta'                  => $meta,
            'sysinfo'               => $this->get_sysinfo(),
            'recurring_frequency'   => $recurring_frequency,
            'recurring_finish_date' => $recurring_finish_date,
        );
        if (!empty($payment_methods)) {
            $form['show_payment_methods'] = json_encode(array_values($payment_methods));
        }
        if ($receipt_items) {
            $receipt = new FPaymentsReciept($amount);
            foreach($receipt_items as $item) {
                $receipt->addItem($item);
            }
            $form['receipt_contact'] = $receipt_contact;
            $form['receipt_items'] = $receipt->getJson();
        };
        $form['signature'] = $this->get_signature($form);

        logModulbankMessage('payment: '.$order_id.' '. var_export($form, true));

        return $form;
    }

    function refund_payment(
        $transaction_id,
        $amount
    ) {
        $url  = $this->get_refund_url();
        $data = [
            'transaction'    => $transaction_id,
            'amount'         => $amount,
            'merchant'       => $this->merchant_id,
            'salt'           => $this->get_salt(32),
            'unix_timestamp' => time(),
        ];
        $data['signature'] = $this->get_signature($data);
        $response = $this->send_request('POST', $url, $data);
        $response = json_decode($response);
        if (!$response) {
            throw new FPaymentsError('Empty response');
        }
        if ($response->status !== 'ok') {
            throw new FPaymentsError($response->message);
        }
        return true;
    }


    function confirm_payment(
        $transaction_id,
        $amount,
        $receipt_contact = '',
        array $receipt_items = null
    ) {
        $url  = $this->get_capture_url();
        $data = [
            'transaction'    => $transaction_id,
            'amount'         => $amount,
            'merchant'       => $this->merchant_id,
            'salt'           => $this->get_salt(32),
            'unix_timestamp' => time(),
        ];
        if ($receipt_items) {
            if (!$receipt_contact) {
                throw new FPaymentsError('receipt_contact required');
            }
            $receipt = new FPaymentsReciept($amount);
            foreach($receipt_items as $item) {
                $receipt->addItem($item);
            }
            $data['receipt_contact'] = $receipt_contact;
            $data['receipt_items'] = $receipt->getJson();
        }
        $data['signature'] = $this->get_signature($data);
        $response = $this->send_request('POST', $url, $data);
        $response = json_decode($response);
        if (!$response) {
            throw new FPaymentsError('Empty response');
        }
        if ($response->status !== 'ok') {
            throw new FPaymentsError($response->message);
        }
        return true;
    }

    private function get_sysinfo() {
        return json_encode(array(
            'json_enabled' => true,
            'language' => 'PHP ' . phpversion(),
            'plugin' => $this->plugininfo,
            'cms' => $this->cmsinfo,
        ));
    }

    function is_signature_correct(array $form) {
        if (!array_key_exists('signature', $form)) {
            return false;
        }
        return $this->get_signature($form) == $form['signature'];
    }

    function is_order_completed(array $form) {
        $is_testing_transaction = ($form['testing'] === '1');
        return ($form['state'] == 'COMPLETE' || $form['state'] == 'AUTHORIZED') && ($is_testing_transaction == $this->is_test);
    }

    public static function array_to_hidden_fields(array $form) {
        $result = '';
        foreach ($form as $k => $v) {
            $result .= '<input name="' . htmlspecialchars($k) . '" value="' . htmlspecialchars($v) . '" type="hidden">';
        }
        return $result;
    }

    function get_signature(array $params, $key = 'signature') {
        $keys = array_keys($params);
        sort($keys);
        $chunks = array();
        foreach ($keys as $k) {
            $v = (string) $params[$k];
            if (($v !== '') && ($k != 'signature')) {
                $chunks[] = $k . '=' . base64_encode($v);
            }
        }
        $data = implode('&', $chunks);

        $sig = $this->double_sha1($data);
        return $sig;
    }

    private function double_sha1($data) {
        for ($i = 0; $i < 2; $i++) {
            $data = sha1($this->secret_key . $data);
        }
        return $data;
    }

    private function get_salt($length = 10) {
        $characters = '0123456789abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ';
        $result = '';
        for ($i = 0; $i < $length; $i++) {
            $result .= $characters[rand(0, strlen($characters) - 1)];
        }
        return $result;
    }

    function rebill(
        $amount,
        $currency,
        $order_id,
        $recurrind_tx_id,
        $recurring_token,
        $description = ''
    ) {
        if (!$description) {
            $description = "Заказ №$order_id";
        }
        $form = array(
            'testing'               => (int) $this->is_test,
            'merchant'              => $this->merchant_id,
            'unix_timestamp'        => time(),
            'salt'                  => $this->get_salt(32),
            'amount'                => $amount,
            'currency'              => $currency,
            'description'           => $description,
            'order_id'              => $order_id,
            'initial_transaction'   => $recurrind_tx_id,
            'recurring_token'       => $recurring_token,
        );
        $form['signature'] = $this->get_signature($form);
        $result = $this->send_request('POST', $this->get_rebill_url(), $form);
        return json_decode($result);
    }

    function send_request($method, $url, $data) {
        if ($method == 'GET') {
            $url .= "?".http_build_query($data);
        }
        $response = false;
        if (function_exists("curl_init")) {
            $ch = curl_init();
            curl_setopt($ch, CURLOPT_URL, $url);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
            curl_setopt($ch, CURLOPT_VERBOSE, 0);
            curl_setopt($ch, CURLOPT_USERAGENT, $this->plugininfo);
            if ($method == 'POST') {
                curl_setopt($ch, CURLOPT_POST, 1);
                curl_setopt($ch, CURLOPT_POSTFIELDS, $data);
            }
            $response = curl_exec($ch);
            curl_close($ch);
        }
        return $response;
    }
}


abstract class AbstractFPaymentsCallbackHandler {
    /**
    * @return FPaymentsForm
    */
    abstract protected function get_fpayments_form();
    abstract protected function load_order($order_id);
    abstract protected function get_order_currency($order);
    abstract protected function get_order_amount($order);
    /**
    * @return bool
    */
    abstract protected function is_order_completed($order);
    /**
    * @return bool
    */
    abstract protected function mark_order_as_completed($order, array $data);
    /**
    * @return bool
    */
    abstract protected function mark_order_as_error($order, array $data);

    function show(array $data) {
        if (function_exists("get_magic_quotes_gpc") && get_magic_quotes_gpc()) {
           array_walk_recursive($data, 'stripslashes_gpc');
        }
        $error = null;
        $debug_messages = array();
        $ff = $this->get_fpayments_form();

        logModulbankMessage('callback for: '. var_export($data, true));

        if (!$ff->is_signature_correct($data)) {
            $error = 'Incorrect "signature"';
        } else if (!($order_id = (int) $data['order_id'])) {
            $error = 'Empty "order_id"';
        } else if (!($order = $this->load_order($order_id))) {
            $error = 'Unknown order_id';
        } else if ($this->get_order_currency($order) != $data['currency']) {
            $error = 'Currency mismatch: "' . $this->get_order_currency($order) . '" != "' . $data['currency'] . '"';
        } else if ($this->get_order_amount($order) != $data['amount']) {
            $error = 'Amount mismatch: "' . $this->get_order_amount($order) . '" != "' . $data['amount'] . '"';
        } else if ($ff->is_order_completed($data)) {
            $debug_messages[] = "info: order completed";
            if ($this->is_order_completed($order)) {
                $debug_messages[] = "order already marked as completed";
            } else if ($this->mark_order_as_completed($order, $data)) {
                $debug_messages[] = "mark order as completed";
            } else {
                $error = "Can't mark order as completed";
            }
        } else {
            $debug_messages[] = "info: order not completed";
            if (!$this->is_order_completed($order)) {
                if ($this->mark_order_as_error($order, $data)) {
                    $debug_messages[] = "mark order as error";
                } else {
                    $error = "Can't mark order as error";
                }
            }
        }


        logModulbankMessage('callback: '. $error);
        logModulbankMessage('callback: orderId: '. $order_id);

        if ($error) {
            echo "ERROR: $error\n";
        } else {
            echo "OK$order_id\n";
        }
        foreach ($debug_messages as $msg) {
            echo "...$msg\n";
            logModulbankMessage('... '. $msg);
        }
    }
}

class FPaymentsReciept {

    private $items = [];
    private $current_total = 0;
    private $final_total = 0;

    function __construct($total) {
        $this->final_total = $total;
    }

    public function addItem($item) {
        if ($item->get_sum() <= 0 ){
            return false;
        }
        $this->items[] = $item->as_dict();
        $this->current_total += $item->get_sum();
    }

    public function getJson() {
        $this->normalize();
        return json_encode($this->items, JSON_UNESCAPED_UNICODE);
    }

    public function normalize() {
        if ($this->final_total != 0 && $this->final_total != $this->current_total) {
            $coefficient = $this->final_total / $this->current_total;
            $realprice   = 0;
            $aloneId     = null;
            foreach ($this->items as $index => &$item) {
                $item['price'] = round($coefficient * $item['price'], 2);
                $realprice += round($item['price'] * $item['quantity'], 2);
                if ($aloneId === null && $item['quantity'] === 1) {
                    $aloneId = $index;
                }

            }
            unset($item);
            if ($aloneId === null) {
                foreach ($this->items as $index => $item) {
                    if ($aloneId === null && $item['quantity'] > 1) {
                        $aloneId = $index;
                        break;
                    }
                }
            }
            if ($aloneId === null) {
                $aloneId = 0;
            }

            $diff = $this->final_total - $realprice;

            if (abs($diff) >= 0.001) {
                if ($this->items[$aloneId]['quantity'] === 1) {
                    $this->items[$aloneId]['price'] = round($this->items[$aloneId]['price'] + $diff, 2);
                } elseif (
                    count($this->items) == 1
                    && abs(round($this->final_total / $this->items[$aloneId]['quantity'], 2) - $this->final_total / $this->items[$aloneId]['quantity']) < 0.001
                ) {
                    $this->items[$aloneId]['price'] = round($this->final_total / $this->items[$aloneId]['quantity'], 2);
                } elseif ($this->items[$aloneId]['quantity'] > 1) {
                    $tmpItem = $this->items[$aloneId];
                    $item    = array(
                        "quantity"       => 1,
                        "price"          => round($tmpItem['price'] + $diff, 2),
                        "name"           => $tmpItem['name'],
                        "sno"            => $tmpItem['sno'],
                        "payment_object" => $tmpItem['payment_object'],
                        "payment_method" => $tmpItem['payment_method'],
                        "vat"            => $tmpItem['vat'],
                    );
                    $this->items[$aloneId]['quantity'] -= 1;
                    array_splice($this->items, $aloneId + 1, 0, array($item));
                } else {
                    $this->items[$aloneId]['price'] = round($this->items[$aloneId]['price'] + $diff / ($this->items[$aloneId]['quantity'] ), 2);

                }
            }
        }
    }
}


class FPaymentsRecieptItem {
    const TAX_NO_NDS = 'none';  # без НДС;
    const TAX_0_NDS = 'vat0';  # НДС по ставке 0%;
    const TAX_10_NDS = 'vat10';  # НДС чека по ставке 10%;
    const TAX_18_NDS = 'vat18';  # НДС чека по ставке 18%
    const TAX_20_NDS = 'vat20';  # НДС чека по ставке 20%
    const TAX_10_110_NDS = 'vat110';  # НДС чека по расчетной ставке 10/110;
    const TAX_18_118_NDS = 'vat118';  # НДС чека по расчетной ставке 18/118.
    const TAX_20_120_NDS = 'vat120';  # НДС чека по расчетной ставке 20/120.

    private $title;
    private $amount;
    private $n;
    private $nds;
    private $sno;
    private $payment_object;
    private $payment_method;


    function __construct($title, $amount, $n = 1, $nds = null, $sno=null, $payment_object=null, $payment_method=null) {
        $this->title = self::clean_title($title);
        $this->amount = $amount;
        $this->n = $n;
        $this->nds = $nds ? $nds : self::TAX_0_NDS;
        $this->sno = $sno;
        $this->payment_object = $payment_object;
        $this->payment_method = $payment_method;
    }

    function as_dict() {
        return array(
            'quantity' => $this->n,
            'price' =>  round($this->amount, 2),
            'name' => $this->title,
            'sno' => $this->sno,
            'payment_object' => $this->payment_object,
            'payment_method' => $this->payment_method,
            'vat' => $this->nds
        );
    }

    function get_sum() {
        return $this->n * round($this->amount, 2);
    }

    private static function clean_title($s, $max_chars=64) {
        $result = '';
        $arr = mb_str_split($s);
        $allowed_chars = mb_str_split('0123456789"(),.:;- йцукенгшщзхъфывапролджэёячсмитьбюqwertyuiopasdfghjklzxcvbnm');
        foreach ($arr as $char) {
            if (mb_strlen($result) >= $max_chars) {
                break;
            }
            if (in_array(mb_strtolower($char), $allowed_chars)) {
                $result .= $char;
            }
        }
        return $result;
    }
}


function logModulbank($paymentData, $request)
{
    logModulbankMessage(json_encode($paymentData, JSON_UNESCAPED_UNICODE));
    logModulbankMessage($request);
}

function logModulbankMessage($message)
{
//    $log = '[' . date('D M d H:i:s Y', time()) . '] ';
//    $log .= $message;
//    $log .= "\n";
//    file_put_contents(dirname(__FILE__) . "/modulbank.log", $log, FILE_APPEND);
}