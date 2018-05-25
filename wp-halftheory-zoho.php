<?php
/*
Plugin Name: WP Zoho
Plugin URI: https://github.com/halftheory/wp-halftheory-zoho
GitHub Plugin URI: https://github.com/halftheory/wp-halftheory-zoho
Description: WP Zoho
Author: Half/theory
Author URI: https://github.com/halftheory
Version: 1.0
Network: true
*/

/*
Available filters:
wpzoho_option_defaults(array)
wpzoho_deactivation(string $db_prefix)
wpzoho_uninstall(string $db_prefix)
*/

// Exit if accessed directly.
defined('ABSPATH') || exit;

if (!class_exists('WP_Zoho_Plugin')) :
class WP_Zoho_Plugin {

	public function __construct($load_actions = true) {
		@include_once(dirname(__FILE__).'/class-wp-zoho.php');
		$this->subclass = new WP_Zoho($load_actions);
	}

	public static function init() {
		$plugin = new self;
		return $plugin;
	}

	public static function activation() {
		$plugin = new self(false);
		// cron
		$plugin->subclass->cron_toggle();
		// add defaults if they don't exist
		$options = $plugin->subclass->get_option();
		if (empty($options)) {
			$option_defaults = array(
				'actions' => array(
					'edit_user_profile_update',
					'profile_update',
					'deleted_user',
					'xprofile_updated_profile',
				),
				'admin_email' => get_option('admin_email', ''),
				'zoho_api_limit' => 1000,
				'zoho_xml_Contacts_getFields' => 'https://crm.zoho.com/crm/private/xml/Contacts/getFields?authtoken=###AUTHTOKEN###&scope=crmapi',
				'zoho_xml_Contacts_insertRecords' => 'https://crm.zoho.com/crm/private/xml/Contacts/insertRecords?authtoken=###AUTHTOKEN###&scope=crmapi&newFormat=1&xmlData=###XMLDATA###',
				'zoho_xml_Contacts_updateRecords' => 'https://crm.zoho.com/crm/private/xml/Contacts/updateRecords?authtoken=###AUTHTOKEN###&scope=crmapi&newFormat=1&id=###ID###&xmlData=###XMLDATA###',
				'zoho_xml_Contacts_deleteRecords' => 'https://crm.zoho.com/crm/private/xml/Contacts/deleteRecords?authtoken=###AUTHTOKEN###&scope=crmapi&id=###ID###',
				'zoho_xml_Contacts_getRecordById' => 'https://crm.zoho.com/crm/private/xml/Contacts/getRecordById?authtoken=###AUTHTOKEN###&scope=crmapi&id=###ID###',
				'zoho_xml_Contacts_getSearchRecordsByPDC' => 'https://crm.zoho.com/crm/private/xml/Contacts/getSearchRecordsByPDC?authtoken=###AUTHTOKEN###&scope=crmapi&searchColumn=email&searchValue=###SEARCHVALUE###',
			);
			$option_defaults = apply_filters('wpzoho_option_defaults', $option_defaults);
            if ($plugin->subclass->update_option($option_defaults)) {
            	// ok
            }
        	else {
        		// error
        	}
		}
		return $plugin;
	}

	public static function deactivation() {
		$plugin = new self(false);
		// cron
		$plugin->subclass->cron_toggle(false);
		global $wpdb;
		// remove transients
		$query_single = "DELETE FROM $wpdb->options WHERE option_name LIKE '_transient_".$plugin->subclass->prefix."%' OR option_name LIKE '_transient_timeout_".$plugin->subclass->prefix."%'";
		if (is_multisite()) {
			$wpdb->query("DELETE FROM $wpdb->sitemeta WHERE meta_key LIKE '_site_transient_".$plugin->subclass->prefix."%' OR meta_key LIKE '_site_transient_timeout_".$plugin->subclass->prefix."%'");
			$current_blog_id = get_current_blog_id();
			$sites = get_sites();
			foreach ($sites as $key => $value) {
				switch_to_blog($value->blog_id);
				$wpdb->query($query_single);
			}
			switch_to_blog($current_blog_id);
		}
		else {
			$wpdb->query($query_single);
		}
		apply_filters('wpzoho_deactivation', $plugin->subclass->prefix);
		return;
	}

	public static function uninstall() {
		$plugin = new self(false);
		global $wpdb;
		// remove options + usermeta
		$query_options = "DELETE FROM $wpdb->options WHERE option_name = '".$plugin->subclass->prefix."' OR option_name LIKE '".$plugin->subclass->prefix."_%'";
		$query_usermeta = "DELETE FROM $wpdb->usermeta WHERE meta_key = '".$plugin->subclass->prefix."' OR meta_key LIKE '".$plugin->subclass->prefix."_%'";
		if (is_multisite()) {
			delete_site_option($plugin->subclass->prefix);
			$wpdb->query("DELETE FROM $wpdb->sitemeta WHERE meta_key = '".$plugin->subclass->prefix."' OR meta_key LIKE '".$plugin->subclass->prefix."_%'");
			$current_blog_id = get_current_blog_id();
			$sites = get_sites();
			foreach ($sites as $key => $value) {
				switch_to_blog($value->blog_id);
				delete_option($plugin->subclass->prefix);
				$wpdb->query($query_options);
			}
			switch_to_blog($current_blog_id);
		}
		else {
			delete_option($plugin->subclass->prefix);
			$wpdb->query($query_options);
		}
		$wpdb->query($query_usermeta);
		apply_filters('wpzoho_uninstall', $plugin->subclass->prefix);
		return;
	}

}
// Load the plugin.
add_action('init', array('WP_Zoho_Plugin', 'init'));
endif;

register_activation_hook(__FILE__, array('WP_Zoho_Plugin', 'activation'));
register_deactivation_hook(__FILE__, array('WP_Zoho_Plugin', 'deactivation'));
if (!function_exists('WP_Zoho_Plugin_uninstall')) {
	function WP_Zoho_Plugin_uninstall() {
		WP_Zoho_Plugin::uninstall();
	}
}
register_uninstall_hook(__FILE__, 'WP_Zoho_Plugin_uninstall');
?>