<?php 

/**
 Setup for Callblocker tellows
 
 @Copyright 2014 Stefan Rick
 @author Stefan Rick
 Mail: stefan@rick-software.de
 Web: http://www.netzberater.de
 
 */

class Callblocker_Setup extends Service {	
	
	public function __construct(){		
		parent::__construct();		
		$this->registerLocale(dirname(__FILE__).'/../locale', 'callblocker');
		$this->pluginname = _('tellows Callblocker Setup');
		
		if(isset($_GET['action'])){
			if($_GET['action'] == 'savetellows'){
				$this->_saveTellowsConf();
			}elseif($_GET['action'] == 'savelinphone'){
				$this->_saveLinphoneConf();
			}elseif($_GET['action'] == 'updateCallblocker'){
				$this->_updateCallblocker();
			}
		}
		
		if(isset($_GET['actionupdate']) && $_GET['actionupdate'] == 'updateCallblocker'){
			$this->_updateCallblocker();		
		}
		
		//SIP Config
		$this->_getLinphoneConf();
		
		//Check, ob Logindaten stimmen
		$this->_getTellowsConf();
		
		//Check auf Modem
		$this->modemConnected();
		
		$this->getAllLogs();
		
		$this->getCBVersion();
	}
	
	private function _saveTellowsConf(){
		//Call Scripts
		$this->view->message[] = _t('Save tellows Settings');		
		$this->writeDynamicScript(array("echo 'partner=tellowskey\napikey=".$_GET['tellows_apikey']."\nminscore=".$_GET['tellows_minscore']."\ncountry=".$_GET['tellows_country']."\nchecked=1' > /opt/callblocker/tellows.conf"));
		$this->_getTellowsConf();
		if($this->tellows->registered_bool === FALSE){
			$this->writeDynamicScript(array("echo 'partner=tellowskey\napikey=".$_GET['tellows_apikey']."\nminscore=".$_GET['tellows_minscore']."\ncountry=".$_GET['tellows_country']."\nchecked=0' > /opt/callblocker/tellows.conf"));
			$this->view->message[] = _t('API-Key could not be registered and seems to be wrong!');
		}else{
			$this->view->message[] = _t('API-Key successfully registered!');			
		}
		//delete tellows blacklist and get new One!
		$this->writeDynamicScript(array('rm /opt/callblocker/cache/tellows.csv','sudo /opt/callblocker/tellowsblacklist.sh'));
		return true;		
	}
	
	/**
	 * partner=tellowskey
	 * apikey=test
	 * minscore=7
	 */
	private function _getTellowsConf(){		
		$this->tellows = new stdClass();
		$output = shell_exec('cat /opt/callblocker/tellows.conf');
		preg_match('=apikey\=([a-zA-Z0-9]*)=', $output, $match);
		$this->tellows->apikey = $match[1];
		preg_match('=minscore\=([0-9])=', $output, $match);
		$this->tellows->minscore = $match[1];
		preg_match('=country\=([a-z]*)=', $output, $match);
		$this->tellows->country = $match[1];
		
		//tellows Testcall
		$output = $this->writeDynamicScript(array('wget -O /opt/callblocker/cache/apitest.txt "http://www.tellows.de/api/checklicense?partner=tellowskey&apikeyMd5='.md5($this->tellows->apikey).'"'));		
		$output = shell_exec('cat /opt/callblocker/cache/apitest.txt');
		$values = json_decode($output, true);
		
		if($values['AUTHENTICATION'] == 'SUCCESSFUL'){
			$this->tellows->registered = _t('Connection Successful').' '._t('License Valid until').' '.$values['VALIDUNTIL'] ;
			$this->tellows->registered_bool = true;
			
		}elseif($values['AUTHENTICATION'] == 'FAILED'){			
			$this->tellows->registered = str_replace('$MESSAGE', $values['MESSAGE'], _t('<span style="color:red;">Connection Failure - API-Key not valid: $MESSAGE</span>'));
			$this->tellows->registered_bool = false;
		}else{
			$this->tellows->registered = _t('Could not check tellows Connection! Internet not available?');
			$this->tellows->registered_bool = false;
		}
		
		//fetch Timestamp of List and number of phonenumbers in blacklist
		$this->tellows->blacklist_date = strftime('%d-%m-%Y', (int)shell_exec('stat -L --format %Y /opt/callblocker/cache/tellows.csv'));
		$this->tellows->blacklist_entries = (int)shell_exec('cat /opt/callblocker/cache/tellows.csv | wc -l') - 1;
		return true;
	}
	
	private function _getLinphoneConf(){
		$this->linphone = new stdClass();
		$output = shell_exec('cat /opt/callblocker/linphone.conf');
		$tmp = explode('--', $output);		
		$this->linphone->host = trim(str_replace('host', '', $tmp[1]));
		$this->linphone->user = trim(str_replace('username', '', $tmp[2]));
		$this->linphone->password = trim(str_replace('password', '', $tmp[3]));

		if($this->linphone->host != '' && $this->linphone->user != ''){
			$this->linphone->running = $this->status('linphonec');
			$this->linphone->registered = $this->writeDynamicScript(array('linphonecsh generic "status registered"'));
			
			if(strpos($this->linphone->registered, 'registered=-1') !== FALSE || strpos($this->linphone->registered, 'registered=0') !== FALSE){
				$this->linphone->registered = _t('<span style="color:red;">Connection Failure - SIP not connected (check settings)</span>');
			}else{
				$this->linphone->registered = _t('Successfull Connected').' '.$this->linphone->registered;
			}
		}else{
			$this->linphone->registered = _t('SIP not configured');
		}
		return true;
	}
	
	private function _saveLinphoneConf(){
		$this->writeDynamicScript(array("echo '--host ".$_GET['linphone_host']." --username ".$_GET['linphone_user']." --password ".$_GET['linphone_password']."' > /opt/callblocker/linphone.conf"));
		//Restart Linphone Service
		$this->writeDynamicScript(array("linphonecsh generic 'soundcard use files';linphonecsh register $(cat /opt/callblocker/linphone.conf);sleep 2;chmod a+rw /dev/null;"));
		$this->view->message[] = _t('VOIP-Settings Updated');
		return true;
	}
	
	/**
	 * Update Max2Play-Plugin AND Settings under /opt/callblocker
	 * To extend Features for Callblocker in later Versions
	 */
	private function _updateCallblocker(){		
		$this->getCBVersion();
		//Check auf Update
		$file = file_get_contents('http://cdn.tellows.de/uploads/downloads/callblocker/currentversion/version.txt');
		if((float)$this->view->version < (float)$file || !$this->view->installed){
			$this->view->message[] = _t('Callblocker update started');
			//Start Script -> Download Files for Webserver and Scripts
			$shellanswer = $this->writeDynamicScript(array("/opt/max2play/update_callblocker.sh"));			
			$this->view->message[] = nl2br($shellanswer);
			if(strpos($shellanswer, 'inflating: /opt/callblocker/incoming.sh') !== FALSE)
				$this->view->message[] = _t('UPDATE SUCCESSFUL - Please Restart Device');
			else
				$this->view->message[] = _t('UPDATE NOT SUCCESSFUL');
		}else{
			$this->view->message[] = _t('Callblocker is up to date - no update required');
		}
	}

	private function modemConnected(){
		$out = shell_exec('if [ -e /dev/ttyACM0 ]; then echo "1"; else echo "0"; fi;');
		$this->view->modemconnected = $out;
		return true;
	}
	
	private function getAllLogs(){
		$out['NCIDD Restart'] = shell_exec('cat /opt/callblocker/cache/ncidd-restart.txt');
		$out['Blacklistevent Last Sync'] = shell_exec('cat /opt/callblocker/cache/blacklistevent_last.txt');
		$out['NCID Running'] = shell_exec('ps -Al | grep ncid');
		$out['NCID Version'] = shell_exec('/usr/sbin/ncidd -V 2>&1');
		$out['SIPPHONE Running'] = shell_exec('ps -Al | grep linphone');
		$out['LOAD AVERAGE'] = shell_exec('cat /proc/loadavg');
		$out['Button Blacklist'] = shell_exec('ps -Al | grep button');
		$out['CallerID LOG Last 10'] = shell_exec('tail -10 /var/log/cidcall.log');
		$out['tellows blacklist download'] = shell_exec('cat /opt/callblocker/cache/tellowsblacklist.txt');
		
		$this->view->debug = $out;
	}
	
	private function getCBVersion(){
		$this->view->version = shell_exec('cat /opt/callblocker/version.txt');
		
		//Check if CB /opt/callblocker/ is INSTALLED!!
		if(file_exists('/opt/callblocker/buttonblacklist.sh'))
			$this->view->installed = true;
		else{
			$this->view->message[] = _t('Callblocker is not installed - start installation by clicking on UPDATE button at the end of the page!');
		}
		
		return $this->view->version;
	}
		
}

$cs = new Callblocker_Setup();

include_once(dirname(__FILE__).'/../view/setup.php');

