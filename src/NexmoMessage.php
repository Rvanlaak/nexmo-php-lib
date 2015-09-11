<?php

/**
 * Class NexmoMessage handles the methods and properties of sending an SMS message.
 *
 * Usage: $var = new NexoMessage ( $account_key, $account_password );
 * Methods:
 *     sendText ( $to, $from, $message, $unicode = null )
 *     sendBinary ( $to, $from, $body, $udh )
 *     pushWap ( $to, $from, $title, $url, $validity = 172800000 )
 *     displayOverview( $nexmo_response=null )
 *
 *     inboundText ( $data=null )
 *     reply ( $text )
 *
 */
class NexmoMessage
{

    // Nexmo account credentials
    /**
     * @var string Nexmo server URI
     *
     * We're sticking with the JSON interface here since json
     * parsing is built into PHP and requires no extensions.
     * This will also keep any debugging to a minimum due to
     * not worrying about which parser is being used.
     */
    var $nx_uri = 'https://rest.nexmo.com/sms/json';

    /**
     * @var bool If recieved an inbound message
     */
    var $inbound_message = false;
    public $to = '';
    public $from = '';
    public $text = '';

    // Current message
    public $network = '';
    public $message_id = '';
    public $ssl_verify = false;
    private $nx_key = '';
    private $nx_secret = '';

    // A few options
    /**
     * @var array The most recent parsed Nexmo response.
     */
    private $nexmo_response = ''; // Verify Nexmo SSL before sending any message

    function NexmoMessage($api_key, $api_secret)
    {
        $this->nx_key    = $api_key;
        $this->nx_secret = $api_secret;
    }

    /**
     * Prepare new WAP message.
     */
    function sendBinary($to, $from, $body, $udh)
    {
        //Binary messages must be hex encoded
        $body = bin2hex($body);
        $udh  = bin2hex($udh);

        // Make sure $from is valid
        $from = $this->validateOriginator($from);

        // Send away!
        $post = array(
            'from' => $from,
            'to'   => $to,
            'type' => 'binary',
            'body' => $body,
            'udh'  => $udh
        );

        return $this->sendRequest($post);
    }

    /**
     * Validate an originator string
     *
     * If the originator ('from' field) is invalid, some networks may reject the network
     * whilst stinging you with the financial cost! While this cannot correct them, it
     * will try its best to correctly format them.
     *
     * @param $inp
     *
     * @return string
     */
    private function validateOriginator($inp)
    {
        // Remove any invalid characters
        $ret = preg_replace('/[^a-zA-Z0-9]/', '', (string) $inp);

        if (preg_match('/[a-zA-Z]/', $inp)) {

            // Alphanumeric format so make sure it's < 11 chars
            $ret = substr($ret, 0, 11);

        } else {

            // Numerical, remove any prepending '00'
            if (substr($ret, 0, 2) == '00') {
                $ret = substr($ret, 2);
                $ret = substr($ret, 0, 15);
            }
        }

        return (string) $ret;
    }

    /**
     * Prepare and send a new message.
     *
     * @param $data
     *
     * @return array|bool|stdClass
     */
    private function sendRequest($data)
    {
        // Build the post data
        $data = array_merge($data, array('username' => $this->nx_key, 'password' => $this->nx_secret));
        $post = '';
        foreach ($data as $k => $v) {
            $post .= "&$k=$v";
        }

        // If available, use CURL
        if (function_exists('curl_version')) {

            $to_nexmo = curl_init($this->nx_uri);
            curl_setopt($to_nexmo, CURLOPT_POST, true);
            curl_setopt($to_nexmo, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($to_nexmo, CURLOPT_POSTFIELDS, $post);

            if ( ! $this->ssl_verify) {
                curl_setopt($to_nexmo, CURLOPT_SSL_VERIFYPEER, false);
            }

            $from_nexmo = curl_exec($to_nexmo);
            curl_close($to_nexmo);

        } elseif (ini_get('allow_url_fopen')) {
            // No CURL available so try the awesome file_get_contents

            $opts       = array(
                'http' =>
                    array(
                        'method'  => 'POST',
                        'header'  => 'Content-type: application/x-www-form-urlencoded',
                        'content' => $post
                    )
            );
            $context    = stream_context_create($opts);
            $from_nexmo = file_get_contents($this->nx_uri, false, $context);

        } else {
            // No way of sending a HTTP post :(
            return false;
        }

        return $this->nexmoParse($from_nexmo);
    }

    /**
     * Parse server response.
     *
     * @param $from_nexmo
     *
     * @return array|bool|stdClass
     */
    private function nexmoParse($from_nexmo)
    {
        $response = json_decode($from_nexmo);

        // Copy the response data into an object, removing any '-' characters from the key
        $response_obj = $this->normaliseKeys($response);

        if ($response_obj) {
            $this->nexmo_response = $response_obj;

            // Find the total cost of this message
            $response_obj->cost = $total_cost = 0;
            if (is_array($response_obj->messages)) {
                foreach ($response_obj->messages as $msg) {
                    $total_cost = $total_cost + (float) $msg->messageprice;
                }
                $response_obj->cost = $total_cost;
            }

            return $response_obj;

        } else {
            // A malformed response
            $this->nexmo_response = array();

            return false;
        }

    }

    /**
     * Recursively normalise any key names in an object, removing unwanted characters.
     *
     * @param $obj
     *
     * @return array|stdClass
     */
    private function normaliseKeys($obj)
    {
        // Determine is working with a class or araay
        if ($obj instanceof stdClass) {
            $new_obj = new stdClass();
            $is_obj  = true;
        } else {
            $new_obj = array();
            $is_obj  = false;
        }

        foreach ($obj as $key => $val) {
            // If we come across another class/array, normalise it
            if ($val instanceof stdClass || is_array($val)) {
                $val = $this->normaliseKeys($val);
            }

            // Replace any unwanted characters in they key name
            if ($is_obj) {
                $new_obj->{str_replace('-', '', $key)} = $val;
            } else {
                $new_obj[str_replace('-', '', $key)] = $val;
            }
        }

        return $new_obj;
    }

    /**
     * Prepare new binary message.
     *
     * @param $to
     * @param $from
     * @param $title
     * @param $url
     * @param int $validity
     *
     * @return array|bool|stdClass
     */
    function pushWap($to, $from, $title, $url, $validity = 172800000)
    {
        // Making sure $title and $url are UTF-8 encoded
        if ( ! mb_check_encoding($title, 'UTF-8') || ! mb_check_encoding($url, 'UTF-8')) {
            trigger_error('$title and $udh need to be valid UTF-8 encoded strings');

            return false;
        }

        // Make sure $from is valid
        $from = $this->validateOriginator($from);

        // Send away!
        $post = array(
            'from'     => $from,
            'to'       => $to,
            'type'     => 'wappush',
            'url'      => $url,
            'title'    => $title,
            'validity' => $validity
        );

        return $this->sendRequest($post);

    }

    /**
     * Display a brief overview of a sent message. Useful for debugging and quick-start purposes.
     *
     * @param object $nexmo_response
     *
     * @return string representing an html table or cli output
     */
    public function displayOverview($nexmo_response = null)
    {
        $result = $this->parseResponse($nexmo_response);

        // Build the output
        if (isset($_SERVER['HTTP_HOST'])) {
            // HTML output
            $ret = '<table><tr><td colspan="2">'.$result['status'].'</td></tr>';
            $ret .= '<tr><th>Status</th><th>Message ID</th></tr>';
            foreach ($result['message_status'] as $mstat) {
                $ret .= '<tr><td>'.$mstat['status'].'</td><td>'.$mstat['id'].'</td></tr>';
            }
            $ret .= '</table>';

        } else {

            // CLI output
            $ret = $result['status'].":\n";

            // Get the sizes for the table
            $out_sizes = array('id' => strlen('Message ID'), 'status' => strlen('Status'));
            foreach ($result['message_status'] as $mstat) {
                if ($out_sizes['id'] < strlen($mstat['id'])) {
                    $out_sizes['id'] = strlen($mstat['id']);
                }
                if ($out_sizes['status'] < strlen($mstat['status'])) {
                    $out_sizes['status'] = strlen($mstat['status']);
                }
            }

            $ret .= '  '.str_pad('Status', $out_sizes['status'], ' ').'   ';
            $ret .= str_pad('Message ID', $out_sizes['id'], ' ')."\n";
            foreach ($result['message_status'] as $mstat) {
                $ret .= '  '.str_pad($mstat['status'], $out_sizes['status'], ' ').'   ';
                $ret .= str_pad($mstat['id'], $out_sizes['id'], ' ')."\n";
            }
        }

        return $ret;
    }

    /**
     * Return an array with the results of the text message.
     *
     * @param object $nexmo_response
     *
     * @return array
     */
    public function parseResponse($nexmo_response = null)
    {
        $info   = ( ! $nexmo_response) ? $this->nexmo_response : $nexmo_response;
        $result = array();

        if ( ! $nexmo_response) {
            return $result['status'] = 'Cannot display an overview of this response';
        }

        // How many messages were sent?
        if ($info->messagecount > 1) {
            $result['status'] = 'Your message was sent in '.$info->messagecount.' parts';
        } elseif ($info->messagecount == 1) {
            $result['status'] = 'Your message was sent';
        } else {
            return $result['status'] = 'There was an error sending your message';
        }

        // Build an array of each message status and ID
        if ( ! is_array($info->messages)) {
            $info->messages = array();
        }

        $result['message_status'] = array();
        foreach ($info->messages as $message) {
            $tmp = array('id' => '', 'status' => 0);

            if ($message->status != 0) {
                $tmp['status'] = $message->errortext;
            } else {
                $tmp['status'] = 'OK';
                $tmp['id']     = $message->messageid;
            }

            $result['message_status'][] = $tmp;
        }

        return $result;
    }

    /**
     * Check for any inbound messages, using $_GET by default.
     *
     * This will set the current message to the inbound
     * message allowing for a future reply() call.
     */
    public function inboundText($data = null)
    {
        if ( ! $data) {
            $data = $_GET;
        }

        if ( ! isset($data['text'], $data['msisdn'], $data['to'])) {
            return false;
        }

        // Get the relevant data
        $this->to         = $data['to'];
        $this->from       = $data['msisdn'];
        $this->text       = $data['text'];
        $this->network    = (isset($data['network-code'])) ? $data['network-code'] : '';
        $this->message_id = $data['messageId'];

        // Flag that we have an inbound message
        $this->inbound_message = true;

        return true;
    }

    /**
     * Reply the current message if one is set.
     */
    public function reply($message)
    {
        // Make sure we actually have a text to reply to
        if ( ! $this->inbound_message) {
            return false;
        }

        return $this->sendText($this->from, $this->to, $message);
    }

    /**
     * Prepare new text message.
     *
     * If $unicode is not provided we will try to detect the
     * message type. Otherwise set to TRUE if you require
     * unicode characters.
     */
    function sendText($to, $from, $message, $unicode = null)
    {
        // Making sure strings are UTF-8 encoded
        if ( ! is_numeric($from) && ! mb_check_encoding($from, 'UTF-8')) {
            trigger_error('$from needs to be a valid UTF-8 encoded string');

            return false;
        }

        if ( ! mb_check_encoding($message, 'UTF-8')) {
            trigger_error('$message needs to be a valid UTF-8 encoded string');

            return false;
        }

        if ($unicode === null) {
            $containsUnicode = max(array_map('ord', str_split($message))) > 127;
        } else {
            $containsUnicode = (bool) $unicode;
        }

        // Make sure $from is valid
        $from = $this->validateOriginator($from);

        // URL Encode
        $from    = urlencode($from);
        $message = urlencode($message);

        // Send away!
        $post = array(
            'from' => $from,
            'to'   => $to,
            'text' => $message,
            'type' => $containsUnicode ? 'unicode' : 'text'
        );

        return $this->sendRequest($post);
    }
}
