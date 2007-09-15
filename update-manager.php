<?php
/*
Plugin Name: Update Manager
Plugin URI: http://wordpress.org/extend/plugins/update-manager/
Description: Displays update notifications for Wordpress plugins.
Author: Martin Fitzpatrick
Version: 1.8
Author URI: http://www.mutube.com
*/

/*  Copyright 2006  MARTIN FITZPATRICK  (email : martin.fitzpatrick@mutube.com)

    This program is free software; you can redistribute it and/or modify
    it under the terms of the GNU General Public License as published by
    the Free Software Foundation; either version 2 of the License, or
    (at your option) any later version.

    This program is distributed in the hope that it will be useful,
    but WITHOUT ANY WARRANTY; without even the implied warranty of
    MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
    GNU General Public License for more details.

    You should have received a copy of the GNU General Public License
    along with this program; if not, write to the Free Software
    Foundation, Inc., 59 Temple Place, Suite 330, Boston, MA  02111-1307  USA
*/


/*
  INITIALISATION
  All functions in here called at startup (after other plugins have loaded)
*/

if (!function_exists('updatem_init')) {
function updatem_init() {

/*
	SUPPORT FUNCTIONS
*/

	/* Returns true if upgrade plugin has a higher version than the current, false otherwise */
	function updatem_is_upgrade($current,$upgrade)
	{
	return 	( ($upgrade['version_major']>$current['version_major']) ||
			( ($upgrade['version_major']==$current['version_major']) &&  ($upgrade['version_minor']>$current['version_minor']) ) );
	}

	/* Strip filename from /path/to/plugin/filename.ext */
	function updatem_strip_filename($plugin_file)
	{
		$fn=strpos($plugin_file,'/');
		$fx=strrpos($plugin_file,'.');
				
		if($fn===false){$fn=0;} else {$fn++;}
		if($fx===false){$fx=strlen($plugin_file);}
					
		return substr($plugin_file,$fn,$fx-$fn);
	}

	/* Fetch remote url, using cURL if available or fallback to file_get_contents */
	function updatem_fetch_url($url)
	{
		global $updatem_debug;

		/* Use cURL if it is available, otherwise attempt fopen */
		if(function_exists('curl_init'))
		{ 

			/*	
				Request data using cURL library
				With thanks to Marcin Juszkiewicz
				http://www.hrw.one.pl/
			*/
						
			$ch = curl_init();

			// set URL and other appropriate options
			curl_setopt($ch, CURLOPT_URL, $url);
			curl_setopt($ch, CURLOPT_HEADER, 0);
			curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
			
			// grab URL and pass it to the browser
			$data = @curl_exec($ch);
		
			if (curl_errno($ch)) {
				array_push($updatem_debug,curl_error($ch));
				$data=false;
			}

 			// close curl resource, and free up system resources
 			curl_close($ch);

		} else { $data=@file_get_contents ( $url ); } /* If cURL is not installed use file_get_contents */

 		return $data;
	}


/*
   OUTPUT FUNCTIONS
*/


	/* Output results of upgrade lookup (both successful upgrades & not found */

	function show_results($plugins)
	{
		$style = '';
		sort($plugins);

		?><table width="100%" cellpadding="3" cellspacing="3">
		<thead>

		<tr>
			<th><?php _e('Plugin'); ?></th>
			<th><?php _e('Description'); ?></th>
			<th><?php _e('Installed'); ?></th>
			<th><?php _e('Available'); ?></th>
			<th><?php _e('Link'); ?></th>
		</tr>	
		<?php

		foreach($plugins as $plugin_file => $plugin_data) {
		
			$style = ('class="alternate"' == $style|| 'class="alternate active"' == $style) ? '' : 'alternate';

			$plugin_data['Description'] = wp_kses($plugin_data['Description'], array('a' => array('href' => array(),'title' => array()),'abbr' => array('title' => array()),'acronym' => array('title' => array()),'code' => array(),'em' => array(),'strong' => array()) ); ;
			if ($style != '') $style = 'class="' . $style . '"';
			echo "
			<tr $style>
			<td class='name'>{$plugin_data['Title']}</td>
			<td class='desc'><p>{$plugin_data['Description']} <cite>".sprintf(__('By %s'), $plugin_data['Author']).".</cite></p></td>
			<td class='vers'>{$plugin_data['Version']}</td>";

			echo "<td class='vers'>" . $plugin_data['Updated_Version'] . "</td>";

			echo "<td>";
			echo '<a href="' . $plugin_data['Homepage_URL'] . '">Homepage</a>';
			echo "</td></tr>";

			
		}
		?></table><?php
	}

/*
	UPDATE MANAGER - Output Results Admin/Plugin
*/
    function updatem_manager() {
	
		global $updatem_debug;
		$updatem_debug=array();

		?>
		<div class="wrap">
        <h2>Update Manager</h2>
		<?php


		if ( $_GET['updatem-refresh'] ) {

			// Here is our options page

			$plugins = get_plugins();
			$plugins_updated = array();
			$plugins_unchecked = array();
			$options = get_option('plugin_updatemanager');

			if (empty($plugins)) {
				array_push($updatem_debug,__("Couldn't open plugins directory or there are no plugins available."));	
			} else {

				foreach ($plugins as $plugin_file => $plugin_data) {

					//Skip Wordpress' included plugins (from Automattic, Inc.)
					if((strpos($plugin_data['Author'],'Automattic, Inc.')!==false)
					 || ($plugin_data['Name']=='WordPress Database Backup')
					 || ($plugin_data['Name']=='Hello Dolly') ){continue;}

					/* Sub-divide version into major.minor combination, re-insert into plugin array */
					list($plugin_data['version_major'],$plugin_data['version_minor'])=explode('.',$plugin_data['Version']);
					sscanf($plugin_data['Title'], '<a href="%s"',$plugin_data['Homepage_URL']);													
					
					//Use plugin FILENAME (minus directory/ext) as request
					//filter instead of name.  Wp-plugins.net asks specifically for 
					//this as a "Short Name" when uploading. More accurate results.
					$dir_name=updatem_strip_filename($plugin_file);
							
					//REQUEST UPDATED DATA
					//First attempt, to use dir-name of the plugin to find plugins (more accurate)
					$data = updatem_fetch_url ( 'http://wp-plugins.net/get_plugin_data.php?filter=' . urlencode($dir_name) );

					//If that fails, re-request using the plugin's homepage to get a full list to parse
					if ($data=='a:0:{}'){ 
						/* Extract plugin host from Title URL */
						sscanf($plugin_data['Title'], '<a href="http://%[^/"]s',$plugin_data['Hostname']);
						if($plugin_data['Hostname']!='')
							{ $data = updatem_fetch_url ( 'http://wp-plugins.net/get_plugin_data.php?filter=' . $plugin_data['Hostname'] ); }
					}

					$plugins_request_success_this=false;

					if ($data===false){
 						array_push($updatem_debug,"Request failed for '" . $plugin_data['Name'] . "'");
						
					} else {
						
						$wwwdata=unserialize($data);
					
						/*	At this point $wwwdata will only contain an array if valid data
						was returned by the wp-plugins server */

						if (!is_array($wwwdata)){
							$errorcount++; array_push($updatem_debug,"Bad data returned from server for request '" . $dir_name . "'");}
						else {

							foreach($wwwdata as $record) {
								
								//Make sure this is the SAME plugin
								if(($record['dir_name']==$dir_name) || ($record['plugin_name']==$plugin_data['Name'])){

									$plugins_request_success=true;
									$plugins_request_success_this=true;
	
									//Is the available version newer than what's installed?
									if (is_upgrade($plugin_data,$record)) { 			
					
										$plugins_updated[$plugin_file]=$plugin_data;
										$plugins_updated[$plugin_file]['Updated_Version'] = $record['version_major'] . '.' . $record['version_minor'];
										$plugins_updated[$plugin_file]['Description'] = $record['description'];
										/* $plugins_updated[$plugin_file]['Updated_URL'] = "http://www.wp-plugins.net/plugin/" . $record['dir_name'] . "/#plugin_" . $record['plugin_id']; */

										break; //leave the foreach loop, we've found the record

									}

								} 

							} /* foreach record returned*/



						} /* is_array returned*/

					if($plugins_request_success_this==false){
						$plugin_data['Updated_Version']='?';
						$plugins_unchecked[$plugin_file]=$plugin_data;						
					}

					} /* data good */
	
				} /* foreach plugin */

			} /* !is_empty plugins */

			if(count($plugins_unchecked)==0){
				if(count($plugins_updated)==0){ 
					/* All checked, no upgrades */
					?><p>All your plugins are currently <strong>up to date</strong>.</p><?php	
				} else {
					/* All checked, some upgrades */
					?><p>Upgrades are available for <strong><?php echo count($plugins_updated); ?></strong> of your plugins. Click the Download links for more information.</p><?php	
					show_results($plugins_updated);
				}
			} else {
				if(count($plugins_updated)==0){
					/* Some checked, no upgrades */
					?><p>Checked plugins were all <strong>up to date</strong>.</p><?php	
					?><p>The <strong><?php echo count($plugins_unchecked); ?></strong> plugins listed below were <strong>not available</strong> on <a href="http://www.wp-plugins.net">wp-plugins</a> and could not be checked.<br/>Please visit the plugin's homepage to check for upgrades.</p><?php
					show_results($plugins_unchecked);
					
				} else {
					/* Some checked, some upgrades */
					?><p>Upgrades are available for <strong><?php echo count($plugins_updated); ?></strong> of your plugins. Click the Download links for more information.</p><?php					
					show_results($plugins_updated);
					?><p>The <strong><?php echo count($plugins_unchecked); ?></strong> plugins listed below were <strong>not available</strong> on <a href="http://www.wp-plugins.net">wp-plugins</a> and could not be checked.<br/>Please visit the plugin's homepage to check for upgrades.</p><?php
					show_results($plugins_unchecked);
				}
			}

			if(count($updatem_debug)>0) {
				?><p>There were <strong><?php echo count($updatem_debug); ?></strong> errors during the update process. For debug information.
				<a href="#" onclick="document.getElementById('updm-debug').style.display = 'block';">click here</a></p>
				<ul id="updm-debug" style="display:none;">
				<?php 
					foreach($updatem_debug as $bug){
						echo "<li>" . $bug . "</li>"; 
					} 
				?>
				</ul><?php

			}
		

		} else {

		?><p>Please wait while the Update Manager <a href="?page=update-manager&updatem-refresh=true">collects plugin data from wp-plugins...</a>
		<script type="text/javascript">
		function UpdateManagerRefresh() { window.location = '?page=update-manager&updatem-refresh=true';}
		wpOnload=setTimeout(UpdateManagerRefresh,100); //--></script>
		<?php
		

		}
		?></div></form></div><?php

        }


/*
	UPDATE MANAGER - Output Results Admin/Plugin

    function updatem_options() {
	
		?>
		<div class="wrap">
        <h2>Update Manager Options</h2>
		<div style="margin:20px; 0px">
        <form action="" method="post">
		<p>Use the options below to control how Update Manager searches, finds &amp; reports upgrades to you.</p>
		<?php

		// Get our options and see if we're handling a form submission.
		$options = get_option('plugin_updatemanager');
		if ( !is_array($options) )
			{$options = array('search'=>'filename');}

		if ( $_POST['updatemanager-submit'] ) {
			// Remember to sanitize and format use input appropriately.
			$options['search'] = strip_tags(stripslashes($_POST['searchwith']));
			update_option('plugin_updatemanager', $options);
		}

		// Be sure you format your options to be valid HTML attributes.
		$options['search'] = htmlspecialchars($options['search'], ENT_QUOTES);

		// Here is our little form segment. Notice that we don't need a
		// complete form. This will be embedded into the existing form.
		
		?><p style="height:25px;"><label for="updatemanager-search">Search Method</label>
		<select id="updatemanager-search">
		<option <?php if($options['search']=='filename'){?>selected<?php } ?> value="filename">Plugin Filename</option>
		<option <?php if($options['search']=='url+name'){?>selected<?php } ?> value="url+name">Homepage URL &amp; Plugin Name</option>
		</select>
		<input type="hidden" id="updatemanager-submit" name="updatemanager-submit" value="1" />
		<p class="submit"><input type="submit" value="Save changes &raquo;"></p>
        </form></div></div>
		<?php

	}
*/
        function updatem_add_pages()
        {
			add_submenu_page('plugins.php',"Update Manager", "Update Manager", 10, "update-manager", updatem_manager);
/*			add_options_page("Update Manager Options", "Update Manager", 10, "update-manager-options", updatem_options); */
        }

        add_action('admin_menu', 'updatem_add_pages');


}
}

// Run our code later in case this loads prior to any required plugins.
add_action('plugins_loaded', 'updatem_init');

?>
