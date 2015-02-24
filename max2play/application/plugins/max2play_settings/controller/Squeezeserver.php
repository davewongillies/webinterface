<?php 

/**
 Squeezeserver Administration Controller

 @Copyright 2014 Stefan Rick
 @author Stefan Rick
 Mail: stefan@rick-software.de
 Web: http://www.netzberater.de

 This program is free software; you can redistribute it and/or modify
 it under the terms of the GNU General Public License as published by
 the Free Software Foundation; either version 2 of the License, or
 (at your option) any later version.

 This program is distributed in the hope that it will be useful,
 but WITHOUT ANY WARRANTY; without even the implied warranty of
 MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 GNU General Public License for more details.

 You should have received a copy of the GNU General Public License along
 with this program; if not, write to the Free Software Foundation, Inc.,
 51 Franklin Street, Fifth Floor, Boston, MA 02110-1301 USA.
 */


class Squeezeserver extends Service {
	protected $pname = 'logitechmediaserver';
	protected $prozessname = 'squeezeboxserve';
	public $viewname = 'Squeezebox Server';
	public $lmsversions = array();
	
	public function __construct(){						
		parent::__construct();
		$this->pluginname = _('Squeezebox Server');
		
		if($_GET['ajax'] == 1 && $_GET['action'] == 'install'){
			//Function to get Progress of Installation
			$_GET['downloadurl'] = '';
			$this->installLMS();
			ob_end_clean();
			echo implode('<br />', $this->view->message);
			ob_flush();
			die();
		}
			
		if(isset($_GET['action'])){
			if($_GET['action'] == 'start'){			
				$this->view->message[] = $this->start($this->pname, '', $this->prozessname);	
				sleep(10); //Timeout to get process in processlist
			}
			
			if($_GET['action'] == 'stop'){			
				$this->view->message[] = $this->stop($this->pname, '', $this->prozessname);
				sleep(10); //Timeout to get process off processlist
			}
			
			if($_GET['action'] == 'save'){
				$this->selectAutostart(isset($_GET['autostart']) ? 1 : 0, false);
			}
			
			if($_GET['action'] == 'install'){
				$this->installLMS($_GET['lmsversion']);
			}
			
			if($_GET['action'] == 'showavailablelms'){
				$this->getLMSVersions();
			}
		}
		
		$this->view->installed = $this->checkInstall();
		$this->view->autostart = $this->checkAutostart($this->pname);
		$this->view->pid = $this->status($this->prozessname);
		$this->getAllLogs();
	}
	
	/**
	 * Due to the lizenz of LMS (not completly GPL) the user needs to start the installation manual
	 * Preinstalled LMS on images is not allowed
	 * This needs the sudoers.d rights for the script /opt/max2play/install_lms.sh
	 */
	public function installLMS($lmsversion = ''){		
		ignore_user_abort(true);
		set_time_limit(7200);
		$shellanswer = shell_exec("cat /opt/max2play/cache/install_lms.txt");
		if($shellanswer != ''){
			preg_match('=[0-9\: -]*=', $shellanswer, $started);
			//Use WGET Timestamp - install should never take more than 2 hours			
			//Check install_lms.txt for running installation!
			$lastmodified_seconds = trim(shell_exec("expr $(date +%s) - $(date +%s -r /opt/max2play/cache/install_lms.txt)"), "\n");
			if($lastmodified_seconds > 600 || (time() - 2*60*60) > strtotime(trim($started[0], '- '))){
				$this->view->message[] = _('Something went wrong in last Install Attempt - Deleting Progressfile');
				shell_exec("rm /opt/max2play/cache/install_lms.txt");
			}
			$shellanswer = preg_replace('=[0-9]{1,}s.*?[0-9]{1,}K[\. ]{10,}.*?[0-9]{1,}(M|K) =s', '', $shellanswer);
			$shellanswer = preg_replace('=([0-9]{1,}(m|s))*=s', '', $shellanswer);
			$this->view->message[] = nl2br(_('Installation Progress: ')."(startet ".$started[0].") ". $shellanswer);			
			return false;
		}else{
			if(!isset($this->lmsversions[$lmsversion]) && $_GET['downloadurl'] == ''){
				$this->view->message[] = _('LMS-Version not existing');
				$this->view->message[] = '<!-- finished -->';
				return false;
			}
			if($_GET['downloadurl'] != '' && strpos($_GET['downloadurl'], 'http://downloads.slimdevices.com') === 0){
				$downurl = $_GET['downloadurl'];
			}else{
				$downurl = $this->lmsversions[$lmsversion];
			}
			$shellanswer = shell_exec("sudo /opt/max2play/install_lms.sh update ".$downurl." > /dev/null &");
			$this->view->message[] = _('Installation startet - This Messages refreshes every 3 seconds to show current status of installation. If finished this message disappears.');
			$this->view->message[] = _('Installation Package: ').$downurl;			
			$this->view->message[] = '<div id="msgprogress"></div><script type="text/javascript">setTimeout(function(){reloadprogress("msgprogress", "/plugins/max2play_settings/controller/Squeezeserver.php", 1)}, 3000);</script>';
			return true;
		}
	}
	
	/**
	 * This needs the sudoers.d rights for the script /opt/max2play/install_lms.sh
	 * @return boolean
	 */
	public function checkInstall(){
		$shellanswer = shell_exec("sudo /opt/max2play/install_lms.sh check 2>/dev/null");
		if(strpos($shellanswer, 'installed=1') !== FALSE){
			return true;
		}else 
			return false;
	}
	
	/**
	 * Get all available LMS-Downloads for Debian
	 */
	public function getLMSVersions(){		
		$slimdevices_download = 'http://downloads.slimdevices.com/';
		$html = file_get_contents($slimdevices_download);
		
		//get Regular Builds
		$count = preg_match_all('/<td><a href="(LogitechMediaServer_v7.8[^"]+)">([^<]*)<\/a><\/td>/i', $html, $files);		
		for ($i = 0; $i < $count; ++$i) {
			$name = str_replace('_v','_',substr($files[1][$i], 0, strlen($files[1][$i]) -1));
			$this->lmsversions[substr($files[2][$i], 0, strlen($files[2][$i]) -1)] = $slimdevices_download.$files[1][$i].strtolower($name).'_all.deb';
		}
		
		//get Nightly Builds
		$html = file_get_contents($slimdevices_download.'nightly/');
		$count = preg_match_all('/<a href=([^>]+(7\.8|7\.9))>([^<]*)<\/a><br>/i', $html, $versions);
		for ($i = 0; $i < $count; ++$i) {			
			$html_version = file_get_contents($slimdevices_download.'nightly/'.$versions[1][$i]);			
			$found = preg_match_all('/<a href="(.*_all\.deb)">[^<]*<\/a>/i', $html_version, $html_link);
			if($found){
				$this->lmsversions['Nightly '.$versions[2][$i]] = $slimdevices_download.'nightly/'.$html_link[1][0];
			}
		}
		return true;
	}
	
	private function getAllLogs(){
		$out['SQUEEZESERVER LOG'] = shell_exec('cat /var/log/squeezeboxserver/server.log 2>/dev/null');		
		$this->view->debug = $out;
		return true;
	}
		
}

$sp = new Squeezeserver();
include_once(dirname(__FILE__).'/../view/squeezeserver.php');