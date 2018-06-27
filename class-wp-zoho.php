<?php
/*
Available filters:
halftheory_admin_menu_parent
wpzoho_admin_menu_parent
wpzoho_exclude_users
wpzoho_contacts_zoho_fields
wpzoho_contacts_wp_fields
*/

// Exit if accessed directly.
defined('ABSPATH') || exit;

if (!class_exists('WP_Zoho')) :
class WP_Zoho {

	var $required = ' (required)';
	var $array_sep = '::';
	var $exclude_users = array();
	var $has_bp = false;

	public function __construct($load_actions = true) {
		$this->plugin_name = get_called_class();
		$this->plugin_title = ucwords(str_replace('_', ' ', $this->plugin_name));
		$this->prefix = sanitize_key($this->plugin_name);
		$this->prefix = preg_replace("/[^a-z0-9]/", "", $this->prefix);

		$this->exclude_users = apply_filters('wpzoho_exclude_users', $this->exclude_users);

		// buddypress
		if (function_exists('buddypress')) {
			if (bp_is_active('xprofile')) {
				$this->has_bp = true;
			}
		}

		if (!$load_actions) {
			return;
		}

		// admin options
		if (!$this->is_front_end()) {
			if (is_multisite()) {
				add_action('network_admin_menu', array($this,'admin_menu'));
				if (is_main_site()) {
					add_action('admin_menu', array($this,'admin_menu'));
				}
			}
			else {
				add_action('admin_menu', array($this,'admin_menu'));
			}
		}

		// cron
		add_action($this->prefix.'_cron', array($this,'cron_action'));

		// stop if not active
		$active = $this->get_option('active', false);
		if (empty($active)) {
			return;
		}

		// actions
		add_action('profile_update', array($this,'profile_update'), 10, 2);
		// admin
		if (!$this->is_front_end()) {
			add_action('edit_user_profile', array($this,'edit_user_profile'));
			add_action('show_user_profile', array($this,'edit_user_profile'));
			add_action('edit_user_profile_update', array($this,'edit_user_profile_update'));
			add_action('personal_options_update', array($this,'edit_user_profile_update'));
			add_action('deleted_user', array($this,'deleted_user'));
		}
		// buddypress
		if ($this->has_bp) {
			add_action('xprofile_updated_profile', array($this,'xprofile_updated_profile'), 9, 5); // before redirect
		}
	}

	/* functions-common */

	public function make_array($str = '', $sep = ',') {
		if (function_exists(__FUNCTION__)) {
			$func = __FUNCTION__;
			return $func($str, $sep);
		}
		if (is_array($str)) {
			return $str;
		}
		if (empty($str)) {
			return array();
		}
		$arr = explode($sep, $str);
		$arr = array_map('trim', $arr);
		$arr = array_filter($arr);
		return $arr;
	}

	private function is_front_end() {
		if (function_exists(__FUNCTION__)) {
			$func = __FUNCTION__;
			return $func();
		}
		if (is_admin() && !wp_doing_ajax()) {
			return false;
		}
		if (wp_doing_ajax()) {
			if (strpos($this->get_current_uri(), admin_url()) !== false) {
				return false;
			}
		}
		return true;
	}

	private function get_current_uri() {
		if (function_exists(__FUNCTION__)) {
			$func = __FUNCTION__;
			return $func();
		}
	 	$res  = is_ssl() ? 'https://' : 'http://';
	 	$res .= $_SERVER['HTTP_HOST'];
	 	$res .= $_SERVER['REQUEST_URI'];
		if (wp_doing_ajax()) {
			if (!empty($_SERVER["HTTP_REFERER"])) {
				$res = $_SERVER["HTTP_REFERER"];
			}
		}
		return $res;
	}

	/* admin */

	public function admin_menu() {
		if (!is_array($GLOBALS['menu'])) {
			return;
		}

		$has_parent = false;
		$parent_slug = $this->prefix;
		$parent_name = apply_filters('halftheory_admin_menu_parent', 'Halftheory');
		$parent_name = apply_filters('wpzoho_admin_menu_parent', $parent_name);

		// set parent to nothing to skip parent menu creation
		if (empty($parent_name)) {
			add_options_page(
				$this->plugin_title,
				$this->plugin_title,
				'manage_options',
				$this->prefix,
				__CLASS__ .'::menu_page'
			);
			return;
		}

		// find top level menu if it exists
	    foreach ($GLOBALS['menu'] as $value) {
	    	if ($value[0] == $parent_name) {
	    		$parent_slug = $value[2];
	    		$has_parent = true;
	    		break;
	    	}
	    }

		// add top level menu if it doesn't exist
		if (!$has_parent) {
			add_menu_page(
				$this->plugin_title,
				$parent_name,
				'manage_options',
				$parent_slug,
				__CLASS__ .'::menu_page'
			);
		}

		// add the menu
		add_submenu_page(
			$parent_slug,
			$this->plugin_title,
			$this->plugin_title,
			'manage_options',
			$this->prefix,
			__CLASS__ .'::menu_page'
		);
	}

	public function menu_page() {
 		global $title;
		?>
		<div class="wrap">
			<h2><?php echo $title; ?></h2>
		<?php
 		$plugin = new WP_Zoho();

 		if ($_POST['refresh_contacts_zoho_fields']) {
			if ($plugin->delete_transient($plugin->prefix.'_xml_Contacts_getFields') && $plugin->delete_transient($plugin->prefix.'_contacts_zoho_fields')) {
        		echo '<div class="updated"><p><strong>The old fields have been deleted.</strong></p></div>';
			}
			else {
        		echo '<div class="error"><p><strong>Error: There was a problem deleting the old fields.</strong></p></div>';
			}
 		}

 		if ($_POST['bulk_update_all_users']) {
			$cron = $plugin->get_option('cron', false);
			if (empty($cron)) {
        		echo '<div class="error"><p><strong>Error: The Cronjob is not active.</strong></p></div>';
			}
			else {
	 			$users = array();
	 			$users_args = array(
	 				'exclude' => $plugin->exclude_users,
	 				'role__not_in' => $plugin->get_option('hidden_roles', array()),
	 				'fields' => 'ID',
	 				'orderby' => 'ID',
	 			);
	 			if (is_multisite()) {
	 				$sites = get_sites();
					foreach ($sites as $key => $value) {
						$blog_users = get_users( array_merge($users_args, array('blog_id' => $value->blog_id)) );
						if (!empty($blog_users)) {
							$users = array_merge($users, $blog_users);
						}
					}
					sort($users);
	 			}
		 		else {
		 			$users = get_users($users_args);
		 		} 			
	 			$plugin->cron_contacts_update($users);
	    		echo '<div class="updated"><p><strong>Added all users ('.count($users).') to the next update.</strong></p></div>';
    		}
 		}

        if ($_POST['save']) {
        	$save = function() use ($plugin) {
				// verify this came from the our screen and with proper authorization
				if (!isset($_POST[$plugin->plugin_name.'::menu_page'])) {
					return;
				}
				if (!wp_verify_nonce($_POST[$plugin->plugin_name.'::menu_page'], plugin_basename(__FILE__))) {
					return;
				}

				// make array for user_field_map
				$zoho_fields = $plugin->get_contacts_zoho_fields();
				if (!empty($zoho_fields)) {
					$name = $plugin->prefix.'_user_field_map';
					$_POST[$name] = array();
	        		foreach ($zoho_fields as $key => $value) {
	        			// section
	        			if (is_array($value)) {
	        				// fields
	        				foreach ($value as $field_key => $field_value) {
								if (!isset($_POST[$name.'_'.$field_key])) {
									continue;
								}
								if (empty($_POST[$name.'_'.$field_key])) {
									unset($_POST[$name.'_'.$field_key]);
									continue;
								}
								$_POST[$name][$field_key] = $_POST[$name.'_'.$field_key];
								unset($_POST[$name.'_'.$field_key]);
	        				}
	        			}
	        			// field
	        			else {
							if (!isset($_POST[$name.'_'.$key])) {
								continue;
							}
							if (empty($_POST[$name.'_'.$key])) {
								unset($_POST[$name.'_'.$key]);
								continue;
							}
							$_POST[$name][$key] = $_POST[$name.'_'.$key];
							unset($_POST[$name.'_'.$key]);
	        			}
	        		}
				}

				// get values
				$options_arr = $plugin->get_options_array();
				$options = array();
				foreach ($options_arr as $value) {
					$name = $plugin->prefix.'_'.$value;
					if (!isset($_POST[$name])) {
						continue;
					}
					if (empty($_POST[$name])) {
						continue;
					}
					$options[$value] = $_POST[$name];
				}
				// save it
	            $updated = '<div class="updated"><p><strong>Options saved.</strong></p></div>';
	            $error = '<div class="error"><p><strong>Error: There was a problem.</strong></p></div>';
				if (!empty($options)) {
		            if ($plugin->update_option($options)) {
		            	echo $updated;
		            }
		        	else {
		        		// were there changes?
		        		$options_old = $plugin->get_option(null, array());
		        		ksort($options_old);
		        		ksort($options);
		        		if ($options_old !== $options) {
		            		echo $error;
		            	}
		            	else {
			            	echo $updated;
		            	}
		        	}
				}
				else {
		            if ($plugin->delete_option()) {
		            	echo $updated;
		            }
		        	else {
		            	echo $updated;
		        	}
				}

				// maybe cron changed
				$plugin->cron_toggle();
			};
			$save();
        }

		// show the form
		$options_arr = $plugin->get_options_array();
		$options = $plugin->get_option(null, array());
		$options = array_merge( array_fill_keys($options_arr, null), (array)$options );
		?>
	    <form id="<?php echo $plugin->prefix; ?>-admin-form" name="<?php echo $plugin->prefix; ?>-admin-form" method="post" action="<?php echo $_SERVER['REQUEST_URI']; ?>">
		<?php
		// Use nonce for verification
		wp_nonce_field(plugin_basename(__FILE__), $plugin->plugin_name.'::'.__FUNCTION__);
		?>
	    <div id="poststuff">

        <div class="postbox">
        	<div class="inside">
	            <h4><?php _e('Global Options'); ?></h4>

		        <p><label for="<?php echo $plugin->prefix; ?>_active"><input type="checkbox" id="<?php echo $plugin->prefix; ?>_active" name="<?php echo $plugin->prefix; ?>_active" value="1"<?php checked($options['active'], 1); ?> /> <?php echo $plugin->plugin_title; ?> active?</label></p>

	            <p><label for="<?php echo $plugin->prefix; ?>_cron"><input type="checkbox" id="<?php echo $plugin->prefix; ?>_cron" name="<?php echo $plugin->prefix; ?>_cron" value="1"<?php checked($options['cron'], 1); ?> /> <?php _e('Send data to Zoho?'); ?></label><br />
	            <span class="description"><?php _e('This option activates the hourly Cronjob that communicates all changes to the Zoho server.'); ?></span></p>

	            <p><label for="<?php echo $plugin->prefix; ?>_cron_direct"><input type="checkbox" id="<?php echo $plugin->prefix; ?>_cron_direct" name="<?php echo $plugin->prefix; ?>_cron_direct" value="1"<?php checked($options['cron_direct'], 1); ?> /> <?php _e('Execute the Cronjob via direct file.'); ?></label><br />
	            <span class="description"><?php _e('This can be faster, but may have problems with security plugins.'); ?></span></p>

	            <?php if (!empty($options['cron'])) : ?>
	        	<p><input type="submit" value="<?php _e('Export data for all users'); ?>" id="bulk_update_all_users" class="button button-large" name="bulk_update_all_users"><br />
	            <span class="description"><?php _e('This will send the data for all users to Zoho on the next update.'); ?></span></p>
	            <?php endif; ?>

	            <?php $options['actions'] = $plugin->make_array($options['actions']); ?>
	            <p><?php _e('Enabled Actions'); ?><br />
	            <label><input type="checkbox" name="<?php echo $plugin->prefix; ?>_actions[]" value="edit_user_profile_update"<?php if (in_array('edit_user_profile_update', $options['actions'])) { checked(true); } ?> /> edit_user_profile_update</label><br />
	            <label><input type="checkbox" name="<?php echo $plugin->prefix; ?>_actions[]" value="profile_update"<?php if (in_array('profile_update', $options['actions'])) { checked(true); } ?> /> profile_update</label><br />
	            <label><input type="checkbox" name="<?php echo $plugin->prefix; ?>_actions[]" value="deleted_user"<?php if (in_array('deleted_user', $options['actions'])) { checked(true); } ?> /> deleted_user</label>
	            <?php if ($plugin->has_bp) : ?>
	            <br />
	            <label><input type="checkbox" name="<?php echo $plugin->prefix; ?>_actions[]" value="xprofile_updated_profile"<?php if (in_array('xprofile_updated_profile', $options['actions'])) { checked(true); } ?> /> xprofile_updated_profile</label>
	        	<?php endif; ?>
	        	</p>

	            <p><label for="<?php echo $plugin->prefix; ?>_admin_email" style="display: inline-block; width: 16em;"><?php _e('Admin Email'); ?></label>
	            <input type="text" id="<?php echo $plugin->prefix; ?>_admin_email" name="<?php echo $plugin->prefix; ?>_admin_email" value="<?php echo esc_attr($options['admin_email']); ?>" style="width: 50%;" /></p>

        	</div>
        </div>

        <div class="postbox">
        	<div class="inside">
	            <h4><?php _e('Zoho Options'); ?></h4>
	            <p><span class="description"><?php _e('These details are available from Zoho.'); ?><br />
	            	<?php _e('Legend: Authtoken = ###AUTHTOKEN###, User ID = ###ID###, User Data = ###XMLDATA###, Search Value = ###SEARCHVALUE###.'); ?></span></p>

	            <p><label for="<?php echo $plugin->prefix; ?>_zoho_api_limit" style="display: inline-block; width: 16em;"><?php _e('Zoho API Request Limit'); ?></label>
	            <input type="text" id="<?php echo $plugin->prefix; ?>_zoho_api_limit" name="<?php echo $plugin->prefix; ?>_zoho_api_limit" value="<?php echo esc_attr($options['zoho_api_limit']); ?>" style="width: 50%;" /></p>

	            <p><label for="<?php echo $plugin->prefix; ?>_zoho_authtoken" style="display: inline-block; width: 16em;"><?php _e('Zoho Authtoken'); ?></label>
	            <input type="text" id="<?php echo $plugin->prefix; ?>_zoho_authtoken" name="<?php echo $plugin->prefix; ?>_zoho_authtoken" value="<?php echo esc_attr($options['zoho_authtoken']); ?>" style="width: 50%;" /></p>

	            <p><label for="<?php echo $plugin->prefix; ?>_zoho_xml_Contacts_getFields" style="display: inline-block; width: 16em;"><?php _e('XML Contacts getFields'); ?></label>
	            <input type="text" id="<?php echo $plugin->prefix; ?>_zoho_xml_Contacts_getFields" name="<?php echo $plugin->prefix; ?>_zoho_xml_Contacts_getFields" value="<?php echo esc_attr($options['zoho_xml_Contacts_getFields']); ?>" style="width: 50%;" /></p>

	            <p><label for="<?php echo $plugin->prefix; ?>_zoho_xml_Contacts_insertRecords" style="display: inline-block; width: 16em;"><?php _e('XML Contacts insertRecords'); ?></label>
	            <input type="text" id="<?php echo $plugin->prefix; ?>_zoho_xml_Contacts_insertRecords" name="<?php echo $plugin->prefix; ?>_zoho_xml_Contacts_insertRecords" value="<?php echo esc_attr($options['zoho_xml_Contacts_insertRecords']); ?>" style="width: 50%;" /></p>

	            <p><label for="<?php echo $plugin->prefix; ?>_zoho_xml_Contacts_updateRecords" style="display: inline-block; width: 16em;"><?php _e('XML Contacts updateRecords'); ?></label>
	            <input type="text" id="<?php echo $plugin->prefix; ?>_zoho_xml_Contacts_updateRecords" name="<?php echo $plugin->prefix; ?>_zoho_xml_Contacts_updateRecords" value="<?php echo esc_attr($options['zoho_xml_Contacts_updateRecords']); ?>" style="width: 50%;" /></p>

	            <p><label for="<?php echo $plugin->prefix; ?>_zoho_xml_Contacts_deleteRecords" style="display: inline-block; width: 16em;"><?php _e('XML Contacts deleteRecords'); ?></label>
	            <input type="text" id="<?php echo $plugin->prefix; ?>_zoho_xml_Contacts_deleteRecords" name="<?php echo $plugin->prefix; ?>_zoho_xml_Contacts_deleteRecords" value="<?php echo esc_attr($options['zoho_xml_Contacts_deleteRecords']); ?>" style="width: 50%;" /></p>

	            <p><label for="<?php echo $plugin->prefix; ?>_zoho_xml_Contacts_getRecordById" style="display: inline-block; width: 16em;"><?php _e('XML Contacts getRecordById'); ?></label>
	            <input type="text" id="<?php echo $plugin->prefix; ?>_zoho_xml_Contacts_getRecordById" name="<?php echo $plugin->prefix; ?>_zoho_xml_Contacts_getRecordById" value="<?php echo esc_attr($options['zoho_xml_Contacts_getRecordById']); ?>" style="width: 50%;" /></p>

	            <p><label for="<?php echo $plugin->prefix; ?>_zoho_xml_Contacts_getSearchRecordsByPDC" style="display: inline-block; width: 16em;"><?php _e('XML Contacts getSearchRecordsByPDC'); ?></label>
	            <input type="text" id="<?php echo $plugin->prefix; ?>_zoho_xml_Contacts_getSearchRecordsByPDC" name="<?php echo $plugin->prefix; ?>_zoho_xml_Contacts_getSearchRecordsByPDC" value="<?php echo esc_attr($options['zoho_xml_Contacts_getSearchRecordsByPDC']); ?>" style="width: 50%;" /></p>
	            
        	</div>
        </div>

        <div class="postbox">
        	<div class="inside">
				<h4><?php _e('User Options'); ?></h4>
	            <p><?php _e('Hidden Roles'); ?><br />
	            <span class="description"><?php _e('Users with the following roles will be excluded from data transfers.'); ?></span></p>
	            <?php
				global $wp_roles;
				if (!isset($wp_roles)) {
					$wp_roles = new WP_Roles();
				}
				$options['hidden_roles'] = $plugin->make_array($options['hidden_roles']);
				foreach ($wp_roles->role_names as $key => $value) {
					echo '<label style="display: inline-block; width: 50%;"><input type="checkbox" name="'.$plugin->prefix.'_hidden_roles[]" value="'.$key.'"';
					if (in_array($key, $options['hidden_roles'])) {
						checked($key, $key);
					}
					echo '> '.$value.'</label>';
	            }
	            ?>
        	</div>
        </div>

        <div class="postbox">
        	<div class="inside">
				<h4><?php _e('User Field Mapping'); ?></h4>

	        	<p><input type="submit" value="<?php _e('Refresh fields from Zoho'); ?>" id="refresh_contacts_zoho_fields" class="button button-large" name="refresh_contacts_zoho_fields"></p>

	            <?php
				$zoho_fields = $plugin->get_contacts_zoho_fields(true);
				$wp_fields = $plugin->get_contacts_wp_fields();

				if (!empty($zoho_fields) && !empty($wp_fields)) :
        			$options['user_field_map'] = $plugin->make_array($options['user_field_map']);

	        		$wp_fields_select = function($zoho_field = '') use ($wp_fields, $options, $plugin) {
	        			$option = false;
	        			if (isset($options['user_field_map'][$zoho_field])) {
	        				$option = $options['user_field_map'][$zoho_field];
	        			}
	        			?>
						<select id="<?php echo $plugin->prefix.'_user_field_map_'.$zoho_field; ?>" name="<?php echo $plugin->prefix.'_user_field_map_'.$zoho_field; ?>">
							<option value=""><?php _e('&mdash;&mdash;'); ?></option>
							<?php foreach ($wp_fields as $section => $arr) : ?>
								<option value=""<?php disabled(true); ?>><?php echo esc_html($section); ?></option>
								<?php foreach ($arr as $key => $value) : ?>
									<option value="<?php echo esc_attr($key); ?>"<?php selected($key, $option); ?>><?php _e('&nbsp;&nbsp;'); ?> <?php echo esc_html($value); ?></option>
								<?php endforeach; ?>
							<?php endforeach; ?>
						</select>
						<?php
					};
	            	?>
		        	<table width="100%" border="0" cellpadding="5" cellspacing="0">
	    				<tr>
	    					<td valign="top" style="border-bottom:1px solid #ccc;"><strong><?php _e('Zoho Field'); ?></strong></td>
	    					<td valign="top" style="border-bottom:1px solid #ccc;"><strong><?php _e('Wordpress Field'); ?></strong></td>
	    				</tr>
		        		<?php
		        		foreach ($zoho_fields as $key => $value) {
		        			// section
		        			if (is_array($value)) {
		        				?>
		        				<tr>
		        					<td valign="top" style="border-top:1px solid #ccc;"><strong><?php echo $key; ?></strong></td>
		        					<td style="border-top:1px solid #ccc;"></td>
		        				</tr>
		        				<?php
		        				// fields
		        				foreach ($value as $field_key => $field_value) {
			        				?>
			        				<tr>
			        					<td valign="top" style="padding-left:2em;"><?php echo $field_value; ?></td>
			        					<td><?php $wp_fields_select($field_key); ?></td>
			        				</tr>
			        				<?php
		        				}
		        			}
		        			// field
		        			else {
		        				?>
		        				<tr>
		        					<td valign="top"><?php echo $value; ?></td>
		        					<td><?php $wp_fields_select($key); ?></td>
		        				</tr>
		        				<?php
		        			}
		        		}
		        		?>
		        	</table>
	        	<?php endif; ?>
        	</div>
        </div>


        <p class="submit">
            <input type="submit" value="Update" id="publish" class="button button-primary button-large" name="save">
        </p>

        </div><!-- poststuff -->
    	</form>

		</div><!-- wrap -->
		<?php
	}

	/* actions + filters */

	public function cron_action() {
		$active = $this->get_option('active', false);
		if (empty($active)) {
			return;
		}
		$cron = $this->get_option('cron', false);
		if (empty($cron)) {
			$this->cron_toggle(false);
			return;
		}
		$res = null;
		$cron_direct = $this->get_option('cron_direct', false);
		// execute in the action
		if (empty($cron_direct)) {
			if (!class_exists('WP_Zoho_Cron')) {
				@include_once(dirname(__FILE__).'/class-wp-zoho-cron.php');
			}
			$res = new WP_Zoho_Cron();
		}
		// execute by calling the file
		else {
			$url = plugin_dir_url(__FILE__).'class-wp-zoho-cron.php';
			if (function_exists('curl_init')) {
                $c = @curl_init();
                // try 'correct' way
                curl_setopt($c, CURLOPT_URL, $url);
                curl_setopt($c, CURLOPT_RETURNTRANSFER, true);
                curl_setopt($c, CURLOPT_FOLLOWLOCATION, true);
                curl_setopt($c, CURLOPT_MAXREDIRS, 10);
                $res = curl_exec($c);
                // try 'insecure' way
                if (empty($res)) {
                    curl_setopt($c, CURLOPT_URL, $url);
                    curl_setopt($c, CURLOPT_RETURNTRANSFER, true);
                    curl_setopt($c, CURLOPT_SSL_VERIFYPEER, false);
                    curl_setopt($c, CURLOPT_SSL_VERIFYHOST, 0);
					$user_agent = $this->plugin_title;
					if (isset($_SERVER["HTTP_USER_AGENT"]) && !empty($_SERVER["HTTP_USER_AGENT"])) {
						$user_agent = $_SERVER["HTTP_USER_AGENT"];
					}
                    curl_setopt($c, CURLOPT_USERAGENT, $user_agent);
                    $res = curl_exec($c);
                }
                curl_close($c);
			}
			if (empty($res)) {
				$cmd = 'wget -v '.$url.' >/dev/null 2>&1';
				@exec($cmd, $res);
			}
		}
	}

	public function edit_user_profile($profileuser = null) {
		if (!is_object($profileuser)) {
			return;
		}
		if (in_array($profileuser->ID, $this->exclude_users)) {
			return;
		}
		if ($this->user_has_hidden_role($profileuser->roles)) {
			return;
		}
		$has_cap = false;
		if (current_user_can('edit_users')) {
			$has_cap = true;
		}
		elseif (is_multisite() && current_user_can('manage_network_users')) {
			$has_cap = true;
		}
		if (!$has_cap) {
			return;
		}
		$usermeta_arr = $this->get_usermeta_array();
		$usermeta = get_user_meta($profileuser->ID, $this->prefix, true);
		$usermeta = $this->make_array($usermeta);
		$usermeta = array_merge( array_fill_keys($usermeta_arr, null), $usermeta );
		?>
<h2><?php echo $this->plugin_title; ?></h2>
<table class="form-table">
<?php
foreach ($usermeta_arr as $value) : 
?>
<tr>
	<th><label for="<?php echo $this->prefix; ?>_<?php echo $value; ?>"><?php echo $this->key2text($value); ?></label></th>
	<td><input type="text" name="<?php echo $this->prefix; ?>_<?php echo $value; ?>" id="<?php echo $this->prefix; ?>_<?php echo $value; ?>" class="regular-text" value="<?php echo esc_attr($usermeta[$value]); ?>" autocomplete="off" /></td>
</tr>
<?php
endforeach;
?>
</table>
		<?php
	}

	public function edit_user_profile_update($user_id = 0) {
		if (empty($user_id)) {
			return;
		}
		if (in_array($user_id, $this->exclude_users)) {
			return;
		}
		$userdata = get_userdata($user_id);
		if ($this->user_has_hidden_role($userdata->roles)) {
			return;
		}
		// usermeta
		$has_cap = false;
		if (current_user_can('edit_users')) {
			$has_cap = true;
		}
		elseif (is_multisite() && current_user_can('manage_network_users')) {
			$has_cap = true;
		}
		if ($has_cap) {
			$usermeta_arr = $this->get_usermeta_array();
			$usermeta = array();
			foreach ($usermeta_arr as $value) {
				$name = $this->prefix.'_'.$value;
				if (!isset($_POST[$name])) {
					continue;
				}
				if (empty($_POST[$name])) {
					continue;
				}
				$usermeta[$value] = $_POST[$name];
			}
			if (!empty($usermeta)) {
				update_user_meta($user_id, $this->prefix, $usermeta);
			}
			else {
				delete_user_meta($user_id, $this->prefix);
			}
		}

		// fire updates
		$actions = $this->get_option('actions', array());
		if (!in_array(__FUNCTION__, $actions)) {
			return;
		}
		$this->cron_contacts_update($user_id);
	}

	public function profile_update($user_id = 0, $old_user_data = null) {
		if (empty($user_id)) {
			return;
		}
		$actions = $this->get_option('actions', array());
		if (!in_array(__FUNCTION__, $actions)) {
			return;
		}
		$this->cron_contacts_update($user_id);
	}


	public function deleted_user($user_id = 0) {
		if (empty($user_id)) {
			return;
		}
		delete_user_meta($user_id, $this->prefix);
		$actions = $this->get_option('actions', array());
		if (!in_array(__FUNCTION__, $actions)) {
			return;
		}
		$this->cron_contacts_delete($user_id);
	}

	public function xprofile_updated_profile($user_id = 0, $posted_field_ids = array(), $errors = false, $old_values = array(), $new_values = array()) {
		if (empty($user_id)) {
			return;
		}
		$actions = $this->get_option('actions', array());
		if (!in_array(__FUNCTION__, $actions)) {
			return;
		}
		$this->cron_contacts_update($user_id);
	}

    /* functions */

	public function get_option($key = '', $default = array()) {
		if (!isset($this->option)) {
			if (is_multisite()) {
				$option = get_site_option($this->prefix, array());
			}
			else {
				$option = get_option($this->prefix, array());
			}
			$this->option = $option;
		}
		if (!empty($key)) {
			if (array_key_exists($key, $this->option)) {
				return $this->option[$key];
			}
			return $default;
		}
		return $this->option;
	}
	public function update_option($option) {
		if (is_multisite()) {
			$bool = update_site_option($this->prefix, $option);
		}
		else {
			$bool = update_option($this->prefix, $option);
		}
		if ($bool !== false) {
			$this->option = $option;
		}
		return $bool;
	}
	private function delete_option() {
		if (is_multisite()) {
			$bool = delete_site_option($this->prefix);
		}
		else {
			$bool = delete_option($this->prefix);
		}
		if ($bool !== false && isset($this->option)) {
			unset($this->option);
		}
		return $bool;
	}
	public function get_transient($transient) {
		if (is_multisite()) {
			$transient = substr($transient, 0, 167);
			$value = get_site_transient($transient);
		}
		else {
			$transient = substr($transient, 0, 172);
			$value = get_transient($transient);
		}
		return $value;
	}
	public function set_transient($transient, $value, $expiration = 0) {
		if (is_string($expiration)) {
			$expiration = strtotime('+'.$expiration) - time();
			if (!$expiration || $expiration < 0) {
				$expiration = 0;
			}
		}
		if (is_multisite()) {
			$transient = substr($transient, 0, 167);
			$bool = set_site_transient($transient, $value, $expiration);
		}
		else {
			$transient = substr($transient, 0, 172);
			$bool = set_transient($transient, $value, $expiration);
		}
		return $bool;
	}
	public function delete_transient($transient) {
		if (is_multisite()) {
			$transient = substr($transient, 0, 167);
			$bool = delete_site_transient($transient);
		}
		else {
			$transient = substr($transient, 0, 172);
			$bool = delete_transient($transient);
		}
		return $bool;
	}

    private function get_options_array() {
		return array(
			'active',
			'cron',
			'cron_direct',
			'actions',
			'admin_email',
			'zoho_api_limit',
			'zoho_authtoken',
			'zoho_xml_Contacts_getFields',
			'zoho_xml_Contacts_insertRecords',
			'zoho_xml_Contacts_updateRecords',
			'zoho_xml_Contacts_deleteRecords',
			'zoho_xml_Contacts_getRecordById',
			'zoho_xml_Contacts_getSearchRecordsByPDC',
			'hidden_roles',
			'user_field_map',
		);
    }
    private function get_usermeta_array() {
		return array(
			'zoho_id',
			'last_updated',
		);
    }

    public function user_has_hidden_role($roles = array()) {
    	$hidden_roles = $this->get_option('hidden_roles', array());
    	if (empty($hidden_roles)) {
    		return false;
    	}
    	$roles = $this->make_array($roles);
    	if (empty($roles)) {
    		return false;
    	}
    	foreach ($roles as $role) {
    		if (in_array($role, $hidden_roles)) {
    			return true;
    		}
    	}
		return false;
    }

	public function get_file_contents($url = '') {
		if (empty($url)) {
			return false;
		}
		$str = '';
		// use user_agent when available
		$user_agent = $this->plugin_title;
		if (isset($_SERVER["HTTP_USER_AGENT"]) && !empty($_SERVER["HTTP_USER_AGENT"])) {
			$user_agent = $_SERVER["HTTP_USER_AGENT"];
		}
		// try php
		$options = array('http' => array('user_agent' => $user_agent));
		// try 'correct' way
		if ($str_php = @file_get_contents($url, false, stream_context_create($options))) {
			$str = $str_php;
		}
		// try 'insecure' way
		if (empty($str)) {
			$options['ssl'] = array(
				'verify_peer' => false,
				'verify_peer_name' => false,
			);
			if ($str_php = @file_get_contents($url, false, stream_context_create($options))) {
				$str = $str_php;
			}
		}
		// try curl
		if (strpos($str, '<') === false) {
			if (function_exists('curl_init')) {
				$c = @curl_init();
				// try 'correct' way
				curl_setopt($c, CURLOPT_URL, $url);
                curl_setopt($c, CURLOPT_RETURNTRANSFER, true);
                curl_setopt($c, CURLOPT_FOLLOWLOCATION, true);
                curl_setopt($c, CURLOPT_MAXREDIRS, 10);
                $str = curl_exec($c);
                // try 'insecure' way
                if (empty($str)) {
                    curl_setopt($c, CURLOPT_URL, $url);
                    curl_setopt($c, CURLOPT_RETURNTRANSFER, true);
                    curl_setopt($c, CURLOPT_SSL_VERIFYPEER, false);
                    curl_setopt($c, CURLOPT_SSL_VERIFYHOST, 0);
                    curl_setopt($c, CURLOPT_USERAGENT, $user_agent);
                    $str = curl_exec($c);
                }
				curl_close($c);
			}
		}
		if (strpos($str, '<') === false) {
			return false;
		}
		if (strpos($str, '<error>') !== false) {
			return false;
		}
		return $str;
	}

	private function key2text($str) {
		$str = str_replace('_', ' ', $str);
		return trim(ucwords($str));
	}
	private function text2key($str) {
		$str = str_replace(' ', '_', $str);
		return trim($str);
	}

	private function array_keys_section_append($section = '', $arr = array()) {
		if (empty($section)) {
			return $arr;
		}
		$plugin = new self(false);
		$func = function($key) use ($section, $plugin) {
			return $plugin->text2key($section).$plugin->array_sep.$key;
		};
		$keys = array_map($func, array_keys($arr));
		return array_combine($keys, $arr);
	}

	public function get_contacts_zoho_fields($getFields = false) {
		if (!$getFields) {
			$fields = $this->get_transient($this->prefix.'_contacts_zoho_fields');
			$fields = $this->make_array($fields);
			$fields = apply_filters('wpzoho_contacts_zoho_fields', $fields);
			if (!empty($fields)) {
				return $fields;
			}
			return false;
		}

		$res = array();

		// check XML - this can be refreshed in admin
        $xml = $this->get_transient($this->prefix.'_xml_Contacts_getFields');

        // get XML
        if ($xml === false || empty($xml)) {
        	$res[] = __('Attempting to fetch Zoho fields.');
        	$zoho_authtoken = $this->get_option('zoho_authtoken', '');
        	if (empty($zoho_authtoken)) {
        		$res[] = 'Error: No Zoho Authtoken defined.';
        		echo implode('<br />', $res);
        		return false;
        	}
        	$zoho_xml_Contacts_getFields = $this->get_option('zoho_xml_Contacts_getFields', '');
        	if (empty($zoho_xml_Contacts_getFields)) {
        		$res[] = 'Error: No XML Contacts getFields defined.';
        		echo implode('<br />', $res);
        		return false;
        	}
        	$replace = array(
        		'###AUTHTOKEN###' => $zoho_authtoken,
        	);
        	$url = str_replace(array_keys($replace), $replace, $zoho_xml_Contacts_getFields);
    		if ($xml = $this->get_file_contents($url)) {
    			if ($this->set_transient($this->prefix.'_xml_Contacts_getFields', $xml)) {
	        		$res[] = 'Successfully fetched XML and stored it in the database.';
    			}
    			else {
	        		$res[] = 'Warning: Successfully fetched XML but could not store it in the database.';
    			}
    		}
    		else {
        		$res[] = 'Error: Could not fetch URL: '.$url;
        		echo implode('<br />', $res);
        		return false;
    		}
        }

    	$fields = array();
    	$keys = array();

    	// parse the XML and get the fields
		if (class_exists('SimpleXmlElement')) {
			$doc = new SimpleXmlElement($xml);
			foreach ($doc->section as $section) {
				$section_key = (string)$section->attributes()->name;
				$section_key = sanitize_text_field($section_key);
				$fields[$section_key] = array();
				foreach ($section->FL as $value) {
					$field = (string)$value->attributes()->label;
					if ($value->attributes()->req == 'true') {
						$field .= $this->required;
					}
					$field_key = sanitize_title($field);
					$i = 0;
					while (in_array($field_key, $keys)) {
						$field_key = rtrim($field_key, '-'.$i).'-'.++$i;
					}
					$keys[] = $field_key;
					$fields[$section_key][$field_key] = $field;
				}
			}
		}
		else {
			preg_match_all("/<FL [^>]*label=\"([^\"]+)\"/is", $xml, $matches);
			if (!empty($matches[1])) {
				$field_values = $matches[1];
				preg_match_all("/<FL [^>]*req=\"true\" [^>]*label=\"([^\"]+)\"/is", $xml, $matches);
				if (!empty($matches[1])) {
					foreach ($matches[1] as $value) {
						if (in_array($value, $field_values)) {
							if ($key = array_search($value, $field_values)) {
								$field_values[$key] .= $this->required;
							}
						}
					}
				}
				foreach ($field_values as $field) {
					$field_key = sanitize_title($field);
					$i = 0;
					while (in_array($field_key, $keys)) {
						$field_key = rtrim($field_key, '-'.$i).'-'.++$i;
					}
					$keys[] = $field_key;
					$fields[$field_key] = $field;
				}
			}
		}

		if ($this->set_transient($this->prefix.'_contacts_zoho_fields', $fields)) {
    		$res[] = 'Successfully parsed fields and stored them in the database.';
		}
		else {
    		$res[] = 'Warning: Successfully parsed fields but could not store them in the database.';
		}
        
		$fields = apply_filters('wpzoho_contacts_zoho_fields', $fields);

		if (empty($fields)) {
    		$res[] = 'Error: No Zoho fields found.';
    		echo implode('<br />', $res);
    		return false;
		}
		return $fields;
	}

	private function get_contacts_wp_fields() {
		$fields = array();

		// WP_Zoho
		$section = 'WP Zoho';
		$arr = $this->get_usermeta_array();
		$arr_values = array_map(array($this, 'key2text'), $arr);
		$arr = array_combine($arr, $arr_values);
		$fields[$section] = $this->array_keys_section_append($section, $arr);

		// WP_User
		$section = 'WP User';
		global $current_user;
		if (!empty($current_user)) {
			$arr = array();
			foreach ($current_user->data as $key => $value) {
				$arr[$key] = $key;
			}
			foreach ($current_user as $key => $value) {
				$arr[$key] = $key;
			}
			$arr = array_map(array($this, 'key2text'), $arr);
			$fields[$section] = $this->array_keys_section_append($section, $arr);
		}

		// WP_Usermeta
		$section = 'WP Usermeta';
		$plugin = new self(false);
		$exclude = function() use ($plugin) {
			$arr = $plugin->get_usermeta_array();
			$arr[] = $plugin->prefix;
			$quotes = function ($str) {
				return "'".$str."'";
			};
			$arr = array_map($quotes, $arr);
    		return implode(",", $arr);
		};
		global $wpdb;
		if ($arr = $wpdb->get_col("SELECT meta_key FROM $wpdb->usermeta WHERE meta_key NOT LIKE '".$wpdb->base_prefix."_%' AND meta_key NOT LIKE 'closedpostboxes_%' AND meta_key NOT LIKE 'metaboxhidden_%' AND meta_key NOT IN (".$exclude().") GROUP BY meta_key ASC")) {
			$arr = array_combine($arr, $arr);
			$arr = array_map(array($this, 'key2text'), $arr);
			$fields[$section] = $this->array_keys_section_append($section, $arr);
		}

		// Buddypress_XProfile
		$section = 'Buddypress XProfile';
		if ($this->has_bp) {
			if (bp_has_profile( array('fetch_field_data' => true, 'hide_empty_fields' => true) )) {
				$arr = array();
				while ( bp_profile_groups() ) {
					bp_the_profile_group();
					while ( bp_profile_fields() ) {
						bp_the_profile_field();
						$key = bp_get_the_profile_field_id();
						$arr[$key] = bp_get_the_profile_field_name();
					}
				}
				$fields[$section] = $this->array_keys_section_append($section, $arr);
			}
		}

		$fields = apply_filters('wpzoho_contacts_wp_fields', $fields);
		return $fields;
	}

	private function cron_contacts_update($user_id = 0) {
		if (empty($user_id)) {
			return;
		}
		$cron = $this->get_option('cron', false);
		if (empty($cron)) {
			return;
		}
		$arr = $this->get_transient($this->prefix.'_cron_contacts_update');
		$arr = $this->make_array($arr);

		// test function
		$plugin = new self(false);
		$func = function($user_id) use ($arr, $plugin) {
			if (in_array($user_id, $arr)) {
				return false;
			}
			if (in_array($user_id, $plugin->exclude_users)) {
				$plugin->cron_contacts_delete($user_id);
				return false;
			}
			$userdata = get_userdata($user_id);
			if ($plugin->user_has_hidden_role($userdata->roles)) {
				$plugin->cron_contacts_delete($user_id);
				return false;
			}
			return true;
		};

		if (is_array($user_id)) {
			foreach ($user_id as $id) {
				if ($func($id)) {
					$arr[] = $id;
				}
			}
		}
		else {
			if ($func($user_id)) {
				$arr[] = $user_id;
			}
		}
		if (empty($arr)) {
			return;
		}
		$this->set_transient($this->prefix.'_cron_contacts_update', $arr, '2 hours');
	}
	private function cron_contacts_delete($user_id = 0) {
		if (empty($user_id)) {
			return;
		}
		$cron = $this->get_option('cron', false);
		if (empty($cron)) {
			return;
		}
		$arr = $this->get_transient($this->prefix.'_cron_contacts_delete');
		$arr = $this->make_array($arr);

		// test function - use user_id => zoho_id pair, as meta may be deleted before cron can get it
		$plugin = new self(false);
		$func = function($user_id) use ($arr, $plugin) {
			if (array_key_exists($user_id, $arr)) {
				return false;
			}
			$usermeta = get_user_meta($user_id, $plugin->prefix, true);
			$usermeta = $plugin->make_array($usermeta);
			if (!isset($usermeta['zoho_id']) || empty($usermeta['zoho_id'])) {
				return false;
			}
			return $usermeta['zoho_id'];
		};

		if (is_array($user_id)) {
			foreach ($user_id as $id) {
				if ($zoho_id = $func($id)) {
					$arr[$id] = $zoho_id;
				}
			}
		}
		else {
			if ($zoho_id = $func($user_id)) {
				$arr[$user_id] = $zoho_id;
			}
		}
		if (empty($arr)) {
			return;
		}
		$this->set_transient($this->prefix.'_cron_contacts_delete', $arr, '2 hours');
	}

	public function cron_toggle($force = null) {
		$cron = false;
		// use option
		if (is_null($force)) {
			$option = $this->get_option('cron', false);
			if (!empty($option)) {
				$cron = true;
			}
		}
		elseif ($force) {
			$cron = true;
		}

		if (!$cron) {
			$this->delete_transient($this->prefix.'_cron_contacts_delete');
			$this->delete_transient($this->prefix.'_cron_contacts_update');
		}

		$timestamp = wp_next_scheduled($this->prefix.'_cron');
		if (!$cron && !$timestamp) {
			return;
		}
		elseif ($cron && $timestamp) {
			return;
		}
		elseif (!$cron && $timestamp) {
			wp_unschedule_event($timestamp, $this->prefix.'_cron');
			wp_clear_scheduled_hook($this->prefix.'_cron');
		}
		elseif ($cron && !$timestamp) {
			wp_clear_scheduled_hook($this->prefix.'_cron');
			wp_schedule_event(time(), 'hourly', $this->prefix.'_cron');
		}
	}

}
endif;
?>