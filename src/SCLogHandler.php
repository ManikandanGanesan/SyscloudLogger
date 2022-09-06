<?php

namespace SyscloudLogger\SCLogger;

use SyscloudLogger\SCLogger\LogToDB;

use Monolog\Logger;
use Monolog\Handler\StreamHandler;
use Monolog\Handler\RedisHandler;


class SCLogHandler
{
    private $_config;
    private $_handler = array();
    private $_syscloudHandler = array();
    private $_formatType;
    private $_appName;
    private $_debug = 0;
    
    private static $_redis = null;
    private static $_fileStreamHandler = null;
    private static $_redisStreamHandler = null;
    private static $_dbStreamHandler = null;
    
    /**
     * initialize logger
     * @param type $channel - Channel Name.
     * @param type $appName - Application Name.
     * @param type $configs - This parameter accepts keys (logfilepath,redisHost)
     * logfilepath (Example:- /home/ubuntu/SCSGBackup/SCSLog/)
     * redisHost (Example:- elasticcachehost.XXXXXX.XX.XXXX.XXX.cache.amazonaws.com)
     */
    public function __construct($channel, $appName, $configs = array())
    {
        $this->_config =  new SCLogConfig($channel, $configs);
        $this->_redishost = $this->_config->_redishost;
        $this->_appName = $appName;
        $this->_debug = $configs['debug'];
        
        $logger = new Logger($this->_config->_channel);
        $streams = $this->getStreamHandler();
        $handlers = $streams['monolog'];
        foreach($handlers as $handler)
        {
            $pushHandler = $logger->pushHandler($handler);
        }

        $this->_handler = $pushHandler;
        if (array_key_exists("syscloud", $streams))
        {
            $this->_syscloudHandler = $streams['syscloud'][0];
        }else{
            $this->_syscloudHandler = NULL;
        }
    }
    
    public function __destruct() 
    {
        
    }
    
    private function getCustomMessage($errorCode, $message, $metadata)
    {
        $additionalInfo = "";
        if($message instanceof  \Exception)
        {
            $params = $message->getExtraParameters();
            $message = $message->getMessage();
            
            $additionalInfo = null;
            if(count($params) > 0)
            {
                $values = array_values($params);
                $additionalInfo = implode(",", $values);
            }
        }

        return $this->getFormattedError($errorCode, $message, $additionalInfo, $metadata);
    }
    
    public function debug($code, $message, $metadata = array())
    {
        return;
        try {
            $customMessage = $this->getCustomMessage($code, $message, $metadata);
            $this->_handler->addDebug($customMessage);
        
            if(isset($this->_syscloudHandler))
                $this->_syscloudHandler->addDebug($customMessage);
            
        } catch (\Exception $ex) {
            error_log("Error in monologger: " . $ex->getMessage() . " error: " . $customMessage);
        }
        
    }

    public function info($code, $message, $metadata = array())
    {
         try {
             $customMessage = $this->getCustomMessage($code, $message, $metadata);
        $this->_handler->addInfo($customMessage);
        
        if(isset($this->_syscloudHandler))
            $this->_syscloudHandler->addInfo($customMessage);
         } catch (\Exception $ex) {
            error_log("Error in monologger: " . $ex->getMessage() . " error: " . $customMessage);
        }
    }

    public function error($code, $message, $metadata = array())
    {
        try{
            $customMessage = $this->getCustomMessage($code, $message, $metadata);
        $this->_handler->addError($customMessage);
        
        if(isset($this->_syscloudHandler))
            $this->_syscloudHandler->addError($customMessage);
         } catch (\Exception $ex) {
            error_log("Error in monologger: " . $ex->getMessage() . " error: " . $customMessage);
        }
    }

    public function warning($code, $message, $metadata = array())
    {
        try{
        $customMessage = $this->getCustomMessage($code, $message, $metadata);
        $this->_handler->addWarning($customMessage);
        
        if(isset($this->_syscloudHandler))
            $this->_syscloudHandler->addWarning($customMessage);
         } catch (\Exception $ex) {
            error_log("Error in monologger: " . $ex->getMessage() . " error: " . $customMessage);
        }
    }

    public function alert($code, $message, $metadata = array())
    {
        try{
            
        $customMessage = $this->getCustomMessage($code, $message, $metadata);
        $this->_handler->addAlert($customMessage);
        
        if(isset($this->_syscloudHandler))
            $this->_syscloudHandler->addAlert($customMessage);
        
         } catch (\Exception $ex) {
            error_log("Error in monologger: " . $ex->getMessage() . " error: " . $customMessage);
        }
    }

    public function emergency($code, $message, $metadata = array())
    {
        try{
        $customMessage = $this->getCustomMessage($code, $message, $metadata);
        $this->_handler->addEmergency($customMessage);
        
        if(isset($this->_syscloudHandler))
            $this->_syscloudHandler->addEmergency($customMessage);
        
         } catch (\Exception $ex) {
            error_log("Error in monologger: " . $ex->getMessage() . " error: " . $customMessage);
        }
    }
    
   
    /**
     * function to format the error.
     * @param type $errorCode - Error code.
     * @param type $message - Error Message.
     * @return type
     */
    private function getFormattedError($errorCode, $message, $additionalInfo = "", $metadata = array())
    {
        $errorText = "";
        switch($this->_formatType)
        {
            case "text": 
                $errorText = "Code: " . $errorCode . " Message: " . $message;
                break;
            
            case "json":
                $errorText = array(
                    "Code" => $errorCode,
                    "Message" => $message,
                    "time" => time(),
                    "userId" => $this->_config->_userId,
                    "businessUserId" => $this->_config->_businessUserId,
                    "cloudId" => $this->_config->_cloudId,
                    "domainId" => $this->_config->_domainId,
                    "accountid" => $this->_config->_accountid,
                    "additionalInfo" => $additionalInfo
                );
                
                if(count($metadata) > 0 && $metadata != null)
                {
                    if(isset($metadata["otherInfo"]))
                    {
                        $errorText["otherInfo"] = $metadata["otherInfo"];
                    }
                    
                    if(isset($metadata["actionId"]))
                    {
                        $errorText["actionId"] = $metadata["actionId"];
                    }
                    
                    if(isset($metadata["actionResultId"]))
                    {
                        $errorText["actionResultId"] = $metadata["actionResultId"];
                    }
                }
               // $errorText = json_encode($errorText);
                break;
        }
        
        return json_encode($errorText);
    }
    
    /**
     * function to select streams depends upon channels
     * @return type
     */
    private function getStreamHandler()
    {
        $stream = "";
        switch($this->_config->_stream)
        {
             case "file":
                 $stream = array(
                    "monolog" => array($this->getFileStreamHandler())
                     );
                 $this->_formatType = "json";
                 break;
             
             case "stdout":
                 $stream = array(
                    "monolog" => array($this->getStdoutStreamHandler())
                     );
                 $this->_formatType = "json";
                 break;
             
                          
             case "stdout_db":
                 $stream1 = $this->getStdoutStreamHandler();
                 $stream2 = $this->getDBStreamHandler();
                 $this->_formatType = "json";
                 $stream = array(
                     "monolog" => array($stream1), 
                     "syscloud" => array($stream2)
                         );
                 break;
             
             case "redis":
                 $stream = array(
                     "monolog" => array($this->getRedisStreamHandler())
                     );
                 $this->_formatType = "json";
                 break;
             
             case "file_redis":
                 $stream1 = $this->getRedisStreamHandler();
                 $stream2 = $this->getFileStreamHandler();
                 $this->_formatType = "json";
                 $stream = array(
                     "monolog" => array($stream1, $stream2)
                     );
                 break;
             
             case "file_redis_db":
                 $stream1 = $this->getRedisStreamHandler();
                 $stream2 = $this->getFileStreamHandler();
                 $stream3 = $this->getDBStreamHandler();
                 $this->_formatType = "json";
                 $stream = array(
                     "monolog" => array($stream1, $stream2), 
                     "syscloud" => array($stream3)
                         );
                 break;
             
             case "file_db":
                 $stream1 = $this->getFileStreamHandler();
                 $stream2 = $this->getDBStreamHandler();
                 $this->_formatType = "json";
                 $stream = array(
                     "monolog" => array($stream1), 
                     "syscloud" => array($stream2)
                         );
                 break;
             
             
             default: 
                 $stream = array(
                    "monolog" => array($this->getFileStreamHandler())
                     );
                 $this->_formatType = "text";
                 break;
                 
        }
        return $stream;
    }
    
    /**
     * function to get file stream
     * @return StreamHandler
     */
    private function getFileStreamHandler()
    {
        if(!self::$_fileStreamHandler)
        {
            $filename = $this->_config->_filename;
            $path = pathinfo($filename, PATHINFO_DIRNAME);

            if(!file_exists($path))
            {
                mkdir($path, 0777, true);
            }
            chmod($path, 0777);

            self::$_fileStreamHandler =  new StreamHandler($filename, Logger::DEBUG);
        }
        
        return self::$_fileStreamHandler;
    }
    
     /**
     * function to get standard output stream
     * @return StreamHandler
     */
    private function getStdoutStreamHandler()
    {
        if(!self::$_fileStreamHandler)
        {
            self::$_fileStreamHandler =  new StreamHandler("php://stderr", Logger::DEBUG);
            $formatter = new \Monolog\Formatter\JsonFormatter();
            self::$_fileStreamHandler->setFormatter($formatter);
        }
        
        return self::$_fileStreamHandler;
    }
    
    
    /**
     * function to get Redis cache stream
     * @return RedisHandler
     */
    private function getRedisStreamHandler()
    {
        if(!self::$_redis)
        {
            $hosts = explode(",",$this->_redishost);
            $redis = new  \RedisCluster(NULL, $hosts);
            
            // $redis = new \Redis();
            //$redis->pconnect($host, 6379);
            self::$_redis = $redis;
            unset($redis);
        }
        
        $setName = $this->getSetName();
        
        if(!self::$_redisStreamHandler)
        {
            self::$_redisStreamHandler =  new RedisHandler(self::$_redis, $setName);
        }
        
        return self::$_redisStreamHandler;
    }
    
    /**
     * function to get sql stream handler.
     * @return type
     */
    private function getDBStreamHandler()
    {
        if(!self::$_dbStreamHandler)
        {
            $connectionParamsJsonPath = __DIR__ . '/connection.json';
            self::$_dbStreamHandler = new LogToDB($connectionParamsJsonPath);
        }
        
        return self::$_dbStreamHandler;
        
    }
    
    /**
     * function to get formatted setKey
     * @return type
     */
    private function getSetName()
    {
        return $this->_appName . ":" . $this->_config->_businessUserId . ":" . $this->_config->_domainId . ":" . $this->_config->_cloudId . ":" . date("dmY");
    }
    
}


