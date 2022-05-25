<?php
namespace FreePBX\modules;
// vim: set ai ts=4 sw=4 ft=php expandtab:
//	License for all code of this FreePBX module can be found in the license file inside the module directory
//	Copyright 2014 Schmooze Com Inc.
//  Copyright 2018 Sangoma Technologies, Inc

/* $fp = fopen('Mouhabx.txt', 'w');
fwrite($fp, print_r($post, TRUE));
fclose($fp); */

/* Notes by Mouhab
1] rights on folders and files keep changing for the contents of folder MyProject under the blacklist module!!
   when this happens, the dialplan can't access the scripts and might find difference between ASTDB and GSMcall MySQL DB!! specially on Delete!
   test this plz.. --> this is solved by placing what i need to be executable under "agi-bin" folder under the module's folder */

class Blacklist implements \BMO {
	public function __construct($freepbx = null){
		if ($freepbx == null) {
			throw new \RuntimeException('Not given a FreePBX Object');
		}
		$this->FreePBX = $freepbx;
		$this->astman = $this->FreePBX->astman;

		if (false) {
			_('Blacklist a number');
			_('Remove a number from the blacklist');
			_('Blacklist the last caller');
			_('Blacklist');
			_('Adds a number to the Blacklist Module.  All calls from that number to the system will receive a disconnect recording.  Manage these in the Blacklist module.');
			_('Removes a number from the Blacklist Module');
			_('Adds the last caller to the Blacklist Module.  All calls from that number to the system will receive a disconnect recording.');
		}
	}
	public function ajaxRequest($req, &$setting){
		switch ($req) {
			case 'add':
			case 'edit':
			case 'del':
			case 'bulkdelete':
			case 'getJSON':
			case 'calllog':
			return true;
			break;
		}
		return false;
	}

	public function chownFreepbx() {
		// https://wiki.freepbx.org/pages/viewpage.action?pageId=34340984 (reference)
		$webroot = \FreePBX::Config()->get('AMPWEBROOT');
		$modulesdir = $webroot . '/admin/modules/';
		echo $modulesdir;
		$files = array();
		$files[] = array('type' => 'dir',
						'path' => $modulesdir . '/blacklist/',
						'perms' => 0755);
		$files[] = array('type' => 'file',
						'path' => $modulesdir . '/blacklist/agi-bin/blklp.php',
						'perms' => 0755);
		$files[] = array('type' => 'file',
						'path' => $modulesdir . '/blacklist/agi-bin/getExtName.php',
						'perms' => 0755);
		$files[] = array('type' => 'file',
						'path' => $modulesdir . '/blacklist/agi-bin/mail_gui.php',
						'perms' => 0755);
		$files[] = array('type' => 'file',
						'path' => $modulesdir . '/blacklist/agi-bin/maildial.php',
						'perms' => 0755);
		return $files;
	}


	private function gsmcall_sendMail($request, $mode){
	
		if($this->gsmcall_emailGet() !="") {

			$url = $_SERVER['HTTP_ORIGIN'] . '/admin/modules/blacklist/agi-bin/mail_gui.php'; // you need to authorize mail_gui.php inside .htaccess file under /admin folder
			// what post fields?
			
			switch ($mode) {
				case 'delete':
					$fields = array();
					$fields ['mode'] = "delete";
					$fields ['number'] = $request['tn'];
					$fields ['description'] = $request['de'];
					$fields ['timestamp'] = $request['ts'];
					$fields ['addedby'] = $request['ab'];
					$fields ['addedvia'] = $request['mu'];
				break;
				case 'bulk':
					date_default_timezone_set("America/New_York");										
					$fields = array();
					$fields ['mode'] = "Bulk Add";
					$fields ['number'] = $request['number'];
					$fields ['description'] = $request['description'];
					$fields ['timestamp'] = date("m/d/Y") . ' @ ' . date("h:i:sa");
					$fields ['addedby'] = "GUI User";
					$fields ['addedvia'] = "Bulk Add";
					$val = $this->gsmcall_checkNum($request['number']);
					if ((int)$val == 1) {
						// This is a record update for sure, then change the details to reflect the original data @ block time.
						$sql = "SELECT * from gsmcall.blacklist where tn='" . $request['number'] . "' LIMIT 1";
						$oldRec = sql($sql, 'getAll', DB_FETCHMODE_ASSOC);
						$fields ['mode'] = "edit";
						$fields ['timestamp'] = $oldRec[0]['ts'];
						$fields ['addedby'] = $oldRec[0]['ab'];
						$fields ['addedvia'] = "Bulk Modify";
					}
				break;
				default:
					$request ['mode'] = $mode;
					$fields = $request;
				break;
			}
			
			$fields ['toMail'] = $this->gsmcall_emailGet();
			// Storing the PBX name in the array
			$info = parse_url($url);
			$host = $info['host'];
			$host = explode(".", $info['host']);
			$fields ['host'] = ucfirst($host[0]);
			
			// build the urlencoded data
			$postvars = http_build_query($fields);

			// open connection
			$ch = curl_init();

			// set the url, number of POST vars, POST data
			curl_setopt($ch, CURLOPT_URL, $url);
			curl_setopt($ch, CURLOPT_FAILONERROR, true);
			curl_setopt($ch, CURLOPT_POST, count($fields));
			curl_setopt($ch, CURLOPT_POSTFIELDS, $postvars);

			// execute post
			$result = curl_exec($ch);
			//trap Curl Errors...
			if (curl_errno($ch)) {
				$error_msg = curl_error($ch);
			}

			if (isset($error_msg)) {
				$fp = fopen('cUrlError.txt', 'w'); // how to write to a specific directory??
				fwrite($fp, print_r($error_msg, TRUE));
				fclose($fp);
			}
			// close connection
			curl_close($ch);	
		}
	}

	public function ajaxHandler(){

		$request = $_REQUEST;
		
		if(!empty($_REQUEST['oldval']) && $_REQUEST['command'] == 'add' ){
			$_REQUEST['command'] = 'edit';
		}
		switch ($_REQUEST['command']) {
			case 'add':
				$this->gsmcall_sendMail($request, "add"); 
				// $request here doesn't have timestamp and we will handle this inside the gsmcall_numberAdd() according to
				// $mode sent.
				$this->gsmcall_numberAdd($request,"add");
				$this->numberAdd($request);
				return array('status' => true);
			break;
			case 'edit':
				$removedRecord = $this->gsmcall_numberDel($request['oldval']);
				//adding the original timestamp to the values passed to gsmcall_sendMail() so as we can 
				// preserve the orignal time stamp upon callerID record updates.
				$request['timestamp'] = $removedRecord[0]['ts']; 
				$request['addedby'] = $removedRecord[0]['ab'];
				$request['addedvia'] = $removedRecord[0]['mu'];
				
				$this->numberDel($request['oldval']);
				$this->gsmcall_numberAdd($request,"edit");
				$this->numberAdd($request);
				$this->gsmcall_sendMail($request, "edit");
				return array('status' => true); 
			break;
			case 'bulkdelete':
				$numbers = isset($_REQUEST['numbers'])?$_REQUEST['numbers']:array();
				$numbers = json_decode($numbers, true);
				foreach ($numbers as $number) {
					// Send email upon delete
					$removedRecord = $this->gsmcall_numberDel($number);
					// transform $number to an array holding old values
					$this->gsmcall_sendMail($removedRecord[0],"delete");
					$this->numberDel($number);
				}
				return array('status' => 'true', 'message' => _("Numbers Deleted"));
			break;
			case 'del':
				//send email upon delete
				$removedRecord = $this->gsmcall_numberDel($request['number']);
				// transform $number to an array holding old values
				$this->gsmcall_sendMail($removedRecord[0],"delete");
				$ret = $this->numberDel($request['number']);
				return array('status' => $ret);
			break;
			case 'calllog':
				$number = $request['number'];
				$sql = 'SELECT calldate FROM asteriskcdrdb.cdr WHERE src = ?';
				$stmt = \FreePBX::Database()->prepare($sql);
				$stmt->execute(array($number));
				$ret = $stmt->fetchAll(\PDO::FETCH_ASSOC);
				return $ret;
			break;
			case 'getJSON':
			// this is where the loading and refresh happens of the table contents
			switch($request['jdata']){
				case 'grid':
					$ret = array();
					$moh=0;
					$blacklist = $this->getBlacklist();
					$gsmcall_blacklist = $this->gsmcall_getBlacklist();
					foreach($blacklist as $item){
						$number = $item['number'];
						$description = $item['description'];
						if($number == 'dest' || $number == 'blocked'){
							continue;
						}else{
// so as someone by mistake adds a number usnig the system..
							$gsmcall_blacklist[$moh]['ts'] = isset($gsmcall_blacklist[$moh]['ts']) ? $gsmcall_blacklist[$moh]['ts'] : '';
							$gsmcall_blacklist[$moh]['ab'] = isset($gsmcall_blacklist[$moh]['ab']) ? $gsmcall_blacklist[$moh]['ab'] : '';;
							$gsmcall_blacklist[$moh]['mu'] = isset($gsmcall_blacklist[$moh]['mu']) ? $gsmcall_blacklist[$moh]['mu'] : '';
							$gsmcall_blacklist[$moh]['de'] = isset($gsmcall_blacklist[$moh]['de']) ? $gsmcall_blacklist[$moh]['de'] : '';
							$ret[] = array('number' => $number, 'description' => $gsmcall_blacklist[$moh]['de'], 'timestamp' => $gsmcall_blacklist[$moh]['ts'], 'addedby' => $gsmcall_blacklist[$moh]['ab'], 'addedvia' => $gsmcall_blacklist[$moh]['mu']);
							$moh++;
						}
					}
				return $ret;
				break;
			}
			break;
		}
	}

	//BMO Methods
	public function install() {
		$fcc = new \featurecode('blacklist', 'blacklist_add');
		$fcc->setDescription('Blacklist a number');
		$fcc->setHelpText('Adds a number to the Blacklist Module.  All calls from that number to the system will receive a disconnect recording.  Manage these in the Blacklist module.');
		$fcc->setDefault('*30');
		$fcc->setProvideDest();
		$fcc->update();
		unset($fcc);

		$fcc = new \featurecode('blacklist', 'blacklist_remove');
		$fcc->setDescription('Remove a number from the blacklist');
		$fcc->setHelpText('Removes a number from the Blacklist Module');
		$fcc->setDefault('*31');
		$fcc->setProvideDest();
		$fcc->update();
		unset($fcc);
		
		$fcc = new \featurecode('blacklist', 'blacklist_last');
		$fcc->setDescription('Blacklist the last caller');
		$fcc->setHelpText('Adds the last caller to the Blacklist Module.  All calls from that number to the system will receive a disconnect recording.');
		$fcc->setDefault('*32');
		$fcc->update();
		unset($fcc);
		
		$fcc = new \featurecode('blacklist', 'blacklist_blf');
		$fcc->setDescription('In call Blacklisting using Dial Plan');
		$fcc->setHelpText('Adds the current caller during a call to the Blacklist. Then divert the call to the destination set in the blacklist settings tab.');
		$fcc->setDefault('*33');
		$fcc->setProvideDest();
		$fcc->update();
		unset($fcc);
	}
	public function uninstall(){}

	public function backup(){}

	public function restore($backup){}

	public function doConfigPageInit($page) {
		$dispnum = 'blacklist';
		$astver = $this->FreePBX->Config->get('ASTVERSION');
		$request = $_REQUEST;

		if (isset($request['goto0'])) {
			$destination = $request[$request['goto0'].'0'];
		}
		isset($request['action']) ? $action = $request['action'] : $action = '';
		isset($request['oldval']) ? $action = 'edit' : $action;
		isset($request['number']) ? $number = $request['number'] : $number = '';
		isset($request['description']) ? $description = $request['description'] : $description = '';
		isset($request['email']) ? $emailAlt = $request['email'] : $emailAlt = '';
		
		if (isset($request['action'])) {
			switch ($action) {
				case 'settings':
					$this->gsmcall_emailSet($emailAlt);
					$this->destinationSet($destination);
					$this->blockunknownSet($request['blocked']);
				break;
				case 'import':
					if ($_FILES['file']['error'] > 0) {
						echo '<div class="alert alert-danger" role="alert">'._('There was an error uploading the file').'</div>';
					} else {
						if (pathinfo($_FILES['blacklistfile']['name'], PATHINFO_EXTENSION) == 'csv') {
							$path = sys_get_temp_dir().'/'.$_FILES['blacklistfile']['name'];
							move_uploaded_file($_FILES['blacklistfile']['tmp_name'], $path);
							if (file_exists($path)) {
								ini_set('auto_detect_line_endings', true);
								$handle = fopen($path, 'r');
								set_time_limit(0);
								while (($data = fgetcsv($handle)) !== false) {
									if ($data[0] == 'number' && $data[1] == 'description') {
										continue;
									}
									blacklist_add(array(
										'number' => $data[0],
										'description' => $data[1],
										'blocked' => 0,
									));
								}
								unlink($path);
								echo '<div class="alert alert-success" role="alert">'._('Sucessfully imported all entries').'</div>';
							} else {
								echo '<div class="alert alert-danger" role="alert">'._('Could not find file after upload').'</div>';
							}
						} else {
							echo '<div class="alert alert-danger" role="alert">'._('The file must be in CSV format!').'</div>';
						}
					}
				break;
				case 'export':
					$list = $this->getBlacklist();
					if (!empty($list)) {
						header('Content-Type: text/csv; charset=utf-8');
						header('Content-Disposition: attachment; filename=blacklist.csv');
						$output = fopen('php://output', 'w');
						fputcsv($output, array('number', 'description'));
						foreach ($list as $l) {
							fputcsv($output, $l);
						}
					} else {
						header('HTTP/1.0 404 Not Found');
						echo _('No Entries to export');
					}
					die();
				break;
			}
		}
	}

	public function myDialplanHooks(){
		return 400;
	}

	public function doDialplanHook(&$ext, $engine, $priority) {
		$modulename = 'blacklist';
		//Add
		$fcc = new \featurecode($modulename, 'blacklist_add');
		$addfc = $fcc->getCodeActive();
		unset($fcc);
		//Delete
		$fcc = new \featurecode($modulename, 'blacklist_remove');
		$delfc = $fcc->getCodeActive();
		unset($fcc);
		//Last
		$fcc = new \featurecode($modulename, 'blacklist_last');
		$lastfc = $fcc->getCodeActive();
		unset($fcc);
		//blf
		$fcc = new \featurecode($modulename, 'blacklist_blf');
		$blffc = $fcc->getCodeActive();
		unset($fcc);
		
		
		// The application itself.
		$id = 'app-blacklist';
		$c = 's';
		$ext->addInclude('from-internal-additional', $id); // Add the include from from-internal
		$ext->add($id, $c, '', new \ext_macro('user-callerid'));
			
		
		$id = 'app-blacklist-check';
		// LookupBlackList doesn't seem to match empty astdb entry for "blacklist/", so we
		// need to check for the setting and if set, send to the blacklisted area
		// The gotoif below is not a typo.  For some reason, we've seen the CID number set to Unknown or Unavailable
		// don't generate the dialplan if they are not using the function
		//
		if ($this->astman->database_get('blacklist', 'blocked') == '1') {
			$ext->add($id, $c, '', new \ext_gotoif('$["${CALLERID(number)}" = "Unknown"]', 'check-blocked'));
			$ext->add($id, $c, '', new \ext_gotoif('$["${CALLERID(number)}" = "Unavailable"]', 'check-blocked'));
			$ext->add($id, $c, '', new \ext_gotoif('$["foo${CALLERID(number)}" = "foo"]', 'check-blocked', 'check'));
			$ext->add($id, $c, 'check-blocked', new \ext_gotoif('$["${DB(blacklist/blocked)}" = "1"]', 'blacklisted'));
		}

		$ext->add($id, $c, 'check', new \ext_gotoif('$["${BLACKLIST()}"="1"]', 'blacklisted'));
		$ext->add($id, $c, '', new \ext_setvar('CALLED_BLACKLIST', '1'));
		$ext->add($id, $c, '', new \ext_return(''));
		$ext->add($id, $c, 'blacklisted', new \ext_answer(''));
		$ext->add($id, $c, '', new \ext_set('BLDEST', '${DB(blacklist/dest)}'));
		$ext->add($id, $c, '', new \ext_execif('$["${BLDEST}"=""]', 'Set', 'BLDEST=app-blackhole,hangup,1'));
		$ext->add($id, $c, '', new \ext_gotoif('$["${returnhere}"="1"]', 'returnto'));
		$ext->add($id, $c, '', new \ext_gotoif('${LEN(${BLDEST})}', '${BLDEST}', 'app-blackhole,zapateller,1'));
		$ext->add($id, $c, 'returnto', new \ext_return());
		/*
		$ext->add($id, $c, '', new \ext_wait(1));
		$ext->add($id, $c, '', new \ext_zapateller(''));
		$ext->add($id, $c, '', new \ext_playback('ss-noservice'));
		$ext->add($id, $c, '', new \ext_hangup(''));
		$modulename = 'blacklist';
		*/

		
		
		//Dialplan for add
		if(!empty($addfc)){
			$ext->add('app-blacklist', $addfc, '', new \ext_goto('1', 's', 'app-blacklist-add'));
		}
		
		$id = 'app-blacklist-add';
		$c = 's';
		$ext->add($id, $c, '', new \ext_answer());
		$ext->add($id, $c, '', new \ext_macro('user-callerid'));
		$ext->add($id, $c, '', new \ext_wait(1));
		$ext->add($id, $c, '', new \ext_set('NumLoops', 0));
		$ext->add($id, $c, 'start', new \ext_digittimeout(5));
		$ext->add($id, $c, '', new \ext_responsetimeout(10));
		$ext->add($id, $c, '', new \ext_read('blacknr', 'enter-num-blacklist&vm-then-pound'));
		$ext->add($id, $c, '', new \ext_saydigits('${blacknr}'));
		// i18n - Some languages need this is a different format. If we don't
		// know about the language, assume english
		$ext->add($id, $c, '', new \ext_gosubif('$[${DIALPLAN_EXISTS('.$id.',${CHANNEL(language)})}]', $id.',${CHANNEL(language)},1', $id.',en,1'));
		// en - default
		$ext->add($id, 'en', '', new \ext_digittimeout(1));
		$ext->add($id, 'en', '', new \ext_read('confirm','if-correct-press&digits/1&to-enter-a-diff-number&press&digits/2'));
		$ext->add($id, 'en', '', new \ext_return());
		// ja
		$ext->add($id, 'ja', '', new \ext_digittimeout(1));
		$ext->add($id, 'ja', '', new \ext_read('if-correct-press&digits/1&pleasepress'));
		$ext->add($id, 'ja', '', new \ext_return());

		$ext->add($id, $c, '', new \ext_gotoif('$[ "${confirm}" = "1" ]','app-blacklist-add,1,1'));
		$ext->add($id, $c, '', new \ext_gotoif('$[ "${confirm}" = "2" ]','app-blacklist-add,2,1'));
		$ext->add($id, $c, '', new \ext_goto('app-blacklist-add-invalid,s,1'));

		$c = '1';
		$ext->add($id, $c, '', new \ext_gotoif('$[ "${blacknr}" != ""]', '', 'app-blacklist-add-invalid,s,1'));
		$ext->add($id, $c, '', new \ext_set('DB(blacklist/${blacknr})', 1));
		//By Mouhab
		$ext->add($id, $c, '', new \ext_set('blker', '${CALLERID(number)}-${CALLERID(name)}'));
		$ext->add($id, $c, '', new \ext_set('CIDSFSCHEME', 'QUxMfEFMTA=='));
		$ext->add($id, $c, '', new \ext_set('temp1', '${CALLERID(name)}'));
		$ext->add($id, $c, '', new \ext_set('CALLERID(name)', ''));
		$ext->add($id, $c, '', new \ext_set('temp2', '${CALLERID(number)}'));
		$ext->add($id, $c, '', new \ext_set('CALLERID(number)', '${blacknr}'));
		$ext->add($id, $c, '', new \ext_AGI('/var/www/html/admin/modules/superfecta/agi/superfecta.agi'));
		$ext->add($id, $c, '', new \ext_set('CALLERID(name)', '${temp1}'));
		$ext->add($id, $c, '', new \ext_set('CALLERID(number)', '${temp2}'));
		$ext->add($id, $c, '', new \ext_set('DIAL_NUM', '30'));
		$ext->add($id, $c, '', new \ext_AGI('/var/www/html/admin/modules/blacklist/agi-bin/blklp.php,${blacknr},${blker},${DIAL_NUM},${lookupcid}'));
		$ext->add($id, $c, '', new \ext_set('EMAILTO', '${DB(GSMblacklist/email)}'));
		$ext->add($id, $c, '', new \ext_noop('${STRREPLACE(lookupcid,","," ")}'));
		$ext->add($id, $c, '', new \ext_wait(1));
		$ext->add($id, $c, '', new \ext_AGI('/var/www/html/admin/modules/blacklist/agi-bin/maildial.php,${blacknr},${blker},${DIAL_NUM},${EMAILTO},${lookupcid}'));
		// End By Mouhab
		$ext->add($id, $c, '', new \ext_playback('num-was-successfully&added'));
		$ext->add($id, $c, '', new \ext_wait(1));
		$ext->add($id, $c, '', new \ext_hangup());

        $c = '2';
        $ext->add($id, $c, '', new \ext_set('NumLoops', '$[${NumLoops} + 1]'));
        $ext->add($id, $c, '', new \ext_gotoif('$[${NumLoops} < 3]', 'app-blacklist-add,s,start'));
        $ext->add($id, $c, '', new \ext_playback('sorry-youre-having-problems&goodbye'));
        $ext->add($id, $c, '', new \ext_hangup());


		$id = 'app-blacklist-add-invalid';
		$c = 's';
		$ext->add($id, $c, '', new \ext_set('NumLoops', '$[${NumLoops} + 1]'));
		$ext->add($id, $c, '', new \ext_playback('pm-invalid-option'));
		$ext->add($id, $c, '', new \ext_gotoif('$[${NumLoops} < 3]', 'app-blacklist-add,s,start'));
		$ext->add($id, $c, '', new \ext_playback('sorry-youre-having-problems&goodbye'));
		$ext->add($id, $c, '', new \ext_hangup());

		//Del
		if(!empty($delfc)){
			$ext->add('app-blacklist', $delfc, '', new \ext_goto('1', 's', 'app-blacklist-remove'));
		}
		$id = 'app-blacklist-remove';
		$c = 's';
		$ext->add($id, $c, '', new \ext_answer());
		$ext->add($id, $c, '', new \ext_macro('user-callerid'));
		$ext->add($id, $c, '', new \ext_set('NumLoops', 0));
        $ext->add($id, $c, '', new \ext_wait(1));
		$ext->add($id, $c, 'start', new \ext_digittimeout(5));
		$ext->add($id, $c, '', new \ext_responsetimeout(10));
		$ext->add($id, $c, '', new \ext_read('blacknr', 'entr-num-rmv-blklist&vm-then-pound'));
		$ext->add($id, $c, '', new \ext_saydigits('${blacknr}'));
		// i18n - Some languages need this is a different format. If we don't
		// know about the language, assume english
		$ext->add($id, $c, '', new \ext_gosubif('$[${DIALPLAN_EXISTS('.$id.',${CHANNEL(language)})}]', $id.',${CHANNEL(language)},1', $id.',en,1'));
		// en - default
		$ext->add($id, 'en', '', new \ext_digittimeout(1));
		$ext->add($id, 'en', '', new \ext_read('confirm','if-correct-press&digits/1&to-enter-a-diff-number&press&digits/2'));
		$ext->add($id, 'en', '', new \ext_return());
		// ja
		$ext->add($id, 'ja', '', new \ext_digittimeout(1));
		$ext->add($id, 'ja', '', new \ext_read('confirm','if-correct-press&digits/1&pleasepress'));
		$ext->add($id, 'ja', '', new \ext_return());

		$ext->add($id, $c, '', new \ext_gotoif('$[ "${confirm}" = "1" ]','app-blacklist-remove,1,1'));
	    $ext->add($id, $c, '', new \ext_gotoif('$[ "${confirm}" = "2" ]','app-blacklist-remove,2,1'));
	    $ext->add($id, $c, '', new \ext_goto('app-blacklist-add-invalid,s,1'));

		$c = '1';
		$ext->add($id, $c, '', new \ext_dbdel('blacklist/${blacknr}'));
		//By Mouhab
		$ext->add($id, $c, '', new \ext_set('DIAL_NUM', '31'));
		$ext->add($id, $c, '', new \ext_set('blker', '${CALLERID(number)}-${CALLERID(name)}'));
		$ext->add($id, $c, '', new \ext_set('EMAILTO', '${DB(GSMblacklist/email)}'));
		$ext->add($id, $c, '', new \ext_AGI('/var/www/html/admin/modules/blacklist/agi-bin/blklp.php,${blacknr},${CALLERID(number)},${DIAL_NUM},${EMAILTO}'));
		$ext->add($id, $c, '', new \ext_noop('-------- The variable [blkts] & [blkab] were set inside blklp.php ------------'));
		$ext->add($id, $c, '', new \ext_set('CIDSFSCHEME', 'QUxMfEFMTA=='));
		$ext->add($id, $c, '', new \ext_set('temp1', '${CALLERID(name)}'));
		$ext->add($id, $c, '', new \ext_set('CALLERID(name)', ''));
		$ext->add($id, $c, '', new \ext_set('temp2', '${CALLERID(number)}'));
		$ext->add($id, $c, '', new \ext_set('CALLERID(number)', '${blacknr}'));		
		$ext->add($id, $c, '', new \ext_AGI('/var/www/html/admin/modules/superfecta/agi/superfecta.agi'));		
		$ext->add($id, $c, '', new \ext_set('CALLERID(name)', '${temp1}'));
		$ext->add($id, $c, '', new \ext_set('CALLERID(number)', '${temp2}'));		
		$ext->add($id, $c, '', new \ext_noop('${STRREPLACE(lookupcid,","," ")}'));
		$ext->add($id, $c, '', new \ext_wait(1));
		$ext->add($id, $c, '', new \ext_AGI('/var/www/html/admin/modules/blacklist/agi-bin/maildial.php,${blacknr},${blker},${DIAL_NUM},${EMAILTO},${lookupcid},${blkts},${blkab}'));
		// End By Mouhab
		$ext->add($id, $c, '', new \ext_playback('num-was-successfully&removed'));
		$ext->add($id, $c, '', new \ext_wait(1));
		$ext->add($id, $c, '', new \ext_hangup());

        $c = '2';
        $ext->add($id, $c, '', new \ext_set('NumLoops', '$[${NumLoops} + 1]'));
        $ext->add($id, $c, '', new \ext_gotoif('$[${NumLoops} < 3]', 'app-blacklist-remove,s,start'));
        $ext->add($id, $c, '', new \ext_playback('goodbye'));
        $ext->add($id, $c, '', new \ext_hangup());


        $id = 'app-blacklist-remove-invalid';
        $c = 's';
        $ext->add($id, $c, '', new \ext_set('NumLoops', '$[${NumLoops} + 1]'));
        $ext->add($id, $c, '', new \ext_playback('pm-invalid-option'));
        $ext->add($id, $c, '', new \ext_gotoif('$[${NumLoops} < 3]', 'app-blacklist-remove,s,start'));
        $ext->add($id, $c, '', new \ext_playback('sorry-youre-having-problems&goodbye'));
        $ext->add($id, $c, '', new \ext_hangup());

        //Last
		if(!empty($lastfc)){
			$ext->add('app-blacklist', $lastfc, '', new \ext_goto('1', 's', 'app-blacklist-last'));
		}
		$id = 'app-blacklist-last';
		$c = 's';
		$ext->add($id, $c, '', new \ext_answer());
		$ext->add($id, $c, '', new \ext_macro('user-callerid'));
		$ext->add($id, $c, '', new \ext_wait(1));
		$ext->add($id, $c, '', new \ext_setvar('lastcaller', '${DB(CALLTRACE/${AMPUSER})}'));
		$ext->add($id, $c, '', new \ext_gotoif('$[ $[ "${lastcaller}" = "" ] | $[ "${lastcaller}" = "unknown" ] ]', 'noinfo'));
		$ext->add($id, $c, '', new \ext_playback('privacy-to-blacklist-last-caller&telephone-number'));
		$ext->add($id, $c, '', new \ext_saydigits('${lastcaller}'));
		$ext->add($id, $c, '', new \ext_setvar('TIMEOUT(digit)', '1'));
		$ext->add($id, $c, '', new \ext_setvar('TIMEOUT(response)', '7'));
		// i18n - Some languages need this is a different format. If we don't
		// know about the language, assume english
		$ext->add($id, $c, '', new \ext_gosubif('$[${DIALPLAN_EXISTS('.$id.',${CHANNEL(language)})}]', $id.',${CHANNEL(language)},1', $id.',en,1'));
		// en - default
		$ext->add($id, 'en', '', new \ext_read('confirm','if-correct-press&digits/1'));
		$ext->add($id, 'en', '', new \ext_return());
		// ja
		$ext->add($id, 'ja', '', new \ext_read('confirm','if-correct-press&digits/1&pleasepress'));
		$ext->add($id, 'ja', '', new \ext_return());

		$ext->add($id, $c, '', new \ext_gotoif('$[ "${confirm}" = "1" ]','app-blacklist-last,1,1'));
		$ext->add($id, $c, '', new \ext_goto('end'));
		$ext->add($id, $c, 'noinfo', new \ext_playback('unidentified-no-callback'));
		$ext->add($id, $c, '', new \ext_hangup());
		$ext->add($id, $c, '', new \ext_noop('Waiting for input'));
		$ext->add($id, $c, 'end', new \ext_playback('sorry-youre-having-problems&goodbye'));
		$ext->add($id, $c, '', new \ext_hangup());

		$c = '1';
		$ext->add($id, $c, '', new \ext_set('DB(blacklist/${lastcaller})', 1));
		//By Mouhab
		$ext->add($id, $c, '', new \ext_set('blker', '${CALLERID(number)}-${CALLERID(name)}'));
		$ext->add($id, $c, '', new \ext_set('CIDSFSCHEME', 'QUxMfEFMTA=='));
		$ext->add($id, $c, '', new \ext_set('temp1', '${CALLERID(name)}'));
		$ext->add($id, $c, '', new \ext_set('CALLERID(name)', ''));
		$ext->add($id, $c, '', new \ext_set('temp2', '${CALLERID(number)}'));
		$ext->add($id, $c, '', new \ext_set('CALLERID(number)', '${lastcaller}'));
		$ext->add($id, $c, '', new \ext_AGI('/var/www/html/admin/modules/superfecta/agi/superfecta.agi'));
		$ext->add($id, $c, '', new \ext_set('CALLERID(name)', '${temp1}'));
		$ext->add($id, $c, '', new \ext_set('CALLERID(number)', '${temp2}'));
		$ext->add($id, $c, '', new \ext_set('DIAL_NUM', '32'));
		$ext->add($id, $c, '', new \ext_noop('${STRREPLACE(lookupcid,","," ")}'));
		$ext->add($id, $c, '', new \ext_AGI('/var/www/html/admin/modules/blacklist/agi-bin/blklp.php,${lastcaller},${blker},${DIAL_NUM},${lookupcid}'));
		$ext->add($id, $c, '', new \ext_set('EMAILTO', '${DB(GSMblacklist/email)}'));
		$ext->add($id, $c, '', new \ext_wait(1));
		$ext->add($id, $c, '', new \ext_AGI('/var/www/html/admin/modules/blacklist/agi-bin/maildial.php,${lastcaller},${blker},${DIAL_NUM},${EMAILTO},${lookupcid}'));
		// End By Mouhab
		$ext->add($id, $c, '', new \ext_playback('num-was-successfully'));
		$ext->add($id, $c, '', new \ext_playback('added'));
		$ext->add($id, $c, '', new \ext_wait(1));
		$ext->add($id, $c, '', new \ext_hangup());

		$ext->add($id, 'i', '', new \ext_playback('sorry-youre-having-problems&goodbye'));
		$ext->add($id, 'i', '', new \ext_hangup());
		
		
		//Dialplan for blf
		if(!empty($blffc)){
			$ext->add('app-blacklist', $blffc, '', new \ext_goto('1', 's', 'app-blacklist-blf'));
		}
		
		$id = 'app-blacklist-blf';
		$c = 's';
		// By Mouhaaaaaaaaaaaaaab
		$ext->add($id, $c, '', new \ext_answer());
		$ext->add($id, $c, '', new \ext_AGI('/var/www/html/admin/modules/blacklist/agi-bin/getExtName.php,${DEXTEN}'));
		$ext->add($id, $c, '', new \ext_set('blker', '${DEXTEN}-${ext_nam}'));
		$ext->add($id, $c, '', new \ext_set('DB(blacklist/${CALLERID(number)})', 1));
		$ext->add($id, $c, '', new \ext_set('CIDSFSCHEME', 'QUxMfEFMTA=='));
		$ext->add($id, $c, '', new \ext_AGI('/var/www/html/admin/modules/superfecta/agi/superfecta.agi'));
		$ext->add($id, $c, '', new \ext_set('DIAL_NUM', '33'));
		$ext->add($id, $c, '', new \ext_noop('${STRREPLACE(lookupcid,","," ")}'));
		$ext->add($id, $c, '', new \ext_AGI('/var/www/html/admin/modules/blacklist/agi-bin/blklp.php,${CALLERID(number)},${blker},${DIAL_NUM},${lookupcid}'));
		$ext->add($id, $c, '', new \ext_set('EMAILTO', '${DB(GSMblacklist/email)}'));
		$ext->add($id, $c, '', new \ext_AGI('/var/www/html/admin/modules/blacklist/agi-bin/maildial.php,${CALLERID(number)},${blker},${DIAL_NUM},${EMAILTO},${lookupcid}'));
		$ext->add($id, $c, '', new \ext_wait(1));
		
		$ext->add($id, $c, '', new \ext_gotoif('$[ $[ "${CALLERID(number)}" = "" ] | $[ "${CALLERID(number)}" = "unknown" ] ]', 'noinfo'));
		$ext->add($id, $c, 'check', new \ext_gotoif('$["${BLACKLIST()}"="1"]', 'blacklisted'));
		$ext->add($id, $c, 'blacklisted', new \ext_set('BLDEST', '${DB(blacklist/dest)}'));
		$ext->add($id, $c, '', new \ext_gotoif('${LEN(${BLDEST})}', '${BLDEST}:app-blackhole,zapateller,1'));
		$ext->add($id, $c, '', new \ext_hangup());
		$ext->add($id, i,'', new \ext_hangup());
		
		// End By Mouhaaaaaaaaaab
	}

	public function getActionBar($request) {
		$buttons = array();
		switch ($request['display']) {
			case 'blacklist':
			$buttons = array(
				'reset' => array(
					'name' => 'reset',
					'id' => 'Reset',
					'class' => 'hidden',
					'value' => _('Reset'),
				),
				'submit' => array(
					'name' => 'submit',
					'class' => 'hidden',
					'id' => 'Submit',
					'value' => _('Submit'),
				),
			);

			return $buttons;
			break;
		}
	}

	//Blacklist Methods
	public function showPage() {
		$blacklistitems = $this->getBlacklist();
		$destination = $this->destinationGet();
		$filter_blocked = $this->blockunknownGet() == 1 ? true : false;
		$emailAlert = $this->gsmcall_emailGet();
		$view = isset($_REQUEST['view'])?$_REQUEST['view']:'';
		switch ($view) {
			case 'grid':
			return load_view(__DIR__.'/views/blgrid.php', array('blacklist' => $blacklistitems));
			break;
			default:
			return load_view(__DIR__.'/views/general.php', array('email' => $emailAlert,'blacklist' => $blacklistitems, 'destination' => $destination, 'filter_blocked' => $filter_blocked));
			break;
		}
	}

	/**
	 * Method code by Mouhab to get data from DB "gsmcall.blacklist"
	 * @return array Black listed numbers
	 */
	public function gsmcall_getBlacklist() {
		if ($this->astman->connected()) {
			// Get all records from table
			$sql = "SELECT * from gsmcall.blacklist";
			$result = sql($sql, 'getAll', DB_FETCHMODE_ASSOC);
			return $result;
		} else {
			throw new \RuntimeException('Cannot connect to Asterisk Manager, is Asterisk running?');
		}
	}

	/**
	 * Get lists
	 * @return array Black listed numbers
	 */
	public function getBlacklist() {
		if ($this->astman->connected()) {
			$list = $this->astman->database_show('blacklist');
			$blacklisted = array();
			foreach ($list as $k => $v) {
				$numbers = substr($k, 11);
				$blacklisted[] = array('number' => $numbers, 'description' => $v);
			}
			return $blacklisted;
		} else {
			throw new \RuntimeException('Cannot connect to Asterisk Manager, is Asterisk running?');
		}
	}

	/**
	 * Method code by Mouhab to insert data from Ajax to DB "gsmcall.blacklist"
	 * Add Number
	 * @param  array $post Array of blacklist params
	 */
	public function gsmcall_numberAdd($post, $mode){

		date_default_timezone_set("America/New_York");				
		$values = array ();
		$myTimeStamp = date("m/d/Y") . ' @ ' . date("h:i:sa");
		
		if ($mode == "bulk"){
			// check if number exists, then update it like what 'Database put ... ' command does.
			$val = $this->gsmcall_checkNum($post['number']);
			if ((int)$val != 1) {
				// order of inserting values matters in the SQL statement
				$values[] = strval($post['number']);
				$values[] = $post['description'];
				$values[] = 'GUI User';
				$values[] = 'Bulk Import';
				$values[] = $myTimeStamp;
				// Add number normally as it doesn't exist				
				$sql = "INSERT INTO gsmcall.blacklist (";
				$sql .= "tn , de, ab, mu, ts";
				$sql .= ") VALUES ('";
				$sql .= join("', '", array_values($values));
				$sql .= "')";
				$result = \FreePBX::Database()->query($sql);
			} else {
				// Update the record as this number already exists.
				$sql = "UPDATE gsmcall.blacklist SET ";
				$sql .= "de ='" . $post['description'] . "', ";
				$sql .= "ab ='GUI User', ";
				$sql .= "mu ='Bulk Import' ";
				$sql .= "WHERE tn ='" . $post['number'] . "'";		
				$result = \FreePBX::Database()->query($sql);
			}
		}
		
		if ($mode != "bulk"){
//			// order of inserting values matters in the SQL statement
			$values[] = strval($post['number']);
			$values[] = $post['description'];
			$values[] = $post['addedby'];
			$values[] = $post['addedvia'];
		
			if ($mode == "add"){
				$values[] = $myTimeStamp;
				
				$sql = "INSERT INTO gsmcall.blacklist (";
				$sql .= "tn , de, ab, mu, ts";
				$sql .= ") VALUES ('";
				$sql .= join("', '", array_values($values));
				$sql .= "')";
				$result = \FreePBX::Database()->query($sql);
			}
			
			if ($mode == "edit"){
				$values[] = $post['timestamp']; // hold the original timestamp of the blocked number.
			
				$sql = "INSERT INTO gsmcall.blacklist (";
				$sql .= "tn , de, ab, mu, ts";
				$sql .= ") VALUES ('";
				$sql .= join("', '", array_values($values));
				$sql .= "')";
				$result = \FreePBX::Database()->query($sql);
			}
		}
	}
	

	/**
	 * Method coded by Mouhab to query DB "gsmcall.blacklist" to check if number already exists.
	 * Delete Number
	 * @param  array $post Array of blacklist params
	 */
	public function gsmcall_checkNum($post){
		
		// get the details of that record before removal so as i can present it in the mail
		$sql = "SELECT * from gsmcall.blacklist where tn='" . $post . "' LIMIT 1";
		
		$result = sql($sql, 'getAll', DB_FETCHMODE_ASSOC);
		
		if ((isset($result[0]['tn'])) &&  ($result[0]['tn'] == $post)) {
			return true;
		} else {
			return false;
		}
	}

	/**
	 * Method code by Mouhab to insert data from Ajax to DB "gsmcall.blacklist"
	 * Delete Number
	 * @param  array $post Array of blacklist params
	 */
	public function gsmcall_numberDel($post){
		
		// get the details of that record before removal so as i can present it in the mail
		$sql = "SELECT * from gsmcall.blacklist where tn='" . $post . "' LIMIT 1";
		$removedrecord = sql($sql, 'getAll', DB_FETCHMODE_ASSOC);
				
		// run a query to delete..
	    $sql = "DELETE FROM gsmcall.blacklist ";
		$sql .= "WHERE tn = '" . strval($post) . "' ";
		$sql .= "LIMIT 1";

		/* $sql = "DELETE FROM gsmcall.blacklist WHERE tn = '1' LIMIT 1"; */
	
		$result = sql($sql);
		return $removedrecord;
	}
	
	/**
	 * Add Number
	 * @param  array $post Array of blacklist params
	 */
	public function numberAdd($post){
		if ($this->astman->connected()) {
			$post['description'] == '' ? $post['description'] = '1' : $post['description'];
			$this->astman->database_put('blacklist', $post['number'], $post['description']);
		} else {
			throw new \RuntimeException('Cannot connect to Asterisk Manager, is Asterisk running?');
		}
		return $post['number'];
	}

	/**
	 * Delete a number
	 * @param  string $number Number to delete
	 * @return boolean         Status of deletion
	 */
	public function numberDel($number){
		if ($this->astman->connected()) {
			return($this->astman->database_del('blacklist', $number));
		} else {
			throw new \RuntimeException('Cannot connect to Asterisk Manager, is Asterisk running?');
		}
	}

	/**
	 * Set blacklist destination
	 * @param  string $dest Destination
	 * @return boolean       Status of set
	 */
	public function destinationSet($dest) {
		if ($this->astman->connected()) {
			$this->astman->database_del('blacklist', 'dest');
			if (!empty($dest)) {
				return $this->astman->database_put('blacklist', 'dest', $dest);
			} else {
				return true;
			}
		} else {
			throw new \RuntimeException('Cannot connect to Asterisk Manager, is Asterisk running?');
		}
	}
	
	/**
	 * Set the email setting required by GSM new module coded like blacklist destinationSet() function.
	 * @param  string $dest Destination
	 * @return boolean       Status of set
	 */
	public function gsmcall_emailSet($email) {
		if ($this->astman->connected()) {
			$this->astman->database_del('GSMblacklist', 'email');
			if (true) {
				return $this->astman->database_put('GSMblacklist', 'email', $email);
			} else {
				return true;
			}
		} else {
			throw new \RuntimeException('Cannot connect to Asterisk Manager, is Asterisk running?');
		}
	}

	/**
	 * Get the destination
	 * @return string The destination
	 */
	public function destinationGet(){
		if ($this->astman->connected()) {
			return $this->astman->database_get('blacklist', 'dest');
		} else {
			throw new \RuntimeException('Cannot connect to Asterisk Manager, is Asterisk running?');
		}
	}
	
	/**
	 * Get the email setting required by GSM new module code --> "Done in the same way as the original destinationGet()...
	 * Uses gsmcall_emailSet which is also done in the same way that `destinationSet` is coded
	 * @return string The email setting...
	 */
	public function gsmcall_emailGet(){
		if ($this->astman->connected()) {
			return $this->astman->database_get('GSMblacklist', 'email');
		} else {
			throw new \RuntimeException('Cannot connect to Asterisk Manager, is Asterisk running?');
		}
	}
	

	/**
	 * Whether to block unknown calls
	 * @param  boolean $blocked True to block, false otherwise
	 */
	public function blockunknownSet($blocked){
		if ($this->astman->connected()) {
			// Remove filtering for blocked/unknown cid
			$this->astman->database_del('blacklist', 'blocked');
			// Add it back if it's checked
			if (!empty($blocked)) {
				$this->astman->database_put('blacklist', 'blocked', '1');
			}
		} else {
			throw new \RuntimeException('Cannot connect to Asterisk Manager, is Asterisk running?');
		}
	}

	/**
	 * Get status of unknown blocking
	 * @return string 1 if blocked, 0 otherwise
	 */
	public function blockunknownGet(){
		if ($this->astman->connected()) {
			return $this->astman->database_get('blacklist', 'blocked');
		} else {
			throw new \RuntimeException('Cannot connect to Asterisk Manager, is Asterisk running?');
		}
	}
	//BulkHandler hooks
	public function bulkhandlerGetTypes() {
		return array(
			'blacklist' => array(
				'name' => _('Blacklist'),
				'description' => _('Import/Export Caller Blacklist')
			)
		);
	}
	public function bulkhandlerGetHeaders($type) {
		switch($type){
			case 'blacklist':
				$headers = array();
				$headers['number'] = array('required' => true, 'identifier' => _("Phone Number"), 'description' => _("The number as it appears in the callerid display"));
				$headers['description'] = array('required' => false, 'identifier' => _("Description"), 'description' => _("Description of number blacklisted"));
			break;
		}
		return $headers;
	}
	public function bulkhandlerImport($type, $rawData, $replaceExisting = true) {
		$blistnums = array();
		if(!$replaceExisting){
			$blist = $this->getBlacklist();
			foreach ($blist as $value) {
				$blistnums[] = $value['number'];
			}
		}
		switch($type){
			case 'blacklist':
				foreach($rawData as $data){
					if(empty($data['number'])){
						return array('status' => false, 'message'=> _('Phone Number Required'));
					}
					//Skip existing numbers. Array is only populated if replace is false.
					if(in_array($data['number'], $blistnums)){
						continue;
					}
					$this->numberAdd($data);
					$this->gsmcall_sendMail($data,"bulk");
					$this->gsmcall_numberAdd($data,"bulk");
				}
			break;
		}
		return array('status' => true);
	}
	public function bulkhandlerExport($type) {
		$data = NULL;
		switch ($type) {
			case 'blacklist':
				$data = $this->getBlacklist();
			break;
		}
		return $data;
	}

}
