<?php
/*
	Plugin Name: Wordpress LDAP User Import
	Author: Tony Thomas
	Version: 0.1
	Description: Plugin to import users from LDAP and add it to Wordpress MU and the main blog
*/
/**
* Add a page in the settings page that provides the option to click and begin the process
*/
add_action('admin_init', 'wlui_init');
add_action('admin_menu', 'wlui_options_page');
/**
* Adds settings page of WLUI to menu
*/
function wlui_options_page () {
	add_options_page('Import Users', 'Import Users', 'add_users', 'wlui_plugin', 'wlui_display_page');
}
function wlui_display_page() {
?>
<div>
	<h1>Wordpress LDAP User Import</h1>
	<form action = "options.php" method = "POST">
		<?php settings_fields('wlui_options'); ?>
		<?php do_settings_sections('wlui_plugin'); ?>
		<?php submit_button(); ?>
	</form>
	<form action = "" method = "POST">
		<?php submit_button("Test Connection", "secondary", "test-connection"); ?>
	</form>
<?php
	if(isset($_POST['test-connection'])){
		$options = get_option('wlui_options');
		$con = ldap_connect($options['ldap_address']);
		if($con) {
			if($bind = ldap_bind($con, $options['user'], $options['password'])) {
				echo "Connection Successful";
			}
			else
			{
				echo "Connection Successful but Bind error, check username and password";
			}
		}
		else {
			echo "Connection Error, Check LDAP Address";
		}
	}
?>
	<form action = "" method = "POST">
		<?php submit_button("Import", "primary", "import-users"); ?>
	</form>

	<?php
	if( isset( $_POST['import-users'] ) ) {
		wlui_user_import();
	}
}
/**
* Initializations of settings and plugin
*/
function wlui_init() {
//Plugin Inits
register_setting('wlui_options', 'wlui_options', 'wlui_options_validate');
add_settings_section('wlui_main', 'Main Settings', 'wlui_main_display', 'wlui_plugin');
add_settings_field('wlui_ldap_address', 'Ldap Address', 'wlui_ldap_address_display', 'wlui_plugin', 'wlui_main');
add_settings_field('wlui_base', 'Base', 'wlui_base_text', 'wlui_plugin', 'wlui_main');
add_settings_field('wlui_user', 'User Name', 'wlui_user_text', 'wlui_plugin', 'wlui_main');
add_settings_field('wlui_password', 'Password', 'wlui_user_password', 'wlui_plugin', 'wlui_main');
add_settings_field('wlui_count', 'Max Import', 'wlui_count_text', 'wlui_plugin', 'wlui_main');
}
function wlui_main_display() {
	echo '<p>Enter the LDAP details</p>';
}
function wlui_count_text() {
	$options = get_option('wlui_options');
	echo '<input type = "text" name = "wlui_options[max_count]" value = "'.$options['max_count'].'"/>';
}
function wlui_ldap_address_display() {
	$options = get_option('wlui_options');
	echo '<input type = "text" name = "wlui_options[ldap_address]" value = "'.$options['ldap_address'].'" />';
}
function wlui_base_text() {
	$options = get_option('wlui_options');
	echo '<input type = "text" name = "wlui_options[base_dn]" value = "'.$options['base_dn'].'"/>';
}
function wlui_user_text() {
	$options = get_option('wlui_options');
	echo '<input type = "text" name = "wlui_options[user]" value = "'.$options['user'].'"/>';
}

function wlui_user_password() {
	$options = get_option('wlui_options');
	echo '<input type = "password" name = "wlui_options[password]" value = "'.$options['password'].'"/>';
}
function wlui_options_validate($input) {
$input['ldap_address'] = str_replace('ldap://','', $input['ldap_address']);
if(!preg_match('/(?:[A-Za-z]+=[A-Za-z0-9]+,*)+/', $input['base_dn']))
{
	$input['base_dn'] = "";
}
if(!is_numeric($input['max_count']) || $input['max_count'] == "" || $input['max_count'] == NULL)
{
	$input['max_count'] = 0;
}
return $input;
}
/**
* Imports user and adds to main blog
* in WPMU
*/
function wlui_user_import() {
	delete_transient( 'bp_active_member_count' );
	$options	=	get_option('wlui_options');
	if( $con = ldap_connect($options['ldap_address']))
	{
		if(	$bind = ldap_bind($con, $options['user'],  $options['password']))
		{
			$results = ldap_search( $con, $options['base_dn'], "objectClass=person");
			if( $results )
			{
				$entry = ldap_first_entry ($con, $results);
				$count = 0;
				do
				{
						$values = ldap_get_attributes( $con, $entry );
						if(!(array_key_exists( 'mail', $values) && array_key_exists( 'department', $values ) && array_key_exists( 'displayName', $values ) ) ) {
							continue;
						}

						if((!email_exists( $values['mail'][0]) ) && ($values['department'][0] != "Inactive User Groups"))
						{

							$user_id = wpmu_create_user( strtolower($values['sAMAccountName'][0]), "password", $values['mail'][0]);
							if ( $user_id == 1 || $user_id === False ) {
								echo "User ID invalid. User ID = ". $values['sAMAccountName'][0];
								echo " Mail ID: ".$values['mail'][0];
								continue;
							}
							wp_update_user(array ( 'ID' => $user_id, 'user_nicename' => str_replace('.','-',$values['sAMAccountName'][0]), 'nickname' => $values['displayName'][0],'display_name' => $values['displayName'][0],'first_name' => $values['givenName'][0], 'last_name' => $values['sn'][0]));
							add_user_to_blog(1, $user_id, 'Subscriber');
							bp_core_map_user_registration( $user_id );
							bp_core_new_user_activity ( $user_id );
							update_user_meta( $user_id, "ldap_login", "true");
							update_user_meta( $user_id, 'last_activity', bp_core_current_time() );
	$department	=	$values['department'][0];
	$departmentSlug       =       str_replace(" ", "-", strtolower($department));
       $group_id = groups_get_id($departmentSlug);
      if($group_id == 0)$group_id = groups_create_group(
       array('creator_id'=>1,
        'name'=>$values['department'][0],
        'slug'=>$departmentSlug,
        'description'=>'This is the '.$values['department'][0].' group',
        'date_created'=> bp_core_current_time(),
	'status'=> 'public'
       )
      );
      groups_join_group($group_id,$user_id);
							xprofile_set_field_data(2, $user_id, "A short introduction");
							xprofile_set_field_data(3, $user_id, "-");
							xprofile_set_field_data(5, $user_id, "-");
							xprofile_set_field_data(4, $user_id, "-");
							xprofile_set_field_data(6, $user_id, "-");
							xprofile_set_field_data(7, $user_id, "-");
							xprofile_set_field_data(8, $user_id, "-");
							xprofile_set_field_data(9, $user_id, "-");
							$count++;
						}
				}while (($entry = ldap_next_entry($con, $entry)) && $count < $options['max_count']);
			$message = ($count>1)?$count. " users have been imported":"One user has been imported";
			echo $message;
			}
		}
		else {
			echo "Bind Failed";
		}
	}
	else {
		echo "Connection Failed";
	}
}
?>
