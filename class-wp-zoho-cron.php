<?php
/*
Available filters:
wpzoho_contacts_wp_data
*/
set_time_limit(0);

// accessed directly?
if (!defined('ABSPATH')) {
	// load wp
	$wp_load = false;
	$root_paths = array(
		substr(dirname(__FILE__), 0, strpos(dirname(__FILE__), '/wp-content')),
		substr(dirname($_SERVER['SCRIPT_FILENAME']), 0, strpos(dirname($_SERVER['SCRIPT_FILENAME']), '/wp-content')),
		$_SERVER['DOCUMENT_ROOT'],
	);
	foreach ($root_paths as $value) {
		if (is_file($value.'/wp-load.php')) {
			@include_once($value.'/wp-load.php');
			$wp_load = true;
			break;
		}
	}
	if (!$wp_load) {
		exit;
	}
	unset($wp_load);
	unset($root_paths);
	$wpzoho_cron_direct = true;
}

if (class_exists('SimpleXMLElement') && !class_exists('SimpleXMLExtended')) :
class SimpleXMLExtended extends SimpleXMLElement {
	public function addCData($str) {
		$node = dom_import_simplexml($this); 
		$no   = $node->ownerDocument; 
		$node->appendChild($no->createCDATASection($str));
	} 
}
endif;

if (!class_exists('WP_Zoho_Cron')) :
class WP_Zoho_Cron {

	var $delete = array();
	var $update = array();
	var $messages = array();
	var $contacts_wp_data = array();

	public function __construct() {
		if (!class_exists('WP_Zoho')) {
			@include_once(dirname(__FILE__).'/class-wp-zoho.php');
		}
		$this->subclass = new WP_Zoho(false);
		$this->prefix = $this->subclass->prefix;

		$active = $this->subclass->get_option('active', false);
		if (empty($active)) {
			return;
		}
		$cron = $this->subclass->get_option('cron', false);
		if (empty($cron)) {
			$this->subclass->cron_toggle(false);
			return;
		}
		$this->zoho_api_limit = (int)$this->subclass->get_option('zoho_api_limit', 1000);
		if ($this->zoho_api_limit == 0) {
			$this->zoho_api_limit = 1000;
			// TODO: make this per day, not per cron
		}
		$this->zoho_api_requests = 0;

		$this->delete = $this->subclass->get_transient($this->prefix.'_cron_contacts_delete');
		$this->delete = $this->subclass->make_array($this->delete);
		$this->update = $this->subclass->get_transient($this->prefix.'_cron_contacts_update');
		$this->update = $this->subclass->make_array($this->update);
		$this->cron_delete();
		$this->cron_update();
		$this->cron_mail();
	}

	private function cron_delete() {
		if (empty($this->delete)) {
			return;
		}
        $zoho_authtoken = $this->subclass->get_option('zoho_authtoken', '');
    	$zoho_xml_Contacts_deleteRecords = $this->subclass->get_option('zoho_xml_Contacts_deleteRecords', '');
    	$nexttime = array();

    	foreach ($this->delete as $user_id => $zoho_id) {
    		if ($this->zoho_api_requests >= $this->zoho_api_limit) {
    			$nexttime[$user_id] = $zoho_id;
    			continue;
    		}
			// delete meta
			delete_user_meta($user_id, $this->prefix);
			// remove from update
			if (in_array($user_id, $this->update)) {
				$key = array_search($user_id, $this->update);
				unset($this->update[$key]);
			}
			if (empty($zoho_authtoken)) {
				// error
				$this->messages[] = __FUNCTION__.' - zoho_authtoken not defined.';
				continue;
			}
			if (empty($zoho_xml_Contacts_deleteRecords)) {
				// error
				$this->messages[] = __FUNCTION__.' - zoho_xml_Contacts_deleteRecords not defined.';
				continue;
			}
			// delete zoho 
        	$replace = array(
        		'###AUTHTOKEN###' => $zoho_authtoken,
        		'###ID###' => $zoho_id,
        	);
        	$url = str_replace(array_keys($replace), $replace, $zoho_xml_Contacts_deleteRecords);
    		if ($xml = $this->subclass->get_file_contents($url)) {
				// ok
				//$this->messages[] = __FUNCTION__.' - user_id '.$user_id.' - zoho_id '.$zoho_id.' - ok.';
    		}
    		else {
    			// error
				$this->messages[] = __FUNCTION__.' - user_id '.$user_id.' - zoho_id '.$zoho_id.' - zoho_xml_Contacts_deleteRecords XML failed.';
    		}
			++$this->zoho_api_requests;
    	}
		$this->subclass->delete_transient($this->prefix.'_cron_contacts_delete');
		if (!empty($nexttime)) {
			$this->subclass->set_transient($this->prefix.'_cron_contacts_delete', $nexttime, '2 hours');
		}
	}

	private function cron_update() {
		if (empty($this->update)) {
			return;
		}
        $zoho_authtoken = $this->subclass->get_option('zoho_authtoken', '');
		$this->get_user_field_map();
		$this->get_zoho_fields();
    	$zoho_xml_Contacts_insertRecords = $this->subclass->get_option('zoho_xml_Contacts_insertRecords', '');
    	$zoho_xml_Contacts_updateRecords = $this->subclass->get_option('zoho_xml_Contacts_updateRecords', '');
    	$zoho_xml_Contacts_getSearchRecordsByPDC = $this->subclass->get_option('zoho_xml_Contacts_getSearchRecordsByPDC', '');
		$last_updated = date("Y-m-d H:i:s", time());
		$nexttime = array();

    	foreach ($this->update as $user_id) {
    		if ($this->zoho_api_requests >= $this->zoho_api_limit) {
    			$nexttime[] = $user_id;
    			continue;
    		}
			if (empty($zoho_authtoken)) {
				// error
				$this->messages[] = __FUNCTION__.' - zoho_authtoken not defined.';
				break;
			}
			if (empty($this->user_field_map)) {
				// error
				$this->messages[] = __FUNCTION__.' - user_field_map not defined.';
				break;
			}
			if (empty($this->zoho_fields)) {
				// error
				$this->messages[] = __FUNCTION__.' - zoho_fields not defined.';
				break;
			}
			$userdata = get_userdata($user_id);
			if ($this->subclass->user_has_hidden_role($userdata->roles)) {
				continue;
			}

			$usermeta = get_user_meta($user_id, $this->prefix, true);
			$usermeta = $this->subclass->make_array($usermeta);
			$usermeta_new = array();

			// no zoho_id
			if (!isset($usermeta['zoho_id']) || empty($usermeta['zoho_id'])) {
				// search by email
				if (!empty($zoho_xml_Contacts_getSearchRecordsByPDC)) {
		        	$replace = array(
		        		'###AUTHTOKEN###' => $zoho_authtoken,
		        		'###SEARCHVALUE###' => $userdata->user_email,
		        	);
		        	$url = str_replace(array_keys($replace), $replace, $zoho_xml_Contacts_getSearchRecordsByPDC);
		    		if ($xml = $this->subclass->get_file_contents($url)) {
				    	// parse the XML and get the ID
						if (class_exists('SimpleXmlElement')) {
							$doc = new SimpleXmlElement($xml);
							foreach ($doc->result->Contacts->row as $row) {
								foreach ($row->FL as $field) {
									if ($field->attributes()->val == 'CONTACTID') {
										$usermeta['zoho_id'] = $usermeta_new['zoho_id'] = (string)$field;
										break;
									}
								}
								break;
							}
						}
						else {
							preg_match_all("/<FL [^>]*val=\"CONTACTID\"[^>]*>([^<]+)<\/FL>/is", $xml, $matches);
							if (!empty($matches[1])) {
								$usermeta['zoho_id'] = $usermeta_new['zoho_id'] = current($matches[1]);
							}
						}
		    		}
    				++$this->zoho_api_requests;
				}
			}

			// insert
			if (!isset($usermeta['zoho_id']) || empty($usermeta['zoho_id'])) {
				if (empty($zoho_xml_Contacts_insertRecords)) {
					// error
					$this->messages[] = __FUNCTION__.' - zoho_xml_Contacts_insertRecords not defined.';
					continue;
				}
				$xmlData = $this->get_contacts_xmlData($user_id, array('last_updated' => $last_updated));
				if (empty($xmlData)) {
					// error
					$this->messages[] = __FUNCTION__.' - user_id '.$user_id.' - xmlData not defined.';
					continue;
				}
	        	$replace = array(
	        		'###AUTHTOKEN###' => $zoho_authtoken,
	        		'###XMLDATA###' => $xmlData,
	        	);
	        	$url = str_replace(array_keys($replace), $replace, $zoho_xml_Contacts_insertRecords);
	    		if ($xml = $this->subclass->get_file_contents($url)) {
					// parse the XML and get the ID
					if (class_exists('SimpleXmlElement')) {
						$doc = new SimpleXmlElement($xml);
						foreach ($doc->result->recorddetail->FL as $field) {
							if ($field->attributes()->val == 'Id') {
								$usermeta_new['zoho_id'] = (string)$field;
								break;
							}
						}
					}
					else {
						preg_match_all("/<FL [^>]*val=\"Id\"[^>]*>([^<]+)<\/FL>/is", $xml, $matches);
						if (!empty($matches[1])) {
							$usermeta_new['zoho_id'] = current($matches[1]);
						}
					}
					$usermeta_new['last_updated'] = $last_updated;
					$usermeta = array_merge($usermeta, $usermeta_new);
					update_user_meta($user_id, $this->prefix, $usermeta);
					//$this->messages[] = __FUNCTION__.' - user_id '.$user_id.' - zoho_id '.$usermeta['zoho_id'].' - ok.';
	    		}
	    		else {
					// error
					$this->messages[] = __FUNCTION__.' - user_id '.$user_id.' - zoho_xml_Contacts_insertRecords XML failed.';
	    		}
				++$this->zoho_api_requests;
	    		continue;
			}

			// update
			if (empty($zoho_xml_Contacts_updateRecords)) {
				// error
				$this->messages[] = __FUNCTION__.' - zoho_xml_Contacts_updateRecords not defined.';
				continue;
			}
			$xmlData = $this->get_contacts_xmlData($user_id, array('zoho_id' => $usermeta['zoho_id'], 'last_updated' => $last_updated));
			if (empty($xmlData)) {
				// error
				$this->messages[] = __FUNCTION__.' - user_id '.$user_id.' - zoho_id '.$usermeta['zoho_id'].' - xmlData not defined.';
				continue;
			}
        	$replace = array(
        		'###AUTHTOKEN###' => $zoho_authtoken,
        		'###ID###' => $usermeta['zoho_id'],
        		'###XMLDATA###' => $xmlData,
        	);
        	$url = str_replace(array_keys($replace), $replace, $zoho_xml_Contacts_updateRecords);
    		if ($xml = $this->subclass->get_file_contents($url)) {
				$usermeta_new['last_updated'] = $last_updated;
				$usermeta = array_merge($usermeta, $usermeta_new);
				//$this->messages[] = __FUNCTION__.' - user_id '.$user_id.' - zoho_id '.$usermeta['zoho_id'].' - ok.';
    		}
    		else {
				// error
				$this->messages[] = __FUNCTION__.' - user_id '.$user_id.' - zoho_id '.$usermeta['zoho_id'].' - zoho_xml_Contacts_updateRecords XML failed - removed zoho_id.';
				// remove zoho_id, queue next time
				$usermeta = array();
				$nexttime[] = $user_id;
    		}
			update_user_meta($user_id, $this->prefix, $usermeta);
			++$this->zoho_api_requests;
    	}

		$this->subclass->delete_transient($this->prefix.'_cron_contacts_update');
		if (!empty($nexttime)) {
			$this->subclass->set_transient($this->prefix.'_cron_contacts_update', $nexttime, '2 hours');
		}
	}

	private function cron_mail() {
		if (empty($this->messages)) {
			return;
		}
		$this->messages = array_unique($this->messages);
		$message = implode("\n", $this->messages);
		echo $message;

		// mail
		$admin_email = $this->subclass->get_option('admin_email', false);
		if (empty($admin_email)) {
			return;
		}
		wp_mail($admin_email, $this->subclass->plugin_title.' plugin notice - '.get_called_class(), $message);
	}

	/* functions */

	private function get_user_field_map() {
		if (!isset($this->zoho_fields)) {
	    	$this->user_field_map = $this->subclass->get_option('user_field_map', array());
    	}
	}
	private function get_zoho_fields() {
		if (!isset($this->zoho_fields)) {
    		$this->zoho_fields = $this->subclass->get_contacts_zoho_fields();
    	}
	}

	public function get_contacts_xmlData($user_id = 0, $usermeta = array()) {
		$this->get_user_field_map();
		if (empty($this->user_field_map)) {
			return false;
		}
		$this->get_zoho_fields();
		if (empty($this->zoho_fields)) {
			return false;
		}

		$arr = array();

		// compile the data
		foreach ($this->zoho_fields as $key => $value) {
			// section
			if (is_array($value)) {
				// fields
				foreach ($value as $field_key => $field_value) {
					// required
					if (strpos($field_value, $this->subclass->required) !== false) {
						$data = null;
						if (isset($this->user_field_map[$field_key])) {
							if ($data = $this->get_contacts_wp_data($user_id, $this->user_field_map[$field_key], $usermeta)) {
								$data = $data;
							}
						}
						if (empty($data)) {
							$data = trim($this->subclass->required);
						}
						$arr_key = str_replace($this->subclass->required, '', $field_value);
						$arr[$arr_key] = $data;
						continue;
					}
					// no map
					if (!isset($this->user_field_map[$field_key])) {
						continue;
					}
					// has data
					if ($data = $this->get_contacts_wp_data($user_id, $this->user_field_map[$field_key], $usermeta)) {
						$arr[$field_value] = $data;
					}
				}
			}
			// field
			else {
				// required
				if (strpos($value, $this->subclass->required) !== false) {
					$data = null;
					if (isset($this->user_field_map[$key])) {
						if ($data = $this->get_contacts_wp_data($user_id, $this->user_field_map[$key], $usermeta)) {
							$data = $data;
						}
					}
					if (empty($data)) {
						$data = trim($this->subclass->required);
					}
					$arr_key = str_replace($this->subclass->required, '', $value);
					$arr[$arr_key] = $data;
					continue;
				}
				// no map
				if (!isset($this->user_field_map[$key])) {
					continue;
				}
				// has data
				if ($data = $this->get_contacts_wp_data($user_id, $this->user_field_map[$key], $usermeta)) {
					$arr[$value] = $data;
				}
			}
		}

		if (empty($arr)) {
			return false;
		}

		// prepare the data
		$colon2comma = function($str) {
			$str = htmlspecialchars_decode($str, ENT_COMPAT|ENT_XHTML);
			return str_replace(";", ",", $str);
		};
		foreach ($arr as $key => $value) {
			if (is_array($value)) {
				$value = array_map($colon2comma, $value);
				$value = implode(";", $value);
			}
			else {
				$value = htmlspecialchars_decode($value, ENT_COMPAT|ENT_XHTML);
				$value = mb_substr($value, 0, 255); // TODO: check field lengths from zoho
			}
			$arr[$key] = $value;
		}

		// make the XML
		$has_cdata = function($str) {
			if (strpos($str, '</') !== false) { // html
				return true;
			}
			if (preg_match("/[^a-z0-9_\-:\.\+, ]+/is", $str)) { // special chars - includes ;&
				return true;
			}
			elseif (strpos($str, ' ') !== false && preg_match("/[a-z]+/is", $str)) { // text
				return true;
			}
			return false;
		};
		if (class_exists('SimpleXMLExtended')) {
			$xmlData = new SimpleXMLExtended('<Contacts><row no="1"/></Contacts>');
			foreach ($arr as $key => $value) {
				if ($has_cdata($value)) {
					$child = $xmlData->row->addChild('FL', null); // very important! we need a node where to append
					$child->addCData($value);
				}
				else {
					$child = $xmlData->row->addChild('FL', $value);
				}
				$child->addAttribute('val', $key);
			}
			$dom = dom_import_simplexml($xmlData);
			$xmlData = $dom->ownerDocument->saveXML($dom->ownerDocument->documentElement);
		}
		else {
			$xmlData = '<Contacts><row no="1">';
			foreach ($arr as $key => $value) {
				if ($has_cdata($value)) {
					$xmlData .= '<FL val="'.esc_attr($key).'"><![CDATA['.$value.']]></FL>';
				}
				else {
					$xmlData .= '<FL val="'.esc_attr($key).'">'.$value.'</FL>';
				}
			}
			$xmlData .= '</row></Contacts>';
		}
		return urlencode($xmlData);
	}

	private function get_contacts_wp_data($user_id = 0, $sectionfield = '', $usermeta = array()) {
		if (!isset($this->contacts_wp_data[$user_id])) {
			$this->contacts_wp_data[$user_id] = array();
		}

		list($section, $field) = explode($this->subclass->array_sep, $sectionfield, 2);
		$data = '';

		switch ($section) {
			case 'WP_Zoho':
				if (isset($usermeta[$field])) {
					$data = $usermeta[$field];
				}
				break;
			
			case 'WP_User':
				if (!isset($this->contacts_wp_data[$user_id][$section])) {
					$this->contacts_wp_data[$user_id][$section] = get_userdata($user_id);
				}
				if (isset($this->contacts_wp_data[$user_id][$section]->data->$field)) {
					$data = $this->contacts_wp_data[$user_id][$section]->data->$field;
				}
				elseif (isset($this->contacts_wp_data[$user_id][$section]->$field)) {
					$data = $this->contacts_wp_data[$user_id][$section]->$field;
				}
				break;
			
			case 'WP_Usermeta':
				if (!isset($this->contacts_wp_data[$user_id][$section])) {
					$this->contacts_wp_data[$user_id][$section] = array();
				}
				if (!isset($this->contacts_wp_data[$user_id][$section][$field])) {
					$this->contacts_wp_data[$user_id][$section][$field] = get_user_meta($user_id, $field, true);
				}
				if (isset($this->contacts_wp_data[$user_id][$section][$field])) {
					$data = $this->contacts_wp_data[$user_id][$section][$field];
				}
				break;
			
			case 'Buddypress_XProfile':
				if ($this->subclass->has_bp) {
					if (!isset($this->contacts_wp_data[$user_id][$section])) {
						$this->contacts_wp_data[$user_id][$section] = array();
					}
					if (!isset($this->contacts_wp_data[$user_id][$section][$field])) {
						$bp_field = xprofile_get_field($field);
						if (empty($bp_field)) {
							$bp_data = null;
						}
						else {
							$bp_data = xprofile_get_field_data($field, $user_id);
						}
						if (!empty($bp_data)) {
							if ($bp_field->type == 'datebox') {
								$bp_data = date("m/d/Y", strtotime($bp_data));
							}
							elseif ($bp_field->type == 'url') {
								$bp_data = preg_replace("/^.+? href=\"([^\"]+)\".*$/i", "$1", $bp_data);
								$bp_data = strip_tags($bp_data);
							}
							elseif ($bp_field->type == 'textarea') {
								$bp_data = strip_tags($bp_data);
							}
							elseif (is_string($bp_data) && strpos($bp_data, ' href="tel:') !== false) {
								$bp_data = strip_tags($bp_data);
							}
						}
						$this->contacts_wp_data[$user_id][$section][$field] = $bp_data;
					}
					if (isset($this->contacts_wp_data[$user_id][$section][$field])) {
						$data = $this->contacts_wp_data[$user_id][$section][$field];
					}
				}
				break;
			
			default:
				break;
		}

		$data = apply_filters('wpzoho_contacts_wp_data', $data, $user_id, $sectionfield);
		if (!empty($data)) {
			return $data;
		}
		return false;
	}

}
endif;

if (isset($wpzoho_cron_direct)) {
	$cron = new WP_Zoho_Cron();
	exit;
}
?>