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
		$incomingData = json_decode($JSONString);
		$incomingBuffer = utf8_decode($incomingData->Buffer);
				
		$log = new Logging($this->ReadPropertyBoolean("log"), IPS_Getname($this->InstanceID));
		$log->LogMessage("Received data: ".$incomingBuffer); 
		
		$idBuffer = $this->GetIDForIdent('Buffer');
		$buffer = $incomingBuffer;
		
		if (!$this->Lock("ReceivedLock")) { 
			$log->LogMessage("Buffer is already locked. Aborting message handling!"); 
            return false;  
		} else
			$log->LogMessage("Buffer is locked");
		
		// AT+CMGR=1 +CMGR: 1,"",32 06917429000191240A91745960544300008110510233034010E4329D5E0695E5A0B21B442FCFE9 OK 
		
		
		//
		// Handle incoming data
		//
		
		SetValueString($this->GetIDForIdent('LastReceived'), $buffer);
		SetValueString($this->GetIDForIdent('Buffer'), $buffer);
		
		$this->Unlock("ReceivedLock"); 
		
		$this->HandleResponse($buffer);
		
		return true;
    }
	
	private function ReadSMSMessage($Number) {
		$log = new Logging($this->ReadPropertyBoolean("log"), IPS_Getname($this->InstanceID));
		
		$log->LogMessage("Reading meassage ". $Number);
		
		$this->SendATCommand("AT+CMGR=".$Number);
		
		$this->SendATCommand("AT+CMGD=".$Number);
				
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
		$log = new Logging($this->ReadPropertyBoolean("log"), IPS_Getname($this->InstanceID));
		
		if ($this->Lock("BufferLock")) 
			SetValueString($this->GetIDForIdent('Buffer'), '');
		else {
			$log->LogMessage("Unable to lock the buffer");
			return false;
		}
			
		$this->Unlock("BufferLock");
		
		$log->LogMessage("Sending command to parent gateway and waiting for response...");
		$this->SendDataToParent(json_encode(Array("DataID" => "{51C4B053-9596-46BE-A143-E3086636E782}", "Buffer" => $Command)));
	
		/*if($this->WaitForResponse(1000)) {
			$log->LogMessage("Got response back from parent gateway");
			return true;
		} else {
			$log->LogMessage("Timed out waiting for response from parent gateway");
			return false;
		}*/
	}
	
	Public function SendCommand(string $Command) {
		$this->SendATCommand($Command);
	}
	
	private function WaitForResponse ($Timeout) {
		$log = new Logging($this->ReadPropertyBoolean("log"), IPS_Getname($this->InstanceID));
		
		$idBuffer = $this->GetIDForIdent('Buffer');
		
		$response=""; 
		$iteration = intval($Timeout/100);
 		for($x=0;$x<$iteration;$x++) { 
 			$response = GetValueString($idBuffer); 
 			 
 			if(strlen($response)>0) { 
 				$log->LogMessage("Response from gateway: ".$response); 
 				return true; 
 			} else 
 				$log->LogMessage("Still waiting..."); 
 				 
 			IPS_Sleep(100); 
 		} 

		return false;
	}
	
	private function Lock($ident){
		$log = new Logging($this->ReadPropertyBoolean("log"), IPS_Getname($this->InstanceID));
		for ($i = 0; $i < 100; $i++){
			if (IPS_SemaphoreEnter("GSMSMS_" . (string) $this->InstanceID . (string) $ident, 1)){
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
        IPS_SemaphoreLeave("GSMSMS_" . (string) $this->InstanceID . (string) $ident);
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
