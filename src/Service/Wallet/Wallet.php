<?php
namespace App\Service\Wallet;

use Symfony\Component\Yaml\Yaml;

/*
EasyBitcoin-PHP - modified by Cyfer

A simple class for making calls to Bitcoin's API using PHP.
https://github.com/aceat64/EasyBitcoin-PHP

====================

The MIT License (MIT)

Copyright (c) 2013 Andrew LeCody

Permission is hereby granted, free of charge, to any person obtaining a copy
of this software and associated documentation files (the "Software"), to deal
in the Software without restriction, including without limitation the rights
to use, copy, modify, merge, publish, distribute, sublicense, and/or sell
copies of the Software, and to permit persons to whom the Software is
furnished to do so, subject to the following conditions:

The above copyright notice and this permission notice shall be included in
all copies or substantial portions of the Software.

THE SOFTWARE IS PROVIDED "AS IS", WITHOUT WARRANTY OF ANY KIND, EXPRESS OR
IMPLIED, INCLUDING BUT NOT LIMITED TO THE WARRANTIES OF MERCHANTABILITY,
FITNESS FOR A PARTICULAR PURPOSE AND NONINFRINGEMENT. IN NO EVENT SHALL THE
AUTHORS OR COPYRIGHT HOLDERS BE LIABLE FOR ANY CLAIM, DAMAGES OR OTHER
LIABILITY, WHETHER IN AN ACTION OF CONTRACT, TORT OR OTHERWISE, ARISING FROM,
OUT OF OR IN CONNECTION WITH THE SOFTWARE OR THE USE OR OTHER DEALINGS IN
THE SOFTWARE.

====================
*/

class Wallet
{
    // Configuration options
    private $username;
    private $password;
    private $proto;
    private $host;
    private $port;
    private $url;
    private $CACertificate;

    // Information and debugging
    public $status;
    public $error;
    public $raw_response;
    public $response;

    private $id = 0;


    /**
     * @param string $type type of crypto [bitcoin, monero, zcash]
     */
    public function __construct($type)
    {
        $this->proto         = 'http';
        $this->CACertificate = null;

        $yaml = Yaml::parseFile($_SERVER['DOCUMENT_ROOT'] . '/../config/rpc.yaml');

        $this->username      = $yaml['config'][$type]['username'];
        $this->password      = $yaml['config'][$type]['password'];
        $this->host          = $yaml['config'][$type]['host'];
        $this->port          = $yaml['config'][$type]['port'];
        $this->url           = null;

        if ($yaml['config'][$type]['ssl'] == true && isset($yaml['config'][$type]['ssl_path'])) {
            // Set some defaults
            $this->proto         = 'https';
            $this->CACertificate = $yaml['config'][$type]['ssl_path'];
        }
    }

    /**
     * When finalizing/refunding an order.
     * $mkt_amount will be 0 if refunded.
     *
     * @param $receiver_addr string address of receiver
     * @param $receiver_amount double amount to send to receiver
     * @param $mkt_addr string address of marketplace
     * @param $mkt_amount double fee sent to the marketplace
     * @param $fee double fee for bitcoin transaction
     * @param $deposit_addr string address the multisig was stored in
     * @return mixed
     */
    public function finalize($receiver_addr, $receiver_amount, $mkt_addr, $mkt_amount, $fee, $deposit_addr)
    {
        if ($mkt_amount === 0) {
            $request = "{\"id\":\"curltext\",\"method\":\"paytomany\",\"params\":{\"outputs\":
            [[\"{$receiver_addr}\", {$receiver_amount}]],
             \"fee\": {$fee}, \"from_addr\": \"{$deposit_addr}\"}}";
        } else {
            $request = "{\"id\":\"curltext\",\"method\":\"paytomany\",\"params\":{\"outputs\":
            [[\"{$receiver_addr}\", {$receiver_amount}], [\"{$mkt_addr}\", {$mkt_amount}]],
             \"fee\": {$fee}, \"from_addr\": \"{$deposit_addr}\"}}";
        }

        return $this->complete("", $request);
    }

    public function __call($method, $params)
    {
        $this->status       = null;
        $this->error        = null;
        $this->raw_response = null;
        $this->response     = null;
        // If no parameters are passed, this will be an empty array
        $params = array_values($params);
        // The ID should be unique for each call
        $this->id++;

        if (!empty($method)) {
            // Build the request, it's ok that params might have any empty array
            $request = json_encode(array(
                'method' => $method,
                'params' => $params,
                'id'     => $this->id
            ));
        }

        // Build the cURL session
        $curl    = curl_init("{$this->proto}://{$this->host}:{$this->port}/{$this->url}");
        $options = array(
            CURLOPT_HTTPAUTH       => CURLAUTH_BASIC,
            CURLOPT_USERPWD        => $this->username . ':' . $this->password,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS      => 10,
            CURLOPT_HTTPHEADER     => array('Content-type: application/json'),
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $request
        );
        // This prevents users from getting the following warning when open_basedir is set:
        // Warning: curl_setopt() [function.curl-setopt]:
        //   CURLOPT_FOLLOWLOCATION cannot be activated when in safe_mode or an open_basedir is set
        if (ini_get('open_basedir')) {
            unset($options[CURLOPT_FOLLOWLOCATION]);
        }
        if ($this->proto == 'https') {
            // If the CA Certificate was specified we change CURL to look for it
            if (!empty($this->CACertificate)) {
                $options[CURLOPT_CAINFO] = $this->CACertificate;
                $options[CURLOPT_CAPATH] = DIRNAME($this->CACertificate);
            } else {
                // If not we need to assume the SSL cannot be verified
                // so we set this flag to FALSE to allow the connection
                $options[CURLOPT_SSL_VERIFYPEER] = false;
            }
        }
        curl_setopt_array($curl, $options);
        // Execute the request and decode to an array
        $this->raw_response = curl_exec($curl);
        $this->response     = json_decode($this->raw_response, true);
        // If the status is not 200, something is wrong
        $this->status = curl_getinfo($curl, CURLINFO_HTTP_CODE);
        // If there was no error, this will be an empty string
        $curl_error = curl_error($curl);
        curl_close($curl);
        if (!empty($curl_error)) {
            $this->error = $curl_error;
        }
        if ($this->response['error']) {
            // If bitcoind returned an error, put that in $this->error
            $this->error = $this->response['error']['message'];
        } elseif ($this->status != 200) {
            // If bitcoind didn't return a nice error message, we need to make our own
            switch ($this->status) {
                case 400:
                    $this->error = 'HTTP_BAD_REQUEST';
                    break;
                case 401:
                    $this->error = 'HTTP_UNAUTHORIZED';
                    break;
                case 403:
                    $this->error = 'HTTP_FORBIDDEN';
                    break;
                case 404:
                    $this->error = 'HTTP_NOT_FOUND';
                    break;
            }
        }
        if ($this->error) {
            return false;
        }
        return $this->response['result'];
    }
}
