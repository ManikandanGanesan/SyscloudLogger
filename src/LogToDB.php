<?php

namespace SyscloudLogger\SCLogger;

class LogToDB extends SqlHelper
{
    private static $logsToIgnore = array('BGDRIF981', 'BGDRIF966', 'BGDRIF986');

    public function __construct($connectionParamsJsonPath, $connectionOptionsCacheJsonPath = null) 
    {
        parent::__construct($connectionParamsJsonPath, $connectionOptionsCacheJsonPath);
    }
    
    public function addDebug($message)
    {
        
    }
    
    public function addInfo($message)
    {
        try
        {
            $this->insert($message);
        } 
        catch (Exception $ex) {
            error_log("Error occurred while inserting log in DB" . $ex->getMessage());
        }
        
    }
    
    public function addError($message)
    {
        try
        {
            $this->insert($message);
        } 
        catch (Exception $ex) {
            error_log("Error occurred while inserting log in DB" . $ex->getMessage());
        }
    }
    
    public function addWarning($message)
    {
        try
        {
            $this->insert($message);
        } 
        catch (Exception $ex) {
            error_log("Error occurred while inserting log in DB" . $ex->getMessage());
        }
    }
    public function addAlert($message)
    {
        try
        {
            $this->insert($message);
        } 
        catch (Exception $ex) {
            error_log("Error occurred while inserting log in DB" . $ex->getMessage());
        }
    }
    
    public function addEmergency($message)
    {
		return; 
		//removed due to unwated load to db 
        try
        {
            $this->insert($message);
        } 
        catch (Exception $ex) {
            error_log("Error occurred while inserting log in DB" . $ex->getMessage());
        }
    }
    
    private function insert($message)
    {
        $details = json_decode($message, true);
        $businessUserId = $details["businessUserId"];
        $errorCode = $details["Code"];
        if($businessUserId == null || $businessUserId == "")
            return;
        
        if(!in_array($errorCode, self::$logsToIgnore))
        {       
            $dbCredentials = $this->getDBCredentials($businessUserId);
            $dbType = $dbCredentials['DBType'];
            $userId = $details["userId"];
            $domainId = $details["domainId"];
            $createdAt = date("Y-m-d H:i:s", $details["time"]);
            $cloudId = $details["cloudId"];
            $additionalInfo  = $details["additionalInfo"];
            $actionId = isset($details["actionId"])?$details["actionId"]:0;
            $actionResultId = isset($details["actionResultId"])?$details["actionResultId"]:0;
            $accountId = isset($details["accountid"])?$details["accountid"]:0;
            
            // If it is PgSql Database
            if($dbType == 2){
                
                $sqlQuery_write = "INSERT INTO dbo.usereventdetails_tbl(userid, domainid, errorcode, createdat, cloudid, eventstatus, additionaldescription, actionid, actionitemid, accountid)"
                        . " VALUES($userId, $domainId, '$errorCode', '$createdAt', $cloudId, 0, '$additionalInfo', $actionId, $actionResultId, $accountId) RETURNING eventid;";
                $result = $this->executePgSqlQuery($businessUserId, $dbCredentials, $sqlQuery_write);
                
                return;
            }
            $query = "EXEC SCS_InsertUserEventDetails @sUserId=?,@sDomainId=?,@sErrorCode=?,@sCreatedAt=?,@sCloudId=?,@sEventStatus=?,@additionalDescription=?,@actionId=?,@actionItemId=?";
    
            $values = array(
                $userId,
                $domainId,
                $errorCode,
                $createdAt,
                $cloudId,
                0,
                $additionalInfo,
                $actionId,
                $actionResultId
            );
                
                
            $this->executeSqlQuery($businessUserId, $query, $values,
            false, true);
        }
    }

}

