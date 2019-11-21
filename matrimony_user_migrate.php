<?php
/*
* Plugin Name: Matrimony User Migration Plugin
* Description: Migrate Users and related data.
* Version: 1.0.0
* Plugin URI: 
*
* 
*/ 
// Exit if accessed directly
if( !defined( 'ABSPATH' ) ) exit;

if( !class_exists( 'matrimony_user_migrate' ) ):
class matrimony_user_migrate{

	public function instance(){
		add_action('admin_menu', array($this, 'plugin_admin_page'));
	}

	public function plugin_admin_page() {
		add_menu_page('Matrimony User Migration', 'User Migration', 'manage_options', 'user_migration', array($this, 'plugin_setting'));
		//add_filter('post_types_to_delete_with_user', array($this, 'exclude_articles'), 10, 2);
		add_action('admin_init', array($this, 'register_plugin_setting'));
	}

	public function exclude_articles(){
		$post_types_to_delete = get_post_types(array());
		unset($post_types_to_delete["post"]);
		unset($post_types_to_delete["attachment"]);
		return array_keys($post_types_to_delete);
	}

	public function register_plugin_setting(){
		global $wpdb;
		set_time_limit(1200);
		if (isset($_REQUEST['submit']) && $_REQUEST['submit'] == 'Remove'){
			$source_database 	= $_REQUEST['source_database'];
			$db_username 		= $_REQUEST['db_username'];
			$db_password	 	= $_REQUEST['db_password'];
			$db_prefix 			= $_REQUEST['db_prefix'];
			$src_upload_path 	= $_REQUEST['src_upload'];

			update_option('source_database', 	$source_database);
			update_option('db_username', 		$db_username);
			update_option('db_password', 		$db_password);
			update_option('db_prefix', 			$db_prefix);
			update_option('src_upload_path', 	$src_upload_path);

			$tb_usermeta = $wpdb->prefix . 'usermeta';
			$tb_user = $wpdb->prefix . 'users';
			$tb_postmeta = $wpdb->prefix . 'postmeta';
			$tb_posts = $wpdb->prefix . 'posts';
			$exclude_users = get_users([ 'role__in' => [ 'administrator' ] ]);
			$exclude_user_ids = array();
			foreach ($exclude_users as $key => $user) {
				$exclude_user_ids[] = $user->ID;
			}

			$exclude_user_ids_str = implode("', '", $exclude_user_ids);
			
			$post_types_to_delete = $this->exclude_articles();
			$post_types_to_delete = implode( "', '", $post_types_to_delete );

			$delete_postmeta_sql = "DELETE FROM $tb_postmeta WHERE post_id IN ( SELECT ID FROM $wpdb->posts WHERE post_author NOT IN ('$exclude_user_ids_str') AND post_type IN ('$post_types_to_delete') )";

			$wpdb->query($delete_postmeta_sql);

			$delete_posts_sql = "DELETE FROM $tb_posts WHERE post_author NOT IN ('$exclude_user_ids_str') AND post_type IN ('$post_types_to_delete')";
			$wpdb->query($delete_posts_sql);

			$delete_usermeta_sql = "DELETE FROM $tb_usermeta WHERE user_id NOT IN ('$exclude_user_ids_str')";
			$wpdb->query($delete_usermeta_sql);

			$delete_users_sql = "DELETE FROM $tb_user WHERE ID NOT IN ('$exclude_user_ids_str')";
			$wpdb->query($delete_users_sql);
		}else if (isset($_REQUEST['submit']) && $_REQUEST['submit'] == 'Migrate'){
			$source_database 	= $_REQUEST['source_database'];
			$db_username 		= $_REQUEST['db_username'];
			$db_password	 	= $_REQUEST['db_password'];
			$db_prefix 			= $_REQUEST['db_prefix'];
			$src_upload_path 	= $_REQUEST['src_upload'];
			$dst_upload_path 	= wp_upload_dir();
			$dst_upload_dir 	= $dst_upload_path['basedir'];
			$infoCheck = (!empty($source_database) && !empty($db_username) && !empty($db_prefix));
			if ($infoCheck){
				set_time_limit(1200);
				$tb_usermeta = $wpdb->prefix . 'usermeta';
				$tb_user = $wpdb->prefix . 'users';
				$tb_postmeta = $wpdb->prefix . 'postmeta';
				$tb_posts = $wpdb->prefix . 'posts';
								
				$mydb = new wpdb($db_username, $db_password, $source_database, 'localhost');
				$tb_src_user = $db_prefix.'users';
				$tb_src_usermeta = $db_prefix.'usermeta';
				$tb_src_posts = $db_prefix.'posts';
				$tb_src_postmeta = $db_prefix.'postmeta';
				
				$users = $mydb->get_results("SELECT * FROM $tb_src_user WHERE ID <> 1", "ARRAY_A");
				$new_user_array = array();
				$insert_user_qry = "";
				$last_id = $wpdb->get_var("SELECT AUTO_INCREMENT FROM information_schema.tables WHERE table_name = '{$tb_user}' AND table_schema = '{$wpdb->dbname}'");

				$plugin_dir = dirname(__FILE__);

				$dbfile = fopen($plugin_dir."/db_dump.sql", "w");
				fwrite($dbfile, "INSERT INTO $tb_user VALUES ");
				foreach ($users as $user) {
					$curUserId = $user['ID'];
					unset($user['ID']);
					foreach ($user as $key => $user_val) {
						$user[$key] = addslashes($user_val);
					}
					$value_array = array_values($user);
					$temp = ",";
					if ($insert_user_qry == ""){
						$temp = "";
					}
					$insert_user_qry = $temp . "\n($last_id, '".implode("', '", $value_array)."')";
					fwrite($dbfile, $insert_user_qry);
					$new_user_array[$curUserId] = $last_id;
					$last_id++;
				}
				
				fwrite($dbfile, ";\n");
				$usermeta = $mydb->get_results("SELECT * FROM $tb_src_usermeta WHERE user_id IN ( SELECT ID FROM $tb_src_user WHERE ID <> 1 )", "ARRAY_A");
				fwrite($dbfile, "INSERT INTO $tb_usermeta VALUES ");
				$insert_usermeta_qry = "";
				$last_id = $wpdb->get_var("SELECT AUTO_INCREMENT FROM information_schema.tables WHERE table_name = '{$tb_usermeta}' AND table_schema = '{$wpdb->dbname}'");
				$index = 0;
				foreach ($usermeta as $meta) {
					$meta['user_id'] = $new_user_array[$meta['user_id']];
					unset($meta['umeta_id']);
					foreach ($meta as $key => $meta_val) {
						$meta[$key] = addslashes($meta_val);
					}
					if ($index == 2000){
						fwrite($dbfile, ";\n");
						fwrite($dbfile, "INSERT INTO $tb_usermeta VALUES ");
						$insert_usermeta_qry = "";
						$index = 0;
					}
					$value_array = array_values($meta);
					$temp = ",";
					if ($insert_usermeta_qry == ""){
						$temp = "";
					}
					$insert_usermeta_qry = $temp . "\n($last_id, '".implode("', '", $value_array)."')";
					//$wpdb->insert($tb_usermeta, $meta);
					fwrite($dbfile, $insert_usermeta_qry);
					$last_id++;
					$index++;
				}

				fwrite($dbfile, ";\n");

				$posts = $mydb->get_results("SELECT * FROM $tb_src_posts WHERE post_author IN (SELECT ID FROM $tb_src_user WHERE ID <> 1)", "ARRAY_A");
				$last_id = $wpdb->get_var("SELECT AUTO_INCREMENT FROM information_schema.tables WHERE table_name = '{$tb_posts}' AND table_schema = '{$wpdb->dbname}'");
				$post_id_array = array();
				$new_post_array = array();
				fwrite($dbfile, "INSERT INTO $tb_posts VALUES ");
				$insert_usermeta_qry = "";
				foreach ($posts as $post) {
					$post_id_array[] = $curPostId = $post['ID'];
					unset($post['ID']);
					foreach ($post as $key => $post_val) {
						$post[$key] = addslashes($post_val);
					}
					$value_array = array_values($post);
					$temp = ",";
					if ($insert_usermeta_qry == ""){
						$temp = "";
					}
					$insert_usermeta_qry = $temp . "\n($last_id, '".implode("', '", $value_array)."')";
					fwrite($dbfile, $insert_usermeta_qry);
					$new_post_array[$curPostId] = $last_id;
					$last_id++;
					
				}

				fwrite($dbfile, ";\n");

				$src_post_ids = implode("', '", $post_id_array);
				$post_metas = $mydb->get_results("SELECT * FROM $tb_src_postmeta WHERE post_id IN ('$src_post_ids')", "ARRAY_A");
				$last_id = $wpdb->get_var("SELECT AUTO_INCREMENT FROM information_schema.tables WHERE table_name = '{$tb_postmeta}' AND table_schema = '{$wpdb->dbname}'");

				fwrite($dbfile, "INSERT INTO $tb_postmeta VALUES ");
				$insert_usermeta_qry = "";
				$index = 0 ;
				foreach ($post_metas as $postmeta) {
					unset($postmeta['meta_id']);
					$postmeta['post_id'] = $new_post_array[$postmeta['post_id']];

					foreach ($postmeta as $key => $meta_val) {
						$postmeta[$key] = addslashes($meta_val);
					}
					if ($index == 2000){
						fwrite($dbfile, ";\n");
						fwrite($dbfile, "INSERT INTO $tb_postmeta VALUES ");
						$insert_usermeta_qry = "";
						$index = 0;
					}
					$value_array = array_values($postmeta);
					$temp = ",";
					if ($insert_usermeta_qry == ""){
						$temp = "";
					}
					$insert_usermeta_qry = $temp . "\n($last_id, '".implode("', '", $value_array)."')";
					fwrite($dbfile, $insert_usermeta_qry);
					$last_id++;
					$index++;
				}

				fwrite($dbfile, ";\n");
				fclose($dbfile);

				$attachments = $mydb->get_results("SELECT * FROM $tb_src_posts WHERE post_author IN (SELECT ID FROM $tb_src_user WHERE ID <> 1) AND post_type='attachment'", "ARRAY_A");
				foreach ($attachments as $attachment) {
					$origin_file_path = $mydb->get_var("SELECT meta_value FROM $tb_src_postmeta WHERE post_id={$attachment['ID']} AND meta_key='_wp_attached_file'");
					$src_file = $src_upload_path.$origin_file_path;
					$dst_file = $dst_upload_dir."/".$origin_file_path;
					$dst_file = dirname($dst_file)."/";
					$src_path = dirname($src_file);
					$src_file = str_replace("/", "\\", $src_file);
					$dst_file = str_replace("/", "\\", $dst_file);
					shell_exec("xcopy  $src_file $dst_file /Y");
					$thumb_info = $mydb->get_var("SELECT meta_value FROM $tb_src_postmeta WHERE post_id={$attachment['ID']} AND meta_key='_wp_attachment_metadata'");
					$thumb_array = unserialize($thumb_info);
					if (is_array($thumb_array) && isset($thumb_array['sizes'])){
						foreach ($thumb_array['sizes'] as $size_array) {
							$src_file_name = $size_array['file'];
							$thumb_file = $src_path."/".$src_file_name;
							$thumb_file = str_replace("/", "\\", $thumb_file);
							shell_exec("xcopy  $thumb_file $dst_file /Y");
						}
					}
				}
				$fileName = dirname(__FILE__)."/db_dump.sql";
				$fileName = str_replace("/", "\\", $fileName);
				
				$sqlCmd = "mysql -u{$wpdb->dbuser} -p{$wpdb->dbpassword} $wpdb->dbname < {$fileName} 2>&1";
				if ($wpdb->dbpassword == ""){
					$sqlCmd = "mysql -u{$wpdb->dbuser} $wpdb->dbname < {$fileName} 2>&1";
				}
				$result = exec($sqlCmd);
			}
		}
	}

	public function plugin_setting(){
?>
		<div class="wrap">
			<h2>Matrimony User Migration</h2>
			<form action="" method="post" id="user_migrate_settings">
		<?php 
			settings_fields( 'user_migrate_settings' ); 
			do_settings_sections( 'user_migrate_settings' );
			if ((isset($_REQUEST['submit']) && $_REQUEST['submit'] == 'Migrate') && (empty($_REQUEST['source_database']) || empty($_REQUEST['db_username']) || empty($_REQUEST['db_prefix']) || empty($_REQUEST['src_upload']))) {
				echo '<div class="update-nag">Please fill input fields!</div>';
			}
		?>
				<table class="form-table">
					<tbody>
						<tr>
							<th><label for="source_database">Source Database</label></th>
							<td><input type="text" name="source_database" id="source_database" class="regular-text" value="<?php echo esc_attr( get_option('source_database') ) ?>" /></td>
						</tr>
						<tr>
							<th><label for="db_username">Database User</label></th>
							<td><input type="text" name="db_username" id="db_username" class="regular-text" value="<?php echo esc_attr( get_option('db_username') ) ?>" /></td>
						</tr>
						<tr>
							<th><label for="db_password">Database Password</label></th>
							<td><input type="password" name="db_password" id="db_password" class="regular-text" value="<?php echo esc_attr( get_option('db_password') ) ?>" /></td>
						</tr>
						<tr>
							<th><label for="db_prefix">Table Prefix</label></th>
							<td><input type="text" name="db_prefix" id="db_prefix" class="regular-text" value="<?php echo esc_attr( get_option('db_prefix') ) ?>" /></td>
						</tr>
						<tr>
							<th><label for="src_upload">Source Upload Directory Path(Physical)</label></th>
							<td><input type="text" name="src_upload" id="src_upload" class="regular-text" value="<?php echo esc_attr( get_option('src_upload_path') ) ?>" /></td>
						</tr>
					</tbody>
				</table>
				<p class="submit"><input type="submit" name="submit" value="<?php echo !isset($_REQUEST['submit'])?'Remove':'Migrate';?>" /></p>
			</form>
		</div>
<?php
	}
}

$matrimony_user=new matrimony_user_migrate(); 
$matrimony_user->instance();

endif;