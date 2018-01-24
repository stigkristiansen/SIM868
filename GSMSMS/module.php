<?

require_once(__DIR__ . "/../logging.php");

class SIM868GsmSms extends IPSModule
{
    
    public function Create()
    {
        parent::Create();
        $this->RequireParent("{B969177D-4A13-40FB-8006-3BF7557FA5F6}");
        
        $this->RegisterPropertyBoolean ("log", true);
		
    }

    public function ApplyChanges(){
        parent::ApplyChanges();
        
        $this->RegisterVariableString("LastSendt", "LastSendt");
        $this->RegisterVariableString("LastReceived", "LastReceived");
		$this->RegisterVariableString("Buffer", "Buffer");
        
        IPS_SetHidden($this->GetIDForIdent('LastSendt'), true);
        IPS_SetHidden($this->GetIDForIdent('LastReceived'), true);
		IPS_SetHidden($this->GetIDForIdent('Buffer'), true);
    }

    public function ReceiveData($JSONString) {
		$this->Unlock("SendingState");

		$incomingData = json_decode($JSONString);
		$incomingBuffer = utf8_decode($incomingData->Buffer);
				
		$log = new Logging($this->ReadPropertyBoolean("log"), IPS_Getname($this->InstanceID));
		$log->LogMessage("Received data: ".$incomingBuffer); 
				
		if (!$this->Lock("ReceivedQueue_84D523A8-DD46-4AA6-9E2D-3C977B670FCC")) { 
			$log->LogMessage("Queue is already locked. Aborting message handling!"); 
            return false;  
		} else
			$log->LogMessage("Queue is locked");
		
		$id = $this->GetIDForIdent('LastReceived');
		$json = GetValueString($id);
		$queue = json_decode($json);
		$queue[] = $incomingBuffer;
		$json = json_encode($queue);
		SetValueString($id, $json);
		$log->LogMessage("New queue is ".$json);
				
		$this->Unlock("ReceivedQueue_84D523A8-DD46-4AA6-9E2D-3C977B670FCC"); 
		SetValueBoolean (22640, false);
		
		$parameters = Array("SemaphoreIdent" => $this->BuildSemaphoreName("ReceivedQueue_84D523A8-DD46-4AA6-9E2D-3C977B670FCC"), "QueueId" => (string) $id);
		
		IPS_RunScriptEx(29268, $parameters);
		
		return true;
    }
	
	private function ReadSMSMessage($Number) {
		$log = new Logging($this->ReadPropertyBoolean("log"), IPS_Getname($this->InstanceID));
		
		$log->LogMessage("Reading meassage ". $Number);
		
		//$this->SendATCommand("AT+CMGR=".$Number);
		
		//$this->SendATCommand("AT+CMGD=".$Number);
				
		return true;
	}
	
	private function HandleResponse($Response) {
		$pos = strpos($Response, "+CMTI: \"SM\"");
		if($pos!==false && $pos===0) {
			// Incoming SMS messasge
			// +CMTI: "SM",6
			return $this->ReadSMSMessage(substr($Response, 12));
		}
		
		return true;
	}
	
	private function SendATCommand($Command) {
		WaitForResponse(1000);
		
		SetValueBoolean(22640, true);
		
		$log = new Logging($this->ReadPropertyBoolean("log"), IPS_Getname($this->InstanceID));
		
		$log->LogMessage("Sending command \"".$Command."\"to parent gateway...");
		$this->SendDataToParent(json_encode(Array("DataID" => "{51C4B053-9596-46BE-A143-E3086636E782}", "Buffer" => $Command)));
	
	}
	
	Public function SendCommand(string $Command) {
		$this->SendATCommand($Command);
	}
	
	private function WaitForResponse ($Timeout) {
		$log = new Logging($this->ReadPropertyBoolean("log"), IPS_Getname($this->InstanceID));
		
		$idBuffer = 22640;//$this->GetIDForIdent('Buffer');
		
		$response=""; 
		$iteration = intval($Timeout/100);
 		for($x=0;$x<$iteration;$x++) { 
 			$response = GetValueBoolean($idBuffer); 
 			 
 			if(!$response) { 
 				$log->LogMessage("A sending was completed"); 
 				return true; 
 			} else 
 				$log->LogMessage("Sending already in use. Waiting..."); 
 				 
 			IPS_Sleep(100); 
 		} 

		return false;
	}
	
	private function BuildSemaphoreName($ident) {
		return "GSMSMS_" . (string) $this->InstanceID . (string) $ident;
	}
	
	private function Lock($ident){
		$log = new Logging($this->ReadPropertyBoolean("log"), IPS_Getname($this->InstanceID));
		for ($i = 0; $i < 100; $i++){
			if (IPS_SemaphoreEnter($this->BuildSemaphoreName($ident), 1)){
				$log->LogMessage("Semaphore ".$ident." is set"); 
				return true;
			} else {
				if($i==0)
					$log->LogMessage("Waiting for lock...");
				IPS_Sleep(mt_rand(1, 5));
			}
		}
        
        $log->LogMessage($ident." is already locked"); 
        return false;
    }

    private function Unlock($ident)
    {
        IPS_SemaphoreLeave($this->BuildSemaphoreName($ident));
		$log = new Logging($this->ReadPropertyBoolean("log"), IPS_Getname($this->InstanceID));
		$log->LogMessage("Semaphore ".$ident." is cleared");
    }
	
	private function HasActiveParent(){
        $instance = IPS_GetInstance($this->InstanceID);
        if ($instance['ConnectionID'] > 0){
            $parent = IPS_GetInstance($instance['ConnectionID']);
            if ($parent['InstanceStatus'] == 102)
                return true;
        }
        return false;
    }
	
	private function EvaluateParent() {
    	$log = new Logging($this->ReadPropertyBoolean("log"), IPS_Getname($this->InstanceID));
		
		$instance = IPS_GetInstance($this->InstanceID);
		$parentGUID = IPS_GetInstance($instance['ConnectionID'])['ModuleInfo']['ModuleID'];
		if ($parentGUID == '{B969177D-4A13-40FB-8006-3BF7557FA5F6}') {
			$log->LogMessage("The parent is supported");
			return true;
		} else
			$log->LogMessageError("The parent is not supported");
		
		return false;
	}
}

?>
