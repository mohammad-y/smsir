<?php

namespace phplusir\smsir;

use GuzzleHttp\Client;

class Smsir
{
    /**
     * This method checks the number of messages and numbers, and if the number is not the same, it returns an error
     * If set $forceUniqueNumbers to true. It also removes duplicate numbers
     *
     * @param $messages
     * @param $numbers
     * @param bool $forceUniqueNumbers
     * @throws \Exception
     */
    public static function checkCountOfParameters(&$messages, &$numbers, $forceUniqueNumbers = false, $convertToArray = true)
    {
        if ($convertToArray) {
            $messages = (array)$messages;
            $numbers = (array)$numbers;
        }

        /**
         * Remove Duplicate numbers
         */
        if ($forceUniqueNumbers && is_array($numbers))
            $numbers = array_unique($numbers);

        if (count($numbers) !== count($messages))
            throw new \Exception('The number of messages and numbers is not the same');
    }

    /**
     * This method used for log the messages to the database if db-log set to true (@ smsir.php in config folder).
     *
     * @param $result
     * @param $messages
     * @param $numbers
     * @internal param bool $addToCustomerClub | set to true if you want to log another message instead main message
     */
    public static function DBlog($result, $messages, $numbers)
    {
        if (config('smsir.db-log')) {
            $res = json_decode($result->getBody(), true);

            if (count($messages) === 1) {
                if (is_array($messages))
                    $messages = array_shift($messages);

                if (is_array($numbers))
                    $numbers = array_shift($numbers);

                SmsirLogs::create([
                    'response' => $res['Message'],
                    'message' => $messages,
                    'status' => $res['IsSuccessful'],
                    'from' => config('smsir.line-number'),
                    'to' => $numbers,
                ]);
            } else {
                foreach (array_combine($messages, $numbers) as $message => $number) {
                    SmsirLogs::create([
                        'response' => $res['Message'],
                        'message' => $message,
                        'status' => $res['IsSuccessful'],
                        'from' => config('smsir.line-number'),
                        'to' => $number,
                    ]);
                }
            }

            return json_encode($res);
        }
        return json_encode(
            json_decode($result->getBody(), true)
        );

    }

    /**
     * this method used in every request to get the token at first.
     *
     * @return mixed - the Token for use api
     */
    public static function getToken()
    {
        $client = new Client();
        $body = ['UserApiKey' => config('smsir.api-key'), 'SecretKey' => config('smsir.secret-key'), 'System' => 'laravel_v_1_4'];
        $result = $client->post('http://restfulsms.com/api/Token', ['json' => $body, 'connect_timeout' => 30]);
        return json_decode($result->getBody(), true)['TokenKey'];
    }

    /**
     * this method return your credit in sms.ir (sms credit, not money)
     *
     * @return mixed - credit
     */
    public static function credit()
    {
        $client = new Client();
        $result = $client->get('http://restfulsms.com/api/credit', ['headers' => ['x-sms-ir-secure-token' => self::getToken()], 'connect_timeout' => 30]);
        return json_decode($result->getBody(), true)['Credit'];
    }

    /**
     * by this method you can fetch all of your sms lines.
     *
     * @return mixed , return all of your sms lines
     */
    public static function getLines()
    {
        $client = new Client();
        $result = $client->get('http://restfulsms.com/api/SMSLine', ['headers' => ['x-sms-ir-secure-token' => self::getToken()], 'connect_timeout' => 30]);
        return json_decode($result->getBody(), true);
    }

    /**
     * Simple send message with sms.ir account and line number
     *
     * @param $messages = Messages - Count must be equal with $numbers
     * @param $numbers = Numbers - must be equal with $messages
     * @param null $sendDateTime = dont fill it if you want to send message now
     *
     * @return mixed, return status
     */
    public static function send($messages, $numbers, $sendDateTime = null, $forceUniqueNumbers = false)
    {
        self::checkCountOfParameters($messages, $numbers, $forceUniqueNumbers);

        $client = new Client();
        if ($sendDateTime === null) {
            $body = ['Messages' => $messages, 'MobileNumbers' => $numbers, 'LineNumber' => config('smsir.line-number')];
        } else {
            $body = ['Messages' => $messages, 'MobileNumbers' => $numbers, 'LineNumber' => config('smsir.line-number'), 'SendDateTime' => $sendDateTime];
        }
        $result = $client->post('http://restfulsms.com/api/MessageSend', ['json' => $body, 'headers' => ['x-sms-ir-secure-token' => self::getToken()], 'connect_timeout' => 30]);

        return self::DBlog($result, $messages, $numbers);
    }

    /**
     * add a person to the customer club contacts
     *
     * @param $prefix = mr, dr, dear...
     * @param $firstName = first name of this contact
     * @param $lastName = last name of this contact
     * @param $mobile = contact mobile number
     * @param string $birthDay = birthday of contact, not require
     * @param string $categotyId = which category id of your customer club to join this contact?
     *
     * @return \Psr\Http\Message\ResponseInterface = $result as json
     */
    public static function addToCustomerClub($prefix, $firstName, $lastName, $mobile, $birthDay = '', $categotyId = '')
    {
        $client = new Client();
        $body = ['Prefix' => $prefix, 'FirstName' => $firstName, 'LastName' => $lastName, 'Mobile' => $mobile, 'BirthDay' => $birthDay, 'CategoryId' => $categotyId];
        $result = $client->post('http://restfulsms.com/api/CustomerClubContact', ['json' => $body, 'headers' => ['x-sms-ir-secure-token' => self::getToken()], 'connect_timeout' => 30]);

        return self::DBlog($result, "افزودن $firstName $lastName به مخاطبین باشگاه ", $mobile);
    }

    /**
     * this method send message to your customer club contacts (known as white sms module)
     *
     * @param $messages
     * @param $numbers
     * @param null $sendDateTime
     * @param bool $canContinueInCaseOfError
     *
     * @return \Psr\Http\Message\ResponseInterface
     */
    public static function sendToCustomerClub($messages, $numbers, $sendDateTime = null, $canContinueInCaseOfError = true)
    {
        self::checkCountOfParameters($messages, $numbers);

        $client = new Client();
        if ($sendDateTime !== null) {
            $body = ['Messages' => $messages, 'MobileNumbers' => $numbers, 'SendDateTime' => $sendDateTime, 'CanContinueInCaseOfError' => $canContinueInCaseOfError];
        } else {
            $body = ['Messages' => $messages, 'MobileNumbers' => $numbers, 'CanContinueInCaseOfError' => $canContinueInCaseOfError];
        }
        $result = $client->post('http://restfulsms.com/api/CustomerClub/Send', ['json' => $body, 'headers' => ['x-sms-ir-secure-token' => self::getToken()], 'connect_timeout' => 30]);

        return self::DBlog($result, $messages, $numbers);
    }

    /**
     * this method add contact to the your customer club and then send a message to him/her
     *
     * @param $prefix
     * @param $firstName
     * @param $lastName
     * @param $mobile
     * @param $message
     * @param string $birthDay
     * @param string $categotyId
     *
     * @return mixed
     */
    public static function addContactAndSend($prefix, $firstName, $lastName, $mobile, $message, $birthDay = '', $categotyId = '')
    {
        $client = new Client();
        $body = ['Prefix' => $prefix, 'FirstName' => $firstName, 'LastName' => $lastName, 'Mobile' => $mobile, 'BirthDay' => $birthDay, 'CategoryId' => $categotyId, 'MessageText' => $message];
        $result = $client->post('http://restfulsms.com/api/CustomerClub/AddContactAndSend', ['json' => [$body], 'headers' => ['x-sms-ir-secure-token' => self::getToken()], 'connect_timeout' => 30]);

        return self::DBlog($result, $message, $mobile);
    }

    /**
     * this method send a verification code to your customer. need active the module at panel first.
     *
     * @param $code
     * @param $number
     *
     * @param bool $log
     *
     * @return mixed
     */
    public static function sendVerification($code, $number)
    {
        self::checkCountOfParameters($code, $number, true, false);

        $client = new Client();
        $body = ['Code' => $code, 'MobileNumber' => $number];
        $result = $client->post('http://restfulsms.com/api/VerificationCode', ['json' => $body, 'headers' => ['x-sms-ir-secure-token' => self::getToken()], 'connect_timeout' => 30]);

        return self::DBlog($result, $code, $number);
    }

    /**
     * @param array $parameters = all parameters and parameters value as an array
     * @param $template_id = you must create a template in sms.ir and put your template id here
     * @param $number = phone number
     * @return mixed = the result
     */
    public static function ultraFastSend(array $parameters, $template_id, $number)
    {
        $params = [];
        foreach ($parameters as $key => $value) {
            $params[] = ['Parameter' => $key, 'ParameterValue' => $value];
        }
        $client = new Client();
        $body = ['ParameterArray' => $params, 'TemplateId' => $template_id, 'Mobile' => $number];
        $result = $client->post('http://restfulsms.com/api/UltraFastSend', ['json' => $body, 'headers' => ['x-sms-ir-secure-token' => self::getToken()], 'connect_timeout' => 30]);

        return json_decode($result->getBody(), true);
    }

    /**
     * this method used for fetch received messages
     *
     * @param $perPage
     * @param $pageNumber
     * @param $formDate
     * @param $toDate
     *
     * @return mixed
     */
    public static function getReceivedMessages($perPage, $pageNumber, $formDate, $toDate)
    {
        $client = new Client();
        $result = $client->get("http://restfulsms.com/api/ReceiveMessage?Shamsi_FromDate={$formDate}&Shamsi_ToDate={$toDate}&RowsPerPage={$perPage}&RequestedPageNumber={$pageNumber}", ['headers' => ['x-sms-ir-secure-token' => self::getToken()], 'connect_timeout' => 30]);

        return json_decode($result->getBody()->getContents())->Messages;
    }

    /**
     * this method used for fetch your sent messages
     *
     * @param $perPage = how many sms you want to fetch in every page
     * @param $pageNumber = the page number
     * @param $formDate = from date
     * @param $toDate = to date
     *
     * @return mixed
     */
    public static function getSentMessages($perPage, $pageNumber, $formDate, $toDate)
    {
        $client = new Client();
        $result = $client->get("http://restfulsms.com/api/MessageSend?Shamsi_FromDate={$formDate}&Shamsi_ToDate={$toDate}&RowsPerPage={$perPage}&RequestedPageNumber={$pageNumber}", ['headers' => ['x-sms-ir-secure-token' => self::getToken()], 'connect_timeout' => 30]);

        return json_decode($result->getBody()->getContents())->Messages;
    }
}
