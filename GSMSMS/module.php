<?

require_once(__DIR__ . "/../logging.php");

class SIM868GsmSms extends IPSModule
{
    
    public function Create()
    {
        parent::Create();
        //$this->RequireParent("{B969177D-4A13-40FB-8006-3BF7557FA5F6}");
        
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
		$buffer = GetValueString($idBuffer);
		$buffer .= $incomingBuffer;
		
		if (!$this->Lock("ReceivedLock")) { 
			$log->LogMessage("Buffer is already locked. Aborting message handling!"); 
            return false;  
		} else
			$log->LogMessage("Buffer is locked");
		
		// AT+CMGR=1  +CMGR: 1,"",32 06917429000191240A91745960544300008110510233034010E4329D5E0695E5A0B21B442FCFE9 OK 
		
		
		//
		// Handle incoming data
		//
		
		SetValueString($idBuffer, $buffer);
		
		
		
		$this->Unlock("ReceivedLock"); 
		
		SetValueString($idBuffer, $buffer);

		return true;
    }
	
	
	private function Lock($ident){
		$log = new Logging($this->ReadPropertyBoolean("log"), IPS_Getname($this->InstanceID));
		for ($i = 0; $i < 100; $i++){
			if (IPS_SemaphoreEnter("GSM_" . (string) $this->InstanceID . (string) $ident, 1)){
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
        IPS_SemaphoreLeave("GSM_" . (string) $this->InstanceID . (string) $ident);
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
		
		if($this->HasActiveParent()) {
            $instance = IPS_GetInstance($this->InstanceID);
            $parentGUID = IPS_GetInstance($instance['ConnectionID'])['ModuleInfo']['ModuleID'];
            if ($parentGUID == '{6DC3D946-0D31-450F-A8C6-C42DB8D7D4F1}') {
				$log->LogMessage("The parent I/O port is active and supported");
				return true;
			} else
				$log->LogMessageError("The parent I/O port is not supported");
		} else
			$log->LogMessageError("The parent I/O port is not active.");
		
		return false;
	}
}

?>
