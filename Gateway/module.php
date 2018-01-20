<?

require_once(__DIR__ . "/../logging.php");

class SIM868Gateway extends IPSModule
{
    
    public function Create()
    {
        parent::Create();
        $this->RequireParent("{6DC3D946-0D31-450F-A8C6-C42DB8D7D4F1}");
        
        $this->RegisterPropertyBoolean ("log", true);
		$this->RegisterPropertyString ("SIM card pin code", "");
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
		$log->LogMessage("Received data"); 
		
		$idBuffer = $this->GetIDForIdent('Buffer');
		$buffer = GetValueString($idBuffer);
		$buffer .= $incomingBuffer;
		
		if (!$this->Lock("ReceivedLock")) { 
			$log->LogMessage("Buffer is already locked. Aborting message handling!"); 
            return false;  
		} else
			$log->LogMessage("Buffer is locked");
		
		$wordsToSearchFor = array("\r\nOK\r\n", "\r\nERROR\r\n", "\r\nNORMAL POWER DOWN\r\n");
		foreach ($wordsToSearchFor as $word) {
			$log->LogMessage("Searching for \"".preg_replace("/(\r\n)+|\r+|\n+/i", " ", $word)."\" in \"".preg_replace("/(\r\n)+|\r+|\n+/i", " ", $buffer)."\"");
			$length = strlen($buffer)-strlen($word);
			$pos = strpos($buffer, $word);
			
			if($pos!== false)
				break;
		}
		
		if($pos === $length) {
			$buffer = preg_replace("/(\r\n)+|\r+|\n+/i", " ", $buffer);
			$buffer = preg_replace("/\s+/", " ", $buffer);
			
			$log->LogMessage("Found a complete messge: ".$buffer);
						
			SetValueString($this->GetIDForIdent("LastReceived"), $buffer);
			$log->LogMessage("Updated variable LastReceived");

			$this->SendDataToChildren(json_encode(Array("DataID" => "{27E8784A-DF07-4142-9C77-281BF411EEB7}", "Buffer" => $buffer)));
			
			$buffer = "";
		} else {
			$incomingBuffer = preg_replace("/(\r\n)+|\r+|\n+/i", " ", $incomingBuffer);
			$log->LogMessage("Received part of message: ", $incomingBuffer);
		}
		
		SetValueString($idBuffer, $buffer);
		
		$this->Unlock("ReceivedLock"); 
		
		
		
		return true;
    }
	
	public function SendCommand(string $Command) {
		if(!$this->EvaluateParent())
			return false;
				
		$log = new Logging($this->ReadPropertyBoolean("log"), IPS_Getname($this->InstanceID));
		
		if (!$this->Lock("ReceivedLock")) { 
			$log->LogMessage("Buffer is already locked. Aborting command"); 
            return false;  
		} else
			$log->LogMessage("Buffer is locked");
		
		$log->LogMessage("Resetting buffer");
		$idBuffer = $this->GetIDForIdent('Buffer');
		$buffer = SetValueString($this->GetIDForIdent('Buffer'), "");
				
		$this->Unlock("ReceivedLock"); 
		
		$log->LogMessage("Sending command: ".$Command);
		$buffer = $Command.chr(13).chr(10);
		try{
			$this->SendDataToParent(json_encode(Array("DataID" => "{79827379-F36E-4ADA-8A95-5F8D1DC92FA9}", "Buffer" => $buffer)));
		
			if ($this->Lock("LastSendtLock")) { 
				$Id = $this->GetIDForIdent("LastSendt");
				SetValueString($Id, $buffer);
				$log->LogMessage("Updated variable LastSendt");
				$this->Unlock("LastSendtLock"); 
			} 
					
		} catch (Exeption $ex) {
			$log->LogMessageError("Failed to send the command ".$Command." . Error: ".$ex->getMessage());
			
			return false;
		}
		
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
