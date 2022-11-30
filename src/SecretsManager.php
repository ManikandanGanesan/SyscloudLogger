<?php

namespace SyscloudLogger\SCLogger;

require_once __DIR__.'/sysCloudCache.php';

/**
 * Class to access secret manager to get the module keys 
 */
class SecretsManager 
{
    private static $hostName ;
    private static $basePath ;
    private static $maxRetry ;
    private static $ttl;

    private static function init() {
        $config = json_decode(file_get_contents(__DIR__ .'/config.json'));
        //self::$hostName = $config->secretsManager->hostName;
        self::$hostName = SECRETS_MANAGER_HOSTNAME;
        self::$basePath = $config->secretsManager->basePath;
        self::$maxRetry = $config->secretsManager->retry;
        self::$ttl = $config->secretsManager->cachettl;
        if (self::$hostName == null || self::$maxRetry == null || self::$basePath == null) {
            throw new \Exception('unable to load hostName, basePath and retry for secret manager');
        }
    }
    
    public static function getSecretKey($key, $basePath = false) {
        if (self::$hostName == null || self::$maxRetry == null || self::$basePath == null) {
            self::init();
        }
        $cacheKey = str_replace('/', '_', $key);
        if($basePath == 'dbconnection/' && sysCloudCache::hasKey($cacheKey) == false)
        {
            self::doAPICallForDBConnections();
        }
        if (sysCloudCache::hasKey($cacheKey)) {
            $value = sysCloudCache::get($cacheKey);
            if ($value === false) {
            $value = self::doAPICall($key, $basePath);
            }
        } else {
            $value = self::doAPICall($key, $basePath);
        }
        return $value;
    }
    private static function doAPICallForDBConnections()
    {
        if (sysCloudCache::hasKey('dbconnections')) {
            $value = sysCloudCache::get('dbconnections');
            if ($value != false) {
                return;
            }
        }
        $url = self::$hostName . 'dbconnections';
        $response = self::getAPIResponse($url);
        $dbConnections = json_decode($response);
        sysCloudCache::set('dbconnections', $dbConnections, self::$ttl);
        foreach ($dbConnections as $key => $dbConnection)
        {
            sysCloudCache::set($key, $dbConnection, self::$ttl);
        }
        return ;
    }
    private static function doAPICall($key, $basePath = false)
    {
        $cacheKey = str_replace('/', '_', $key);
        $url = self::$hostName . ($basePath ?: self::$basePath) . $key;
        $response = self::getAPIResponse($url);
        if ($response !== false && $response != 'Failed to get Key ') {
            if ($key == 'google' && $basePath == 'auth/') {
                sysCloudCache::set($cacheKey, $response, self::$ttl);
                return $response;
            }
            $responseParsed = json_decode($response);
            //temp fix 
            $value =  isset($responseParsed->keyvalue) ? $responseParsed->keyvalue : $responseParsed;
            sysCloudCache::set($cacheKey, $value, self::$ttl);
            return $value;
        } else {
            throw new \Exception('Failed to get the secret key :: key name = '.$key);
        }
    }


    /**
     * function to get the keys from secret manager API
     * @param array $keys should be an array of keys to be extracted
     * @return array associative array will return values for appropriate keys from secrets manager
     * @throws \Exception if failed to get any value or any other errors
     * @deprecated
     */
    // TODO:: cache the result, if not there then only make API calls, add parameter to set no cache so we don't need cache (direct API)
    // make separate function to support single value input and output
    public static function getSecretKeys($keys, $basePath = false) {
        $result = array();
        foreach ($keys as $key) {
            if (isset($result[$key])) {
                
            } else {
                $result[$key] = self::getSecretKey($key, $basePath);
            }
        }
        return $result;
    }
    
    // call usign curl
    private static function getHttpRes($method, $url, $headers = null, $body = null) {
        for ($i = 0; $i < self::$maxRetry; $i++) {
            $ch = curl_init($url);
            curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
            if($body){
                curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
            }
            curl_setopt($ch, CURLOPT_FOLLOWLOCATION, false);
            curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);        
            curl_setopt($ch, CURLOPT_HTTP_VERSION, CURL_HTTP_VERSION_2_0);   
            curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
            $response = curl_exec($ch);
            $responseCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $error = curl_error($ch);
            if(str_contains($response, 'Data is not found for this business'))
            {
                break;
            }
            if ($error != '' || $response == 'Failed to get Key ' || $response === false || $responseCode != 200) {
                // log error message                
                usleep((1 << $i) * 1000000 + rand(0, 1000000));
            } else {
                return $response;
            }
        }
        throw new \Exception('Failed to get the secret key :: $url = '.$url.' :: with '.$responseCode.' :: '.$error);
    }
    
     public static function getAccessToken() {
        $url = self::$hostName . 'auth';
        if (sysCloudCache::hasKey('secretaccesstoken')) {
            $value = sysCloudCache::get('secretaccesstoken');
            if (!empty($value)) {
                return $value['accessToken'];
            }
        }
        $strTime = microtime(true);
        $headers = ['Content-Type:application/json'];
        $cred= array("username" => "Monologger",
                             "clientid" => "39",
                            "password" => "I95X1T8N1SY7G4") ;
        $body = json_encode($cred);
        $response = self::getHttpRes("POST", $url, $headers, $body);
        $response = json_decode($response, true);
        sysCloudCache::set('secretaccesstoken', $response, self::$ttl);
        return $response['accessToken'];
    }
    
    private static function getAPIResponse($url) {
        $accessToken = self::getAccessToken();
        $headers = [
            "Content-Type:application/json",
            "x-access-token: $accessToken",
            "X-Requestor-Id: Monologger"  
        ];
	return self::getHttpRes("GET", $url, $headers);
        }
    
    
}


