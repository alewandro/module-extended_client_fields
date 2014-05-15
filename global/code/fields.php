<?php


/**
 * Adds a new field to the database.
 */
function ecf_add_field($info)
{
  global $g_table_prefix, $L;
	
  $info = ft_sanitize($info);

	// get the next highest
	$query = mysql_query("SELECT field_order FROM {$g_table_prefix}module_extended_client_fields ORDER BY field_order DESC LIMIT 1");
	$result = mysql_fetch_assoc($query);

	$next_order = 1;
	if (!empty($result))
	  $next_order = $result["field_order"] + 1;

	$is_required = (isset($info["is_required"])) ? "yes" : "no"; 

	// add the main record first
  $query = mysql_query("
	  INSERT INTO {$g_table_prefix}module_extended_client_fields (template_hook, admin_only, field_label, field_type, 
		  field_orientation, default_value, is_required, error_string, field_order)
		VALUES ('{$info["template_hook"]}', '{$info["admin_only"]}', '{$info["field_label"]}', '{$info["field_type"]}', 
		  '{$info["field_orientation"]}', '{$info["default_value"]}', '$is_required', '{$info["error_string"]}', $next_order)
		  ");

  $client_field_id = mysql_insert_id();

  // if this field had multiple options, add them too
  if ($info["field_type"] == "select" || $info["field_type"] == "multi-select" || 
	    $info["field_type"] == "radios" || $info["field_type"] == "checkboxes")
  {
	  for ($i=1; $i<=$info["num_rows"]; $i++)
		{
      if (!isset($info["field_option_text_$i"]) || empty($info["field_option_text_$i"]))
			  continue;
			
			$option_text = $info["field_option_text_$i"];

		  mysql_query("
			  INSERT INTO {$g_table_prefix}module_extended_client_field_options 
				  (client_field_id, option_text, field_order)
				VALUES ($client_field_id, '$option_text', $i)
				  ");
		}
	}

  $message = ft_eval_smarty_string($L["notify_field_added"], array("client_field_id" => $client_field_id));
	return array(true, $message);
}


/**
 * Updates an extended field in the database.
 */
function ecf_update_field($client_field_id, $info)
{
  global $g_table_prefix, $L;
	
  $info = ft_sanitize($info);

	$is_required = (isset($info["is_required"])) ? "yes" : "no"; 

	// here, we add a record 
  $query = mysql_query("
	  UPDATE {$g_table_prefix}module_extended_client_fields 
		SET    template_hook = '{$info["template_hook"]}', 
		       admin_only = '{$info["admin_only"]}',
					 field_label = '{$info["field_label"]}',
					 field_type = '{$info["field_type"]}',
		       field_orientation = '{$info["field_orientation"]}',
					 default_value = '{$info["default_value"]}',
					 field_order = '{$info["field_order"]}',
					 is_required = '$is_required',
					 error_string = '{$info["error_string"]}'
    WHERE  client_field_id = $client_field_id
		  ");

  // if this field had multiple options, add them too
  if ($info["field_type"] == "select" || $info["field_type"] == "multi-select" || 
	    $info["field_type"] == "radios" || $info["field_type"] == "checkboxes")
  {
	  for ($i=1; $i<=$info["num_rows"]; $i++)
		{
      if (!isset($info["field_option_text_$i"]) || empty($info["field_option_text_$i"]))
			  continue;
			
			$option_text = $info["field_option_text_$i"];
		  mysql_query("
			  INSERT INTO {$g_table_prefix}module_extended_client_field_options 
				  (client_field_id, option_text, field_order)
				VALUES ($client_field_id, '$option_text', $i)
				  ");
		}
	}

	return array(true, $L["notify_field_updated"]);
}


/**
 * Returns a page (or all) client fields.
 *
 * @param integer $page_num
 * @param array $search a hash whose keys correspond to database column names
 * @return array
 */
function ecf_get_client_fields($page_num = 1, $num_per_page = 10, $search = array())
{
	global $g_table_prefix;

	$where_clause = "";
	if (!empty($search))
	{
	  $clauses = array();
	  while (list($key, $value) = each($search))
		  $clauses[] = "$key = '$value'";
			
		if (!empty($clauses))
		  $where_clause = "WHERE " . join(" AND ", $clauses);
	}
	
	if ($num_per_page == "all")
	{
		$query = mysql_query("
		  SELECT client_field_id
	    FROM   {$g_table_prefix}module_extended_client_fields
			$where_clause
	    ORDER BY field_order
	      ");
	}
	else
	{
	  // determine the offset
	  if (empty($page_num)) { $page_num = 1; }
		$first_item = ($page_num - 1) * $num_per_page;

	  $query = mysql_query("
	    SELECT client_field_id
	    FROM   {$g_table_prefix}module_extended_client_fields
			$where_clause
	    ORDER BY field_order
	    LIMIT $first_item, $num_per_page
		    ");
	}

	$count_query = mysql_query("SELECT count(*) as c FROM {$g_table_prefix}module_extended_client_fields");
	$count_hash = mysql_fetch_assoc($count_query);
  $num_results = $count_hash["c"];

  $infohash = array();
	while ($field = mysql_fetch_assoc($query))
	{
	  $client_field_id = $field["client_field_id"];
    $infohash[] = ecf_get_field($client_field_id);
  }

  $return_hash["results"] = $infohash;
  $return_hash["num_results"] = $num_results;

  return $return_hash;
}


/**
 * Deletes an extended client field and any data so far added for those clients.
 *
 * TODO: Delete client data, too!
 */
function ecf_delete_field($client_field_id)
{
  global $g_table_prefix, $L;
	
	mysql_query("DELETE FROM {$g_table_prefix}module_extended_client_field_options WHERE client_field_id = $client_field_id");
	mysql_query("DELETE FROM {$g_table_prefix}module_extended_client_fields WHERE client_field_id = $client_field_id");
	mysql_query("DELETE FROM {$g_table_prefix}account_settings WHERE setting_name = 'ecf_{$client_field_id}'");
	
	return array(true, $L["notify_field_deleted"]);	
}


/**
 * Returns all information about a field.
 */
function ecf_get_field($field_id)
{
  global $g_table_prefix;

	$query = mysql_query("
	  SELECT * 
		FROM   {$g_table_prefix}module_extended_client_fields
		WHERE  client_field_id = $field_id
		  ");
  $info = mysql_fetch_assoc($query);
	$info["options"] = array();

  if ($info["field_type"] == "select" || $info["field_type"] == "multi-select" ||
	    $info["field_type"] == "radios" || $info["field_type"] == "checkboxes")
	{
	  $query = mysql_query("
  	  SELECT *
  		FROM   {$g_table_prefix}module_extended_client_field_options
  		WHERE  client_field_id = $field_id
			ORDER BY field_order ASC
  		  ");

		$options = array();
		while ($row = mysql_fetch_assoc($query))
		  $options[] = $row;

    $info["options"] = $options;
	}

  return $info;
}


/**
 * This function handles the actual field generation for the form.
 */
function ecf_display_fields($location, $template_vars)
{
  global $g_root_dir;
	
	// okay! We have some stuff to show. Grab the section title, then
  $settings = ft_get_module_settings("", "extended_client_fields");

	$title = "";
	$is_admin = false;
	switch ($location)
	{
	  case "admin_edit_client_main_top":
		  $location = "edit_client_main_top";
		  $is_admin = true;
		  $title = $settings["main_account_page_top_title"];
      break;
	  case "edit_client_main_top":
		  $title = $settings["main_account_page_top_title"];
		  break;
	  case "admin_edit_client_main_middle":
		  $location = "edit_client_main_middle";		
		  $is_admin = true;
		  $title = $settings["main_account_page_middle_title"];		
			break;
	  case "edit_client_main_middle":
		  $title = $settings["main_account_page_middle_title"];		
		  break;
	  case "admin_edit_client_main_bottom":
		  $location = "edit_client_main_bottom";
		  $is_admin = true;
		  $title = $settings["main_account_page_bottom_title"];		
		  break;
	  case "edit_client_main_bottom":
		  $title = $settings["main_account_page_bottom_title"];		
		  break;
	  case "admin_edit_client_settings_top":
		  $location = "edit_client_settings_top";		
		  $is_admin = true;
		  $title = $settings["settings_page_top_title"];		
		  break;
	  case "edit_client_settings_top":
		  $title = $settings["settings_page_top_title"];		
		  break;
	  case "admin_edit_client_settings_bottom":
		  $location = "edit_client_settings_bottom";		
		  $is_admin = true;
		  $title = $settings["settings_page_bottom_title"];		
		  break;
	  case "edit_client_settings_bottom":
		  $title = $settings["settings_page_bottom_title"];		
		  break;
	}

  $fields = ecf_get_client_fields(1, "all", array("template_hook" => $location));

  if (empty($fields["results"]))
	  return "";

	$smarty = new Smarty();
	$smarty->template_dir  = "$g_root_dir/modules/extended_client_fields/smarty/";
	$smarty->compile_dir   = "$g_root_dir/themes/default/cache/";

	// now look through the incoming client settings, passed through $template_vars and determine
	// the selected value for each field
	$field_info = array();
	foreach ($fields["results"] as $info)
	{
	  if ($info["admin_only"] == "yes" && !$is_admin)
		  continue;

	  $client_field_id = $info["client_field_id"];
		
		if (!isset($template_vars["client_info"]["settings"]["ecf_{$client_field_id}"]))
		  $info["content"] = $info["default_value"];
    else
		  $info["content"] = $template_vars["client_info"]["settings"]["ecf_{$client_field_id}"];
	
		// if this was a checkbox group or multi-select dropdown, split the selected item(s) into an array 
		if ($info["field_type"] == "checkboxes" || $info["field_type"] == "multi-select")
		  $info["content"] = explode("|", $info["content"]);

	  $field_info[] = $info;
	}
	
	if (empty($field_info))
	  return "";
	
	$smarty->assign("title", $title);
	$smarty->assign("fields", $field_info);
	
	// tack on all the template vars passed by the page
  while (list($key, $value) = each($template_vars))
	  $smarty->assign($key, $value);
		
	$output = $smarty->fetch("$g_root_dir/modules/extended_client_fields/smarty/section_html.tpl");

	echo $output;
}


/**
 * This function is called whenever the administrator updates the client, for either of the 
 * main or settings tabs.
 */
function ecf_admin_save_extended_client_fields($postdata)
{
  global $g_table_prefix;

	$client_id = $postdata["infohash"]["client_id"];

	// Main tab
	if ($postdata["tab_num"] == 1)
	{
	  // find out what (if any) extended fields have been created for this tab
		$query = mysql_query("
		  SELECT client_field_id
	    FROM   {$g_table_prefix}module_extended_client_fields
			WHERE  template_hook = 'edit_client_main_top' OR
			       template_hook = 'edit_client_main_middle' OR
						 template_hook = 'edit_client_main_bottom'
        ");

		$client_field_ids = array();
		while ($row = mysql_fetch_assoc($query))
		  $client_field_ids[] = $row["client_field_id"];

    // now loop through all 
		if (!empty($client_field_ids))
		{
		  $settings = array();
		  foreach ($client_field_ids as $id)
			{			
        $settings["ecf_{$id}"] = "";
				if (isset($postdata["infohash"]["ecf_{$id}"])) 
				{
				  $val = ft_sanitize($postdata["infohash"]["ecf_{$id}"]);
				  if (is_array($postdata["infohash"]["ecf_{$id}"]))
					  $settings["ecf_{$id}"] = join("|", $val);
					else
				    $settings["ecf_{$id}"] = $val;
				}
      }
			
      ft_set_account_settings($client_id, $settings); 
		}
	}
	
	// Settings tab
	if ($postdata["tab_num"] == 2)
	{
	  // find out what (if any) extended fields have been created for this tab
		$query = mysql_query("
		  SELECT client_field_id
	    FROM   {$g_table_prefix}module_extended_client_fields
			WHERE  template_hook = 'edit_client_settings_top' OR
			       template_hook = 'edit_client_settings_bottom'
        ");
	
		$client_field_ids = array();
		while ($row = mysql_fetch_assoc($query))
		  $client_field_ids[] = $row["client_field_id"];

    // now loop through all 
		if (!empty($client_field_ids))
		{
		  $settings = array();
		  foreach ($client_field_ids as $id)
			{			
        $settings["ecf_{$id}"] = "";
				if (isset($postdata["infohash"]["ecf_{$id}"])) 
				{
				  $val = ft_sanitize($postdata["infohash"]["ecf_{$id}"]);
				  if (is_array($postdata["infohash"]["ecf_{$id}"]))
					  $settings["ecf_{$id}"] = join("|", $val);
					else
				    $settings["ecf_{$id}"] = $val;
				}
      }
			
      ft_set_account_settings($client_id, $settings); 
		}	
	}
}


function ecf_client_save_extended_client_fields($postdata)
{
  global $g_table_prefix;

	$client_id = $postdata["account_id"];

	// Main tab
	if ($postdata["info"]["page"] == "main")
	{
	  // find out what (if any) extended fields have been created for this tab
		$query = mysql_query("
		  SELECT client_field_id
	    FROM   {$g_table_prefix}module_extended_client_fields
			WHERE  template_hook = 'edit_client_main_top' OR
			       template_hook = 'edit_client_main_middle' OR
						 template_hook = 'edit_client_main_bottom'
        ");

		$client_field_ids = array();
		while ($row = mysql_fetch_assoc($query))
		  $client_field_ids[] = $row["client_field_id"];

		if (!empty($client_field_ids))
		{
		  $settings = array();
		  foreach ($client_field_ids as $id)
			{			
        $settings["ecf_{$id}"] = "";
				if (isset($postdata["info"]["ecf_{$id}"])) 
				{
				  $val = ft_sanitize($postdata["info"]["ecf_{$id}"]);
				  if (is_array($postdata["info"]["ecf_{$id}"]))
					  $settings["ecf_{$id}"] = join("|", $val);
					else
				    $settings["ecf_{$id}"] = $val;
				}
      }

      ft_set_account_settings($client_id, $settings); 
		}
	}
	
	// Settings tab
	if ($postdata["info"]["page"] == "settings")
	{
	  // find out what (if any) extended fields have been created for this tab
		$query = mysql_query("
		  SELECT client_field_id
	    FROM   {$g_table_prefix}module_extended_client_fields
			WHERE  template_hook = 'edit_client_settings_top' OR
			       template_hook = 'edit_client_settings_bottom'
        ");
	
		$client_field_ids = array();
		while ($row = mysql_fetch_assoc($query))
		  $client_field_ids[] = $row["client_field_id"];

    // now loop through all 
		if (!empty($client_field_ids))
		{
		  $settings = array();
		  foreach ($client_field_ids as $id)
			{			
        $settings["ecf_{$id}"] = "";
				if (isset($postdata["info"]["ecf_{$id}"])) 
				{
				  $val = $postdata["info"]["ecf_{$id}"];

				  if (is_array($postdata["info"]["ecf_{$id}"]))
					  $settings["ecf_{$id}"] = join("|", $val);
					else
				    $settings["ecf_{$id}"] = $val;
				}
      }
			
      ft_set_account_settings($client_id, $settings); 
		}	
	}
}


/**
 * Called on the main fields page. This updates the orders of the entire list of
 * Extended Client Fields. Note: the option to sort the Fields only appears if there is
 * 2 or more fields.
 *
 * @param array $info the form contents
 * @return array Returns array with indexes:<br/>
 *               [0]: true/false (success / failure)<br/>
 *               [1]: message string<br/>
 */
function ecf_update_field_order($info)
{
	global $g_table_prefix, $L;

	// loop through all the fields in $info that are being re-sorted and compile a list of
	// view_id => order pairs.
  $new_field_orders = array();
	foreach ($info as $key => $value)
	{
		if (preg_match("/^field_(\d+)_order$/", $key, $match))
		{
			$client_field_id = $match[1];
      $new_field_orders[$client_field_id] = $value;
		}
	}

	// okay! Since we may have only updated a *subset* of all fields (the fields page is
	// arranged in pages), get a list of ALL extended client fields, add them to
  // $new_field_orders and sort the entire lot of them in one go
  $view_info = array();
  $query = mysql_query("
		SELECT client_field_id, field_order
		FROM   {$g_table_prefix}module_extended_client_fields
			");
  while ($row = mysql_fetch_assoc($query))
  {
		if (!array_key_exists($row["client_field_id"], $new_field_orders))
			$new_field_orders[$row["client_field_id"]] = $row["field_order"];
  }

  // sort by the ORDER (the value - non-key - of the hash)
  asort($new_field_orders);

  $count = 1;
  foreach ($new_field_orders as $client_field_id => $order)
  {
  	mysql_query("
			UPDATE {$g_table_prefix}module_extended_client_fields
			SET	   field_order = $count
			WHERE  client_field_id = $client_field_id
				");
  	$count++;
  }

  // return success
	return array(true, $L["notify_field_order_updated"]);
}