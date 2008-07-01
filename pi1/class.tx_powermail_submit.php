<?php
/***************************************************************
*  Copyright notice
*
*  (c) 2007 Alexander Kellner, Mischa Heißmann <alexander.kellner@einpraegsam.net, typo3.2008@heissmann.org>
*  All rights reserved
*
*  This script is part of the TYPO3 project. The TYPO3 project is
*  free software; you can redistribute it and/or modify
*  it under the terms of the GNU General Public License as published by
*  the Free Software Foundation; either version 2 of the License, or
*  (at your option) any later version.
*
*  The GNU General Public License can be found at
*  http://www.gnu.org/copyleft/gpl.html.
*
*  This script is distributed in the hope that it will be useful,
*  but WITHOUT ANY WARRANTY; without even the implied warranty of
*  MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
*  GNU General Public License for more details.
*
*  This copyright notice MUST APPEAR in all copies of the script!
***************************************************************/

require_once(PATH_tslib.'class.tslib_pibase.php');
require_once(PATH_t3lib.'class.t3lib_htmlmail.php');
require_once(t3lib_extMgm::extPath('powermail').'lib/class.tx_powermail_functions_div.php'); // file for div functions
require_once(t3lib_extMgm::extPath('powermail').'lib/class.tx_powermail_markers.php'); // file for marker functions
require_once(t3lib_extMgm::extPath('powermail').'lib/class.tx_powermail_db.php'); // file for marker functions
require_once(t3lib_extMgm::extPath('powermail').'lib/class.tx_powermail_dynamicmarkers.php'); // file for dynamicmarker functions


class tx_powermail_submit extends tslib_pibase {
	var $prefixId      = 'tx_powermail_submit';		// Same as class name
	var $scriptRelPath = 'pi1/class.tx_powermail_submit.php';	// Path to this script relative to the extension dir.
	var $extKey        = 'powermail';	// The extension key.
	var $pi_checkCHash = true;
	var $locallangmarker_prefix = 'locallangmarker_'; // prefix for automatic locallangmarker
	var $email_send = 1; // Enable email send function (disable for testing only)
	var $dbInsert = 1; // Enable db insert of every sent item (disable for testing only)
	var $ok = 0; // disallow sending (standard false)

	function main($content,$conf) {
		$this->conf = $conf;
		$this->pi_setPiVarDefaults();
		$this->pi_loadLL();
		$this->pi_initPIflexform(); // Init and get the flexform data of the plugin

		// Instances
		$this->div_functions = t3lib_div::makeInstance('tx_powermail_functions_div'); // New object: div functions
		$this->dbImport = t3lib_div::makeInstance('tx_powermail_db'); // New object: For additional db import (if wanted)
		$this->dynamicMarkers = t3lib_div::makeInstance('tx_powermail_dynamicmarkers'); // New object: TYPO3 dynamicmarker function
		$this->markers = t3lib_div::makeInstance('tx_powermail_markers'); // New object: TYPO3 mail functions
		$this->markers->init($this->conf,$this); // Initialise the new instance to make cObj available in all other functions.
		$this->confArr = unserialize($GLOBALS['TYPO3_CONF_VARS']['EXT']['extConf'][$this->extKey]); // Get config from localconf.php
		
		// Configuration
		$this->noReplyEmail = str_replace('###DOMAIN###',str_replace('www.','',$_SERVER['SERVER_NAME']),$this->conf['email.']['noreply']); // no reply email address from TS setup
		$this->sessiondata = $GLOBALS['TSFE']->fe_user->getKey('ses',$this->extKey.'_'.($this->pibase->cObj->data['_LOCALIZED_UID'] > 0 ? $this->pibase->cObj->data['_LOCALIZED_UID'] : $this->pibase->cObj->data['uid'])); // Get piVars from session
		$this->sender = ($this->pibase->cObj->data['tx_powermail_sender'] && t3lib_div::validEmail($this->sessiondata[$this->pibase->cObj->data['tx_powermail_sender']]) ? $this->sessiondata[$this->pibase->cObj->data['tx_powermail_sender']] : $this->noReplyEmail); // email sender (if sender is selected and email exists)
		$this->emailReceiver(); // Receiver mail
		$this->subject_r = $this->pibase->cObj->data['tx_powermail_subject_r']; // Subject of mails (receiver)
		$this->subject_s = $this->pibase->cObj->data['tx_powermail_subject_s']; // Subject of mails (sender)
		
		// Templates
		$this->tmpl = array(); $this->mailcontent = array();
		$this->tmpl['thx'] = $this->pibase->cObj->getSubpart(tslib_cObj::fileResource($this->conf['template.']['thxMessage']),"###POWERMAIL_THX###"); // Load HTML Template: THX (works on subpart ###POWERMAIL_THX###)
		$this->tmpl['all'] = $this->pibase->cObj->getSubpart(tslib_cObj::fileResource($this->conf['template.']['all']),"###POWERMAIL_ALL###"); // Load HTML Template: ALL (works on subpart ###POWERMAIL_ALL###)
		$this->tmpl['emails']['all'] = tslib_cObj::fileResource($this->conf['template.']['emails']); // Load HTML Template: Emails
		
		
		// 1. Set $this->markerArray
		$this->markerArray = $this->markers->GetMarkerArray(); // Fill markerArray
 		
		// 2. add hook for manipulation of data after E-Mails where sent
		if(!$this->hook_submit_beforeEmails()) { // All is ok (no spam maybe)
			
			$this->ok = 1; // sending allowed
			if ($this->conf['allow.']['email2receiver']) $this->sendMail('recipient_mail'); // 2a. Email: Generate the Mail for the recipient (if allowed via TS)
			if ($this->conf['allow.']['email2sender'] && $this->pibase->cObj->data['tx_powermail_sender'] && t3lib_div::validEmail($this->sessiondata[$this->pibase->cObj->data['tx_powermail_sender']])) $this->sendMail('sender_mail'); // 2b. Email: Generate the Mail for the sender (if allowed via TS and sender is selected and email exists)
			if ($this->conf['allow.']['dblog']) $this->saveMail(); // 2c. Safe values to DB (if allowed via TS)
			
		} else { // Spam hook is true (maybe spam recognized)
			$this->markerArray = array(); // clear markerArray
			$this->markerArray['###POWERMAIL_THX_ERROR###'] = $this->hook_submit_beforeEmails(); // Fill ###POWERMAIL_THX_MESSAGE### with error message from Hook
		}
		
		// 3. Return Message to FE
		$this->hook_submit_afterEmails(); // add hook for manipulation of data after E-Mails where sent
		$this->content = tslib_cObj::substituteMarkerArrayCached($this->tmpl['thx'],$this->markerArray); // substitute Marker in Template
		$this->content = $this->dynamicMarkers->main($this->conf, $this->pibase->cObj, $this->content); // Fill dynamic locallang or typoscript markers
		$this->content = preg_replace("|###.*?###|i","",$this->content); // Finally clear not filled markers
		$this->hook_submit_LastOne(); // add hook for manipulation thx message
		
		// 4. Additional db storing if wanted
		$this->dbImport->main($this->conf, $this->sessiondata, $this->ok);
		
		// 5. Now clear the session if option is set in TS
		$this->clearSession();
		
		// 6. Clear sessions of captcha
		$this->clearCaptchaSession();
		
		// 7. Redirect if wanted
		$this->redirect();
		
		return $this->content; // return HTML for THX Message
	}
	
	
	// Function sendMail() generates mail for sender and receiver
	function sendMail($subpart) {

		// Configuration
		$this->subpart = $subpart;
		$this->tmpl['emails'][$this->subpart] = $this->pibase->cObj->getSubpart($this->tmpl['emails']['all'],'###POWERMAIL_'.strtoupper($this->subpart).'###'); // Content for HTML Template
		$this->mailcontent[$this->subpart] = $this->pibase->cObj->substituteMarkerArrayCached(trim($this->tmpl['emails'][$this->subpart]),$this->markerArray); // substitute markerArray for HTML content
		$this->mailcontent[$this->subpart] = $this->dynamicMarkers->main($this->conf, $this->pibase->cObj, $this->mailcontent[$this->subpart]); // Fill dynamic locallang or typoscript markers
		$this->mailcontent[$this->subpart] = preg_replace("|###.*?###|i","",$this->mailcontent[$this->subpart]); // Finally clear not filled markers
		$this->maildata = array();
		
		// Set emails and names
		if ($this->subpart == 'recipient_mail') { // default settings: mail to receiver
			$this->maildata['receiver'] = $this->MainReceiver; // set receiver
			$this->maildata['sender'] = $this->sender; // set sender
			$this->maildata['subject'] = $this->subject_r; // set subject
			$this->maildata['sendername'] = $this->sender; // set sendername
			$this->maildata['cc'] = (isset($this->CCReceiver) ? $this->CCReceiver : ''); // carbon copy (take email addresses or nothing if not available)
		} elseif ($this->subpart == 'sender_mail') { // extended settings: mail to sender
			$this->maildata['receiver'] = $this->sender; // set receiver
			$this->maildata['sender'] = (isset($this->conf['email.']['overwritesender']) &&  t3lib_div::validEmail($this->conf['email.']['overwritesender']) ? $this->conf['email.']['overwritesender'] : $this->MainReceiver); // set sender email address (take from ts or from first receiver)
			$this->maildata['subject'] = $this->subject_s; // set subject
			$this->maildata['sendername'] = (isset($this->sendername) ? $this->sendername : $this->MainReceiver); // set sendername
			$this->maildata['cc'] = ''; // no cc
		}
		
		
		$this->hook_submit_changeEmail(); // Last chance to manipulate the mail via hook
		$this->debug($this->subpart); // Debug output
		
		
		
		
		// start main mail function
		$this->htmlMail = t3lib_div::makeInstance('t3lib_htmlmail'); // New object: TYPO3 mail class
		$this->htmlMail->start(); // start htmlmail
		$this->htmlMail->recipient = $this->maildata['receiver']; // main receiver email address
		$this->htmlMail->recipient_copy = $this->maildata['cc']; // cc field (other email addresses)
		$this->htmlMail->subject = $this->div_functions->marker2value($this->maildata['subject'],$this->sessiondata); // mail subject
		$this->htmlMail->from_email = $this->maildata['sender']; // sender email address
		$this->htmlMail->from_name = $this->maildata['sendername']; // sender email name
		$this->htmlMail->returnPath = $this->maildata['sender']; // return path
		$this->htmlMail->replyto_email = ''; // clear replyto email
		$this->htmlMail->replyto_name = ''; // clear replyto name
		
		// add atachment if neeeded
		if(isset($this->sessiondata['FILE']) && $this->conf['upload.']['attachment'] == 1) { // if there are uploaded files AND attachment to emails is activated via constants
			if(is_array($this->sessiondata['FILE']) && $this->subpart == 'recipient_mail') { // only if array and mail to receiver
				foreach ($this->sessiondata['FILE'] as $file) { // one loop for every file
					if (is_file(t3lib_div::getFileAbsFileName($this->div_functions->correctPath($this->conf['upload.']['folder']).$file))) { // If file exists
						$this->htmlMail->addAttachment($this->div_functions->correctPath($this->conf['upload.']['folder']).$file); // add attachment
					}
				}
			}
		}
		
		$this->htmlMail->charset = $GLOBALS['TSFE']->metaCharset; // set current charset
		$this->htmlMail->defaultCharset = $GLOBALS['TSFE']->metaCharset; // set current charset
		if ($this->conf['emailformat.'][$this->subpart] != 'html') { // add plaintext only if emailformat "both" or "plain"
			$this->htmlMail->addPlain($this->div_functions->makePlain($this->mailcontent[$this->subpart])); // add plaintext
		}
		if ($this->conf['emailformat.'][$this->subpart] != 'plain') { // add html only if emailformat "both" or "html"
			$this->htmlMail->setHTML($this->htmlMail->encodeMsg($this->mailcontent[$this->subpart])); // html format if active via constants
		}
		if ($this->email_send) $this->htmlMail->send($this->maildata['receiver']);
	}
	
	
	// Function saveMail() to save piVars and some more infos to DB (tx_powermail_mails)
	function saveMail() {
		
		// Configuration
		$this->save_PID = $GLOBALS['TSFE']->id; // PID where to save: Take current page
		if (intval($this->conf['PID.']['dblog']) > 0) $this->save_PID = $this->conf['PID.']['dblog']; // PID where to save: Get it from TS if set
		if (intval($this->pibase->cObj->data['pages']) > 0) $this->save_PID = $this->pibase->cObj->data['pages']; // PID where to save: Get it from plugin
		
		// DB entry for table Tabelle: tx_powermail_mails
		$this->db_values = array (
			'pid' => intval($this->save_PID), // PID
			'tstamp' => time(), // save current time
			'crdate' => time(), // save current time
			'formid' => ($this->pibase->cObj->data['_LOCALIZED_UID'] > 0 ? $this->pibase->cObj->data['_LOCALIZED_UID'] : $this->pibase->cObj->data['uid']),
			'recipient' => $this->MainReceiver,
			'subject_r' => $this->subject_r,
			'sender' => $this->sender,
			'content' => trim($this->mailcontent['recipient_mail']),
			'piVars' => t3lib_div::array2xml($this->sessiondata,'',0,'piVars'),
			'senderIP' => ($this->confArr['disableIPlog'] == 1 ? $this->pi_getLL('error_backend_noip') : $_SERVER['REMOTE_ADDR']),
			'UserAgent' => $_SERVER['HTTP_USER_AGENT'],
			'Referer' => $_SERVER['HTTP_REFERER'],
			'SP_TZ' => $_SERVER['SP_TZ']
		);
		if ($this->dbInsert) $GLOBALS['TYPO3_DB']->exec_INSERTquery('tx_powermail_mails',$this->db_values); // DB entry
		$this->debug('db'); // Debug output
	}
	
	
	// Function emailReceiver() returns comma-separated list of email receivers
	function emailReceiver() {
		$emails = ''; $this->sendername = ''; // init
		
		// 1. Field receiver
		if ($this->pibase->cObj->data['tx_powermail_recipient']) { // If receivers are listed in field receiver
			$emails = str_replace(array("\r\n","\n\r","\n","\r",";"),',',$this->pibase->cObj->data['tx_powermail_recipient']); // commaseparated list of emails
			$emails = $this->div_functions->marker2value($emails,$this->sessiondata); // make markers available in email receiver field
			$emailarray = t3lib_div::trimExplode(',',$emails,1); // write every part to an array
			
			for ($i=0,$emails='';$i<count($emailarray);$i++) { // one loop for every key
				if (t3lib_div::validEmail($emailarray[$i])) $emails .= $emailarray[$i].', '; // if current value is an email write to $emails
				else $this->sendername .= $emailarray[$i].' '; // if current value is no email, take it for sender name and write to $this->sendername
			}
			if ($emails) $emails = substr(trim($emails), 0, -1); // delete last ,
			if (!empty($this->sendername)) $this->sendername = trim($this->sendername); // trim name
		}
		
		// 2. Field receiver from table
		elseif ($this->pibase->cObj->data['tx_powermail_recip_id'] && $this->pibase->cObj->data['tx_powermail_recip_table']) { // If emails from table was chosen
			$emails = $this->pibase->cObj->data['tx_powermail_recip_id']; // commaseparated list of emails
		}
		
		// 3. Field receiver query
		elseif ($this->pibase->cObj->data['tx_powermail_query']) { // If own select query is chosen
			$query = $this->secQuery($this->pibase->cObj->data['tx_powermail_query']); // secure function of query
			$query = $this->div_functions->marker2value($query,$this->sessiondata); // make markers available in email query
			
			$res = mysql_query($query); // mysql query
			
			if ($res && $query) { // If there is a result
				while($row = $GLOBALS['TYPO3_DB']->sql_fetch_assoc($res)) { // One loop for every result
					if (is_array($row)) { // if $row is an array
						foreach ($row as $key => $value) { // give me the key
							if(t3lib_div::validEmail($row[$key])) { // only if result is a valid email address
								$emails .= $row[$key].', '; // add email address with comma at the end
							}
						}
					}
				}
				if($emails) $emails = substr(trim($emails), 0, -1); // delete last ,
			}
		}
		
		// 4. Split to main receiver and to all other receivers (aa@aa.com, bb@bb.com, cc@cc.com => 1. aa@aa.com / 2. bb@bb.com, cc@cc.com)
		if (isset($emails)) { // if email string is set
			if(strpos($emails,',') > 1) { // if there is a , in the string (more than only one email is set)
				$this->MainReceiver = substr($emails,0,strpos($emails,',')); // aa@aa.com
				$this->CCReceiver = substr($emails,trim(strpos($emails,',')+1)); // bb@bb.com, cc@cc.com
			} else { // only one email is set
				$this->MainReceiver = $emails; // set mail
			}
		}
		
		// 5. If Sendername is not set, take sender email address instead of name
		if (empty($this->sendername)) $this->sendername = $this->MainReceiver;
		
		return false;
	}

	
	// Function redirect() forward the user to a new location after submit
	function redirect() {
		if($this->ok) { // only if spamhook is not set
			if($this->pibase->cObj->data['tx_powermail_redirect']) { // only if redirect target was set in backend
					
				$typolink_conf = array (
				  "returnLast" => "url", // Give me only the string
				  "parameter" => $this->pibase->cObj->data['tx_powermail_redirect'], // target pid
				  "useCacheHash" => 0 // Don't use cache
				);
				$link = $this->pibase->cObj->typolink('x', $typolink_conf); // Create target url
				
				if (intval($this->pibase->cObj->data['tx_powermail_redirect']) > 0 || strpos($this->pibase->cObj->data['tx_powermail_redirect'], 'fileadmin/') !== false) { // PID (intern link) OR file
					$link = ($GLOBALS['TSFE']->tmpl->setup['config.']['baseURL'] ? $GLOBALS['TSFE']->tmpl->setup['config.']['baseURL'] : 'http://'.$_SERVER['HTTP_HOST'].'/') . $link; // Add baseurl to link
				} 
				elseif (t3lib_div::validEmail($this->pibase->cObj->data['tx_powermail_redirect'])) { // if email recognized
					$link = 'mailto:'.$link; // add mailto: 
				}
				
				// Header for redirect
				header("Location: $link"); 
				header("Connection: close");
		
			}
		}
	}
	
	
	// Function debug() enables debug output
	function debug($subpart) {
		if ($subpart != 'db') {
			// If debug output for email
			if ($this->conf['debug.']['output'] == 'all' || $this->conf['debug.']['output'] == 'email') { // only if debug output activated via constants
				$debugarray = array (
					'receiver' => $this->maildata['receiver'] ? $this->maildata['receiver'] : 'SYSTEM NOTE: No receiver email address',
					'cc receiver' => $this->maildata['cc'] ? $this->maildata['cc'] : 'SYSTEM NOTE: No cc addresses',
					'sender' => $this->maildata['sender'] ? $this->maildata['sender'] : 'SYSTEM NOTE: No sender email address',
					'sendername' => $this->maildata['sendername'] ? $this->maildata['sendername'] : 'SYSTEM NOTE: No sender name',
					'charset' => $GLOBALS['TSFE']->metaCharset ? $GLOBALS['TSFE']->metaCharset : 'SYSTEM NOTE: No charset set',
					'attachment' => $this->sessiondata['FILE'] ? $this->sessiondata['FILE'] : 'SYSTEM NOTE: No attachments',
					'subject' => $this->maildata['subject'] ? $this->maildata['subject'] : 'SYSTEM NOTE: No subject',
					'mailcontent (html)' => ($this->conf['emailformat.'][$this->subpart] != 'plain' ? $this->mailcontent[$subpart] : 'SYSTEM NOTE: Disabled via typoscript'),
					'mailcontent (plaintext)' => ($this->conf['emailformat.'][$this->subpart] != 'html' ? $this->div_functions->makePlain($this->mailcontent[$subpart]) : 'SYSTEM NOTE: Disabled via typoscript')
				);
				$this->div_functions->debug($debugarray, 'Email values ('.$subpart.')'); // Debug function (Array from Session)
			}
		} else {
			if ($this->conf['debug.']['output'] == 'all' || $this->conf['debug.']['output'] == 'db') { // only if debug output activated via constants
				$this->div_functions->debug($this->db_values, 'DB values'); // Debug function (Array from Session)
			}
		}
	
	}
	

	// Function hook_submit_changeEmail() to add a hook and change the email datas (changing subject, receiver, sender, sendername)
	function hook_submit_changeEmail() {
		if(is_array($GLOBALS['TYPO3_CONF_VARS']['EXTCONF']['powermail']['PM_SubmitEmailHook'])) { // Adds hook for processing
			foreach ($GLOBALS['TYPO3_CONF_VARS']['EXTCONF']['powermail']['PM_SubmitEmailHook'] as $_classRef) {
				$_procObj = & t3lib_div::getUserObj($_classRef);
				$_procObj->PM_SubmitEmailHook($this->subpart, $this->maildata, $this->sessiondata, $this->markerArray, $this); // Get new marker Array from other extensions
			}
		}
	}
	
	
	// Function hook_submit_beforeEmails() to add a hook at the end of this file to manipulate markers and content before emails where sent
	function hook_submit_beforeEmails() {
		if(is_array($GLOBALS['TYPO3_CONF_VARS']['EXTCONF']['powermail']['PM_SubmitBeforeMarkerHook'])) { // Adds hook for processing of extra global markers
			foreach ($GLOBALS['TYPO3_CONF_VARS']['EXTCONF']['powermail']['PM_SubmitBeforeMarkerHook'] as $_classRef) {
				$_procObj = & t3lib_div::getUserObj($_classRef);
				$hookreturn = $_procObj->PM_SubmitBeforeMarkerHook($this, $this->markerArray, $this->sessiondata); // Get new marker Array from other extensions - if TRUE, don't send mails (maybe spam)
			}
			return $hookreturn; // Return value from hook if given
		} else { // if hook is not set
			return FALSE; // Return False is default (no spam, so emails could be sent)
		}
	}
	

	// Function hook_submit_LastOne() to add a hook and maybe change thx message
	function hook_submit_LastOne() {
		if(is_array($GLOBALS['TYPO3_CONF_VARS']['EXTCONF']['powermail']['PM_SubmitLastOne'])) { // Adds hook for processing
			foreach ($GLOBALS['TYPO3_CONF_VARS']['EXTCONF']['powermail']['PM_SubmitLastOne'] as $_classRef) {
				$_procObj = & t3lib_div::getUserObj($_classRef);
				$_procObj->PM_SubmitLastOneHook($this->content, $this->conf, $this->sessiondata, $this->ok, $this); // Get new marker Array from other extensions
			}
		}
	}
	

	// Function hook_submit_afterEmails() to add a hook at the end of this file to manipulate markers and content after emails where sent
	function hook_submit_afterEmails() {
		if(is_array($GLOBALS['TYPO3_CONF_VARS']['EXTCONF']['powermail']['PM_SubmitAfterMarkerHook'])) { // Adds hook for processing of extra global markers
			foreach ($GLOBALS['TYPO3_CONF_VARS']['EXTCONF']['powermail']['PM_SubmitAfterMarkerHook'] as $_classRef) {
				$_procObj = & t3lib_div::getUserObj($_classRef);
				$_procObj->PM_SubmitAfterMarkerHook($this,$this->markerArray,$this->sessiondata); // Get new marker Array from other extensions
			}
		}
	}
	
	
	// Function to clear the Session after submitting the form. Will only be cleared when option is selected in Constant-Editor oder set by TS
	function clearSession() {
		if($this->ok) { // only if spamhook is not set
			if($this->conf['clear.']['session'] == 1) { // If set in constants // setup
				$GLOBALS['TSFE']->fe_user->setKey("ses", $this->extKey.'_'.($this->pibase->cObj->data['_LOCALIZED_UID'] > 0 ? $this->pibase->cObj->data['_LOCALIZED_UID'] : $this->pibase->cObj->data['uid']), array()); // Generate Session without ERRORS
				$GLOBALS['TSFE']->storeSessionData(); // Save session*/
			}
		}
	}
	
	
	// Function clearCaptchaSession() clears already filled captcha sessions from captcha or sr_freecap
	function clearCaptchaSession() {
		session_start(); // start session
		if(isset($_SESSION['tx_captcha_string'])) $_SESSION['tx_captcha_string'] = ''; // clear session of captcha
		if(isset($_SESSION['sr_freecap_attempts'])) $_SESSION['sr_freecap_attempts'] = 0; // clear session of sr_freecap
		if(isset($_SESSION['sr_freecap_word_hash'])) $_SESSION['sr_freecap_word_hash'] = false; // clear session of sr_freecap
	}
	
	
	// Function secQuery() disables query functions like UPDATE, TRUNCATE, DELETE, and so on
	function secQuery($string) {
		$notAllowed = array('UPDATE','TRUNCATE','DELETE','INSERT','REPLACE','HANDLER','LOAD','ALTER','CREATE','DROP','RENAME','DESCRIBE','BEGIN','COMMIT','ROLLBACK','LOCK','REVOKE','GRANT'); // list of all not allowed strings for querycheck
		$error = 0; $failure = ''; // init 
		
		if(is_array($notAllowed)) { // only if array
			foreach ($notAllowed as $key => $value) { // one loop for every not allowed string
				if (strpos(strtolower($string), strtolower($value)) !== false) { // search for (e.g.) "delete" in string
					$error = 1; // set error if found
					$failure .= '"'.$value.'", '; // Save error string
				}
			}
		}
		if($failure) $failure = substr(trim($failure), 0, -1); // delete last ,
		
		if($error === 0) return $string; // return query if no error
		else { // if error
			echo 'Not allowed string ('.$failure.') in receiver sql query!'; // print error message
			return false; // no return
		}
	}


	//function for initialisation.
	// to call cObj, make $this->pibase->cObj->function()
	function init(&$conf,&$pibase) {
		$this->conf = $conf;
		$this->pibase = $pibase;
	}
}


if (defined('TYPO3_MODE') && $TYPO3_CONF_VARS[TYPO3_MODE]['XCLASS']['ext/powermail/pi1/class.tx_powermail_submit.php'])	{
	include_once($TYPO3_CONF_VARS[TYPO3_MODE]['XCLASS']['ext/powermail/pi1/class.tx_powermail_submit.php']);
}

?>