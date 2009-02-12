<?

defined('C5_EXECUTE') or die(_("Access Denied."));
class DashboardSettingsController extends Controller {

	var $helpers = array('form'); 
	
	public function view($updated = false) {
		$u = new User();
		 
		$this->set('site_tracking_code', Config::get('SITE_TRACKING_CODE') );		
		$this->set('url_rewriting', URL_REWRITING);
		$this->set('marketplace_enabled_in_config', Config::get('ENABLE_MARKETPLACE_SUPPORT') );		
		$this->set('site', SITE);
		$this->set('ui_breadcrumb', $u->config('UI_BREADCRUMB'));
		$txtEditorMode=Config::get('CONTENTS_TXT_EDITOR_MODE');
		$this->set('txtEditorMode', $txtEditorMode ); 
		$txtEditorCstmCode=Config::get('CONTENTS_TXT_EDITOR_CUSTOM_CODE');
		if( !strlen($txtEditorCstmCode) || $txtEditorMode!='CUSTOM' )
			$txtEditorCstmCode=$this->get_txt_editor_default();
		$this->set('txtEditorCstmCode', $txtEditorCstmCode ); 
		
		if ($updated) {
			switch($updated) {
				case "tracking_code_saved";
					$this->set('message', t('Your tracking code has been saved.'));	
					break;			
				/*
				//moved to set_permissions
				case "maintenance_enabled";
					$this->set('message', t('Maintenance Mode turned on. Your site is now private.'));	
					break;
				case "maintenance_disabled":
					$this->set('message', t('Maintenance Mode turned off. Your site is public.'));	
					break;
				*/
				case "marketplace_turned_on";
					$this->set('message', t('Marketplace support is now enabled.'));	
					break;
				case "marketplace_turned_off":
					$this->set('message', t('Marketplace support is now disabled.'));	
					break;				
				case "favicon_saved":  
					$this->set('message', t('Bookmark icon saved.'));	
					break;
				case "favicon_removed":  
					$this->set('message', t('Bookmark icon removed.'));	
					break;							
				case "editing_preferences_saved":
					$this->set('message', t('Editing preferences saved.'));	
					break;
				case "sitename_saved":
					$this->set('message', t("Your site's name has been saved."));	
					break;
				case "debug_saved":
					$this->set('message', t('Debug configuration saved.'));
					break;
				case "txt_editor_config_saved":
					$this->set('message', t('Content text editor toolbar mode saved.'));
					break;										
				case "rewriting_saved":
					if (URL_REWRITING) {
						$this->set('message', t('URL rewriting enabled. Make sure you copy the lines below these URL Rewriting settings area and place them in your .htaccess or web server configuration file.'));
					} else {
						$this->set('message', t('URL rewriting disabled.'));
					}
					break;		
			}
		}
	}

	public function update_maintenance() { 
		$this->set_permissions();
		if ($this->token->validate("update_maintenance")) {
			if ($this->isPost()) {
				Config::save('SITE_MAINTENANCE_MODE', $this->post('site_maintenance_mode'));
				if ($this->post('site_maintenance_mode') == 1) { 
					$this->redirect('/dashboard/settings','set_permissions',"maintenance_enabled");
				} else {
					$this->redirect('/dashboard/settings','set_permissions',"maintenance_disabled");
				}
			}
		} else {
			$this->set('error', array($this->token->getErrorMessage()));
		}
	}
	
	public function update_tracking_code() {
		if ($this->token->validate("update_tracking_code")) {
			if ($this->isPost()) {
				Config::save('SITE_TRACKING_CODE', $this->post('tracking_code'));
				$this->redirect('/dashboard/settings','tracking_code_saved'); 			
			}
		} else {
			$this->set('error', array($this->token->getErrorMessage()));
		}
	}	
	
	const IP_BLACKLIST_CHANGE_MAKEPERM		 	= 1;
	const IP_BLACKLIST_CHANGE_REMOVE		 	= 2;
	const IP_BAN_LOCK_IP_HOW_LONG_TYPE_TIMED 	= 'timed';
	const IP_BAN_LOCK_IP_HOW_LONG_TYPE_FOREVER 	= 'forever';	
	public function update_ipblacklist() {
		$db = Loader::db();
		if ($this->token->validate("update_ipblacklist")) {	
			Loader::model('user_banned_ip');
			//configs from top part form
			$ip_ban_lock_ip_enable = (1 == $this->post('ip_ban_lock_ip_enable')) ? 1 : 0;
			Config::save('IP_BAN_LOCK_IP_ENABLE',$ip_ban_lock_ip_enable);
			Config::save('IP_BAN_LOCK_IP_ATTEMPTS',$this->post('ip_ban_lock_ip_attempts'));
			Config::Save('IP_BAN_LOCK_IP_TIME',$this->post('ip_ban_lock_ip_time'));
			
			if (self::IP_BAN_LOCK_IP_HOW_LONG_TYPE_FOREVER != $this->post('ip_ban_lock_ip_how_long_type')) {
				Config::Save('IP_BAN_LOCK_IP_HOW_LONG_MIN',$this->post('ip_ban_lock_ip_how_long_min'));							
			}
			else {
				Config::Save('IP_BAN_LOCK_IP_HOW_LONG_MIN',0);	
			}
			
			//ip table actions
			//use a single sql query, more efficient than active record
			$ip_ban_changes = $this->post('ip_ban_changes');
			if (count($ip_ban_changes) > 0) {				
				$ip_ban_change_to 	= $this->post('ip_ban_change_to');				
				$q = 'UPDATE UserBannedIPs SET expires = ? WHERE ';
				$v = array();
				switch ($ip_ban_change_to) {
					case self::IP_BLACKLIST_CHANGE_MAKEPERM:	
						$v[] = 0;			//expires 0 is a perma-ban
						break;
					case self::IP_BLACKLIST_CHANGE_REMOVE:
						$v[] = 1;			//expires 1 is really far in past, defacto expire
						break;
				}				
							
				$utility			= new UserBannedIP();
				foreach($ip_ban_changes as $key){
					$q .= '(ipFrom = ? AND ipTo = ?) OR';
					$ids = $utility->parseUniqueID($key);
					$v[] = $ids['ipFrom'];
					$v[] = $ids['ipTo'];
				}
				$q = substr($q,0,strlen($q)-3);				
				$db->execute($q,$v);
			}

			//textarea actions
			$ip_ranges = $this->parseIPBlacklistIntoRanges();
			$db = Loader::db();
			$db->StartTrans();
			$q = 'DELETE FROM UserBannedIPs WHERE isManual = 1';
			$db->execute($q);
			//no batch insert in adodb? :(
			
			foreach ($ip_ranges as $ip_range) {			
				$ip = new UserBannedIP();		
				
				$ip->ipFrom 	= ip2long($ip_range['ipFrom']);
				$ip->ipTo		= $ip_range['ipTo'];
				if ($ip->ipTo != 0) {
					echo $ip->ipTo . "\n";
					$ip->ipTo		= ip2long($ip_range['ipTo']);
				}					
				$ip->banCode	= UserBannedIP::IP_BAN_CODE_REGISTRATION_THROTTLE;
				$ip->expires	= 0;
				$ip->isManual	= 1;			
				try{
					$ip->save();
				}
				catch (Exception $e) {
					//silently discard duplicates
				}
			}
			$db->CompleteTrans();
			
			$this->redirect('/dashboard/settings','set_permissions','saved_ipblacklist');
		}
		else {			
			$this->set('error', array($this->token->getErrorMessage()));
		}
	}
	
	//assumes ipv4
	private function parseIPBlacklistIntoRanges() {
		$ips = preg_split('{[\r\n]+}', $this->post('ip_ban_manual'),null, PREG_SPLIT_NO_EMPTY  );
		$ip_ranges = Array();
		foreach ($ips as $ip) {
			if(strpos($ip, '*') === false){		
				$ip = long2ip(ip2long($ip));	//ensures a valid ip
				$ip_ranges[] = Array('ipFrom'=>$ip,'ipTo'=>0);
			}
			else{
				$aOctets = preg_split('{\.}',$ip);
				$ipFrom = '';
				$ipTo 	= '';
				for($i=0;$i<4;$i++){
					if(is_numeric($aOctets[$i])){
						$ipFrom .= $aOctets[$i].'.';
						$ipTo 	.= $aOctets[$i].'.';					
					}
					else{
						$ipFrom .= '0'.'.';
						$ipTo 	.= '255'.'.';										
					}
				}
				$ipFrom	= substr($ipFrom,0,strlen($ipFrom)-1);
				$ipTo	= substr($ipTo,0,strlen($ipTo)-1);		
				
				$ipFrom  = long2ip(ip2long($ipFrom)); //ensures a valid ip
				$ipTo  	 = long2ip(ip2long($ipTo));   //ensures a valid ip
				
				$ip_ranges[] = Array('ipFrom'=>$ipFrom,'ipTo'=>$ipTo);
			}
		}
		
		return $ip_ranges;
	}
	
	public function update_sitename() {
		if ($this->token->validate("update_sitename")) {
			if ($this->isPost()) {
				Config::save('SITE', $this->post('SITE'));
				$this->redirect('/dashboard/settings','sitename_saved');
			}
		} else {
			$this->set('error', array($this->token->getErrorMessage()));
		}
	}

	public function clear_cache() {
		if ($this->token->validate("clear_cache")) {
			if ($this->isPost()) {
				if (Cache::flush()) {
					$this->redirect('/dashboard/settings', 'set_developer', 'cache_cleared');
				}
			}
		} else {
			$this->set('error', array($this->token->getErrorMessage()));
		}
	}

	public function update_user_settings() {
		if ($this->token->validate("update_user_settings")) {
			if ($this->isPost()) {
				$u = new User();
				$u->saveConfig('UI_BREADCRUMB', $this->post('ui_breadcrumb'));
				$this->redirect('/dashboard/settings','editing_preferences_saved');
			}
		} else {
			$this->set('error', array($this->token->getErrorMessage()));
		}
	}

	public function update_cache() {
		if ($this->token->validate("update_cache")) {
			if ($this->isPost()) {
				$u = new User();
				$eca = $this->post('ENABLE_CACHE') == 1 ? 1 : 0; 
				Config::save('ENABLE_CACHE', $eca);
				$this->redirect('/dashboard/settings', 'set_developer', 'cache_updated');
			}
		} else {
			$this->set('error', array($this->token->getErrorMessage()));
		}
	}
	public function update_debug() {
		if ($this->token->validate("update_debug")) {
			if ($this->isPost()) {
				Config::save('SITE_DEBUG_LEVEL', $this->post('debug_level'));
				$this->redirect('/dashboard/settings','set_developer','debug_saved');
			}
		} else {
			$this->set('error', array($this->token->getErrorMessage()));
		}
	}

	public function update_logging() {
		if ($this->token->validate("update_logging")) {
			if ($this->isPost()) {
				$eldq = $this->post('ENABLE_LOG_DATABASE_QUERIES') == 1 ? 1 : 0;
				$elem = $this->post('ENABLE_LOG_EMAILS') == 1 ? 1 : 0;
				$eler = $this->post('ENABLE_LOG_ERRORS') == 1 ? 1 : 0;
				
				Config::save('ENABLE_LOG_DATABASE_QUERIES', $eldq);
				Config::save('ENABLE_LOG_EMAILS', $elem);
				Config::save('ENABLE_LOG_ERRORS', $eler);
				$this->redirect('/dashboard/settings','set_developer','logging_saved');
			}
		} else {
			$this->set('error', array($this->token->getErrorMessage()));
		}
	}

	public function update_rewriting() {
		if ($this->isPost()) {
			Config::save('URL_REWRITING', $this->post('URL_REWRITING'));
			$this->redirect('/dashboard/settings','rewriting_saved');
		}
	}
	
	public function set_developer($updated = false) {
		$debug_level = Config::get('SITE_DEBUG_LEVEL');
		$enable_log_emails = Config::get('ENABLE_LOG_EMAILS');
		$enable_log_database_queries = Config::get('ENABLE_LOG_DATABASE_QUERIES');
		$enable_log_errors = Config::get('ENABLE_LOG_ERRORS');
		if ($debug_level < 1) {
			$debug_level = DEBUG_DISPLAY_PRODUCTION;
		}
		$this->set('debug_level', $debug_level);		
		$this->set('enable_log_database_queries', $enable_log_database_queries);		
		$this->set('enable_log_emails', $enable_log_emails);		
		$this->set('enable_log_errors', $enable_log_errors);		
		if ($updated) {
			switch($updated) {
				case "debug_saved":
					$this->set('message', t('Debug configuration saved.'));
					break;
				case "logging_saved":
					$this->set('message', t('Logging configuration saved.'));	
					break;
				case "cache_cleared";
					$this->set('message', t('Cached files removed.'));	
					break;
				case "cache_updated";
					$this->set('message', t('Cache settings saved.'));	
					break;
			}
		}
	}
	
	
	public function on_start() {
		$prefsSelected = false;
		$globalSelected = false;
		$permsSelected = false;
		$this->token = Loader::helper('validation/token');
		
		switch($this->getTask()) {
			case "set_developer":
				$devSelected = true;
				break;
			case "set_permissions":
				$permsSelected = true;
				break;
			default:
				$globalSelected = true;
				break;
		}					
		$subnav = array(
			array(View::url('/dashboard/settings'), t('General'), $globalSelected),
			array(View::url('/dashboard/settings', 'set_permissions'), t('Access'), $permsSelected),
			array(View::url('/dashboard/settings', 'set_developer'), t('Debug'), $devSelected)
		);
		$this->set('subnav', $subnav);
	}
	
	protected function set_permissions($saved = false) {
		//IP Address Blacklist
		Loader::model('user_banned_ip');
		$ip_ban_enable_lock_ip_after 	= Config::get('IP_BAN_LOCK_IP_ENABLE');
		$ip_ban_enable_lock_ip_after	= ($ip_ban_enable_lock_ip_after == 1) ? 1 : 0;
		$ip_ban_lock_ip_after_attempts 	= Config::get('IP_BAN_LOCK_IP_ATTEMPTS');
		$ip_ban_lock_ip_after_time		= Config::get('IP_BAN_LOCK_IP_TIME');		
		$ip_ban_lock_ip_how_long_min	= Config::get('IP_BAN_LOCK_IP_HOW_LONG_MIN') ? Config::get('IP_BAN_LOCK_IP_HOW_LONG_MIN') : '';
		if(!$ip_ban_lock_ip_how_long_min){
			$ip_ban_lock_ip_how_long_type = self::IP_BAN_LOCK_IP_HOW_LONG_TYPE_FOREVER;
		}
		else{
			$ip_ban_lock_ip_how_long_type = self::IP_BAN_LOCK_IP_HOW_LONG_TYPE_TIMED;		
		}
		
		$user_banned_ip 				= new UserBannedIP();	
		//pull all once filter various lists using code
		$user_banned_ips 				= $user_banned_ip->Find('1=1');		
		$user_banned_manual_ips 		= Array();
		$user_banned_limited_ips 		= Array();
		
		foreach ($user_banned_ips as $user_banned_ip) { 				
			if ($user_banned_ip->isManual == 1) {	
				$user_banned_manual_ips[] = $user_banned_ip->getIPRangeForDisplay();								
			}
			else if ($user_banned_ip->expires - time() > 0 || $user_banned_ip->expires == 0) {
				$user_banned_limited_ips[] =  $user_banned_ip;
			}
		}
		$user_banned_manual_ips = join($user_banned_manual_ips,"\n");
		$this->set('user_banned_manual_ips',$user_banned_manual_ips);
		$this->set('user_banned_limited_ips',$user_banned_limited_ips);
		$this->set('ip_ban_enable_lock_ip_after',$ip_ban_enable_lock_ip_after);
		$this->set('ip_ban_lock_ip_after_attempts',$ip_ban_lock_ip_after_attempts);
		$this->set('ip_ban_lock_ip_after_time',$ip_ban_lock_ip_after_time);
		$this->set('ip_ban_change_makeperm',self::IP_BLACKLIST_CHANGE_MAKEPERM);
		$this->set('ip_ban_change_remove',self::IP_BLACKLIST_CHANGE_REMOVE);		
		
		$this->set('ip_ban_lock_ip_how_long_type',$ip_ban_lock_ip_how_long_type);
		$this->set('ip_ban_lock_ip_how_long_type',$ip_ban_lock_ip_how_long_type);
		$this->set('ip_ban_lock_ip_how_long_type_forever',self::IP_BAN_LOCK_IP_HOW_LONG_TYPE_FOREVER);
		$this->set('ip_ban_lock_ip_how_long_type_timed',self::IP_BAN_LOCK_IP_HOW_LONG_TYPE_TIMED);		
		$this->set('ip_ban_lock_ip_how_long_min',$ip_ban_lock_ip_how_long_min);
		
		//maintanence mode
		$site_maintenance_mode = Config::get('SITE_MAINTENANCE_MODE');
		if ($site_maintenance_mode < 1) {
			$site_maintenance_mode = 0;
		}
		$this->set('site_maintenance_mode', $site_maintenance_mode);	
		$this->set('user_banned_ips',$user_banned_ips);
		
		if ($saved) {
			switch($saved) { 
				case "maintenance_enabled";
					$this->set('message', t('Maintenance Mode turned on. Your site is now private.'));	
					break;
				case "maintenance_disabled":
					$this->set('message', t('Maintenance Mode turned off. Your site is public.'));	
					break;	
				case "saved_ipblacklist":
					$this->set('message',t('IP Blacklist Settings Updated'));
					break;					
				//permissions saved	
				default: 
					$this->set('message', t('Permissions saved.'));	
			}
		}	
	
		if (PERMISSIONS_MODEL != 'simple') {
			return;
		}
		
		$home = Page::getByID(1, "RECENT");
		$gl = new GroupList($home, false, true);
		$gArrayTmp = $gl->getGroupList();
		$gArray = array();
		foreach($gArrayTmp as $gi) {
			if ($gi->getGroupID() == GUEST_GROUP_ID) {
				$ggu = $gi;
				if ($ggu->canRead()) {
					$this->set('guestCanRead', true);
				}
			} else if ($gi->getGroupID() == REGISTERED_GROUP_ID) {
				$gru = $gi;
				if ($gru->canRead()) {
					$this->set('registeredCanRead', true);
				}
			} else {
				$gArray[] = $gi;
			}
		}
		
		$this->set('ggu', $ggu);
		$this->set('gru', $gru);
		$this->set('gArray', $gArray);
		$this->set('home', $home);
	}
	
	public function update_permissions() {
		$this->set_permissions();
		$home = $this->get('home');
		$gru = Group::getByID(REGISTERED_GROUP_ID);
		$ggu = Group::getByID(GUEST_GROUP_ID);
		$gau = Group::getByID(ADMIN_GROUP_ID);
		
		$args = array();
		switch($_POST['view']) {
			case "ANYONE":
				$args['collectionRead'][] = 'gID:' . $ggu->getGroupID(); // this API is pretty crappy. TODO: clean this up in a nice object oriented fashion
				break;
			case "USERS":
				$args['collectionRead'][] = 'gID:' . $gru->getGroupID(); // this API is pretty crappy. TODO: clean this up in a nice object oriented fashion
				break;
			case "PRIVATE":
				$args['collectionRead'][] = 'gID:' . $gau->getGroupID();
				break;
			
		}
		
		$args['collectionWrite'] = array();
		if (is_array($_POST['gID'])) {
			foreach($_POST['gID'] as $gID) {
				$args['collectionWrite'][] = 'gID:' . $gID;
				$args['collectionAdmin'][] = 'gID:' . $gID;
				$args['collectionDelete'][] = 'gID:' . $gID;
			}
		}
		
		$args['cInheritPermissionsFrom'] = 'OVERRIDE';
		$args['cOverrideTemplatePermissions'] = 1;
		
		$home->updatePermissions($args);
		
		$this->redirect('/dashboard/settings/', 'set_permissions', 'permissions_saved');
	}
	
	function update_favicon(){
		Loader::block('library_file');
		
		if ($this->token->validate("update_favicon")) { 
		
			if(intval($this->post('remove_favicon'))==1){
				Config::save('FAVICON_FID',0);
				$this->redirect('/dashboard/settings/', 'favicon_removed');
			}elseif ( isset($_FILES['favicon_file']) ) {
				$fh = Loader::helper('file');
				if(!$fh->hasAllowedExtension($_FILES['favicon_file']['name'])){	
					$msg = t('Invalid file extension.');
				}else{  
					$bt = BlockType::getByHandle('library_file');
					$data = array();
					$data['file'] = $_FILES['favicon_file']['tmp_name'];
					$data['name'] = $_FILES['favicon_file']['name'];
					$nb = $bt->add($data);
					$fileBlock=LibraryFileBlockController::getFile( $nb->getBlockID() );
					$fileID=$fileBlock->getFileID(); 
					Config::save('FAVICON_FID', $fileID);
					$filepath=$fileBlock->getFilePath();  
					copy($filepath,DIR_BASE.'/favicon.ico');
					$this->redirect('/dashboard/settings/', 'favicon_saved');
				}				
			}else{
				$msg = t('An error occured while uploading your file');
			}
			
		}else{
			$this->set('error', array($this->token->getErrorMessage()));
		}
	}
	
	function update_marketplace_support(){ 		
		if ($this->token->validate("update_marketplace_support")) { 
 			Config::save('ENABLE_MARKETPLACE_SUPPORT', intval($this->post('MARKETPLACE_ENABLED')) );
 			if($this->post('MARKETPLACE_ENABLED'))
 				 $this->redirect('/dashboard/settings/', 'marketplace_turned_on');
			else $this->redirect('/dashboard/settings/', 'marketplace_turned_off');	
		}else{
			$this->set('error', array($this->token->getErrorMessage()));
		}
	}
	
	function txt_editor_config(){
		if ($this->token->validate("txt_editor_config")) { 
 			Config::save('CONTENTS_TXT_EDITOR_MODE', $this->post('CONTENTS_TXT_EDITOR_MODE') );
			if($this->post('CONTENTS_TXT_EDITOR_MODE')=='CUSTOM')
				Config::save('CONTENTS_TXT_EDITOR_CUSTOM_CODE', $this->post('CONTENTS_TXT_EDITOR_CUSTOM_CODE') );
 			$this->redirect('/dashboard/settings/', 'txt_editor_config_saved'); 
		}else{
			$this->set('error', array($this->token->getErrorMessage()));
		}
	}
	
	function get_txt_editor_default(){ 
		ob_start();
		?>
theme : "concrete", 
plugins: "inlinepopups,spellchecker,safari,advlink",
editor_selector : "advancedEditor",
spellchecker_languages : "+English=en",	
theme_concrete_buttons1 : "bold,italic,underline,strikethrough,|,justifyleft,justifycenter,justifyright,justifyfull,|,hr,|,styleselect,formatselect,fontsizeselect",
theme_concrete_buttons2 : "bullist,numlist,|,outdent,indent,|,undo,redo,|,link,unlink,anchor,image,cleanup,help,code,forecolor",
theme_concrete_blockformats : "p,address,pre,h1,h2,h3,div,blockquote,cite",
theme_concrete_toolbar_align : "left",
theme_concrete_fonts : "Andale Mono=andale mono,times;Arial=arial,helvetica,sans-serif;Arial Black=arial black,avant garde;Book Antiqua=book antiqua,palatino;Comic Sans MS=comic sans ms,sans-serif;Courier New=courier new,courier;Georgia=georgia,palatino;Helvetica=helvetica;Impact=impact,chicago;Symbol=symbol;Tahoma=tahoma,arial,helvetica,sans-serif;Terminal=terminal,monaco;Times New Roman=times new roman,times;Trebuchet MS=trebuchet ms,geneva;Verdana=verdana,geneva;Webdings=webdings;Wingdings=wingdings,zapf dingbats",
theme_concrete_font_sizes : "1,2,3,4,5,6,7",
theme_concrete_styles: "Note=ccm-note",
spellchecker_languages : "+English=en"

/*
// Use the advanced theme for more than two rows of content
plugins: "inlinepopups,spellchecker,safari,advlink,table,advhr,xhtmlxtras,emotions,insertdatetime,paste,visualchars,nonbreaking,pagebreak,style",
editor_selector : "advancedEditor",
theme : "advanced",
theme_advanced_buttons1 : "cut,copy,paste,pastetext,pasteword,|,undo,redo,|,styleselect,formatselect,fontsizeselect,fontselect",
theme_advanced_buttons2 : "bold,italic,underline,strikethrough,|,justifyleft,justifycenter,justifyright,justifyfull,|,bullist,numlist,|,outdent,indent,blockquote,|,link,unlink,anchor,|,forecolor,backcolor,|,image,charmap,emotions",
theme_advanced_fonts : "Andale Mono=andale mono,times;Arial=arial,helvetica,sans-serif;Arial Black=arial black,avant garde;Book Antiqua=book antiqua,palatino;Comic Sans MS=comic sans ms,sans-serif;Courier New=courier new,courier;Georgia=georgia,palatino;Helvetica=helvetica;Impact=impact,chicago;Symbol=symbol;Tahoma=tahoma,arial,helvetica,sans-serif;Terminal=terminal,monaco;Times New Roman=times new roman,times;Trebuchet MS=trebuchet ms,geneva;Verdana=verdana,geneva;Webdings=webdings;Wingdings=wingdings,zapf dingbats",
// etc.
*/		
		<? 
		$js=ob_get_contents();
		ob_end_clean();
		return $js;
	}
}