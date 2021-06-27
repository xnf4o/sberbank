<?php

/**
 * Sberbank
 *
 * Laravel sberbank bank acquiring library
 *
 * @link   https://github.com/xnf4o/sberbank
 * @version 1.0
 * @author Evgeniy Gerasimov <xnf4o@inbox.ru>
 */

namespace xnf4o;

use Illuminate\Support\Facades\Log;

class Sberbank
{
    protected $error;

    protected $response;

    protected $payment_id;

    protected $payment_url;

    protected $payment_status;

    private $acquiring_url;

    private $access_token;

    private $url_init;

    private $url_cancel;

    private $url_get_state;

    /**
     * Инициализация
     *
     * @param  bool  $is_access_by_token  Если true, то авторизация через токен, в ином случае login & password
     * @param  array  $auth  Массив данных для авторизации
     */
    public function __construct(bool $is_access_by_token, array $auth)
    {
        $this->acquiring_url = 'https://securepayments.sberbank.ru';

        if (!empty($auth['acquiring_url'])) {
            $this->acquiring_url = $auth['acquiring_url'];
        }

        $this->is_access_by_token = $is_access_by_token;

        if ($this->is_access_by_token) {
            $this->access_token = $auth['access_token'];
        } else {
            $this->login = $auth['login'];
            $this->password = $auth['password'];
        }

        $this->setupUrls();
    }

    /**
     * Настройка урлов
     *
     * @return void
     */
    private function setupUrls(): void
    {
        $this->acquiring_url = $this->checkSlashOnUrlEnd($this->acquiring_url);
        $this->url_init = $this->acquiring_url.'payment/rest/register.do';
        $this->url_cancel = $this->acquiring_url.'payment/rest/reverse.do';
        $this->url_get_state = $this->acquiring_url.'payment/rest/getOrderStatusExtended.do';
    }

    /**
     * Adding slash on end of url string if not there
     *
     * @param  string  $url  УРЛ для проверки
     * @return string
     */
    private function checkSlashOnUrlEnd($url): string
    {
        if ($url[strlen($url) - 1] !== '/') {
            $url .= '/';
        }

        return $url;
    }

    /**
     * TODO: Cancel payment
     * For canceling payment need to use
     * username and password for initialize Sberbank API
     */

    /**
     * Generate Sberbank payment URL
     *
     * -------------------------------------------------
     * For generate url need to send $payment array and array of $items
     * All keys for correct checking in paymentArrayChecked()
     *
     * Sberbank doesn't receive description longer that $description_max_lenght
     * $amount_multiplicator - need for convert price to cents
     *
     * @param  array  $data  array of payment data
     * @return array of data
     */
    public function paymentURL(array $data): array
    {
        if (!$this->paymentArrayChecked($data)) {
            $this->error = 'Incomplete payment data';

            return [
                'success' => false,
                'error' => $this->error,
                'response' => $this->response,
                'payment_id' => $this->payment_id,
                'payment_url' => $this->payment_url,
                'payment_status' => $this->payment_status,
            ];
        }

        $description_max_lenght = 24;
        $amount_multiplicator = 100;

        $data['amount'] = (int)ceil($data['amount'] * $amount_multiplicator);
        $data['currency'] = $this->getCurrency($data['currency']);
        $data['description'] = mb_strimwidth($data['description'], 0, $description_max_lenght - 1, '');

        return [
            'success' => $this->sendRequest($this->url_init, $data),
            'error' => $this->error,
            'response' => $this->response,
            'payment_id' => $this->payment_id,
            'payment_url' => $this->payment_url,
            'payment_status' => $this->payment_status,
        ];
    }

    /**
     * Проверка массива оплаты на наличие всех ключей
     *
     * @param  array  $array_for_check  Массив для проверки
     * @return bool
     */
    private function paymentArrayChecked(array $array_for_check): bool
    {
        $keys = ['orderNumber', 'amount', 'returnUrl', 'failUrl', 'description', 'language'];

        return $this->allKeysIsExistInArray($keys, $array_for_check);
    }

    /**
     * Проверка наличия всех $keys в $arr
     *
     * @param  array  $keys  Массив ключей
     * @param  array  $arr  Массив на проверку
     * @return bool
     */
    private function allKeysIsExistInArray(array $keys, array $arr): bool
    {
        return (bool)!array_diff_key(array_flip($keys), $arr);
    }

    /**
     * Получение кода валюты по наименованию
     *
     * @param  string  $currency  Наименование валюты
     * @return int|null
     */
    private function getCurrency($currency = 'RUB'): ?int
    {
        switch ($currency) {
            case('EUR'):
                return 978;
                break;
            case('USD'):
                return 840;
                break;
            case('RUB'):
                return 643;
                break;
            default:
                return 643;
        }
    }

    /**
     * Отправка запроса в мерчант сбербанка
     *
     * @param  string  $path  Урл API
     * @param  array  $data  Параметры
     * @return bool
     */
    private function sendRequest(string $path, array $data): bool
    {
        if ($this->is_access_by_token) {
            $data['token'] = $this->access_token;
        } else {
            $data['userName'] = $this->login;
            $data['password'] = $this->password;
        }

        $data = http_build_query($data, '', '&');

        if ($curl = curl_init()) {
            curl_setopt($curl, CURLOPT_URL, $path);
            curl_setopt($curl, CURLOPT_FOLLOWLOCATION, true);
            curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($curl, CURLOPT_SSL_VERIFYPEER, false);
            curl_setopt($curl, CURLOPT_POST, true);
            curl_setopt($curl, CURLOPT_POSTFIELDS, $data);
            curl_setopt($curl, CURLOPT_HTTPHEADER, [
                'Cache-Control: no-cache',
                'Content-Type:  application/x-www-form-urlencoded',
            ]);

            $response = curl_exec($curl);
            curl_close($curl);

            $this->response = $response;
            $json = json_decode($response, true);

            if ($json) {
                if ($this->errorsFound()) {
                    return false;
                }

                $this->payment_id = @$json["orderId"];
                $this->payment_url = @$json["formUrl"];
                $this->payment_status = @$json["orderStatus"];

                return true;
            }

            $this->error .= "Can't create connection to: $path | with data: $data";

            return false;
        }

        $this->error .= "CURL init filed: $path | with data: $data";

        return false;
    }

    /**
     * Нахождение возможных ошибок
     *
     * @return bool
     */
    private function errorsFound(): bool
    {
        $response = json_decode($this->response, true);

        if (isset($response['errorCode'])) {
            $error_code = (int)$response['errorCode'];
        } elseif (isset($response['ErrorCode'])) {
            $error_code = (int)$response['ErrorCode'];
        } elseif (isset($response['error']['code'])) {
            $error_code = (int)$response['error']['code'];
        } else {
            $error_code = 0;
        }

        if (isset($response['errorMessage'])) {
            $error_message = $response['errorMessage'];
        } elseif (isset($response['ErrorMessage'])) {
            $error_message = $response['ErrorMessage'];
        } elseif (isset($response['error']['message'])) {
            $error_message = $response['error']['message'];
        } elseif (isset($response['error']['description'])) {
            $error_message = $response['error']['description'];
        } else {
            $error_message = 'Unknown error.';
        }

        if ($error_code !== 0) {
            $this->error = 'Error code: '.$error_code.' | Message: '.$error_message;

            return true;
        }

        return false;
    }

    /**
     * Проверка статуса платежа
     *
     * @param  string  $payment_id  ID платежа
     * @return array
     */
    public function getState(string $payment_id): array
    {
        $params = ['orderId' => $payment_id];

        return [
            'success' => $this->sendRequest($this->url_get_state, $params),
            'error' => $this->error,
            'response' => $this->response,
            'payment_id' => $this->payment_id,
            'payment_url' => $this->payment_url,
            'payment_status' => $this->payment_status,
        ];
    }
}
