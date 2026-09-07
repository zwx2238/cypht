<?php

if (!defined('DEBUG_MODE')) { die(); }

handler_source('site');
output_source('site');

replace_module('output', 'header_content', 'local_agent_header_content');
replace_module('output', 'login', 'local_agent_managed_login');
replace_module('output', 'folder_list_content_end', 'local_agent_folder_list_content_end');
/* Preserve the load_user_data anchor used by deferred protocol initializers. */
add_module_to_all_pages('handler', 'local_agent_load_user_data', true, 'site', 'load_user_data', 'before');
foreach (Hm_Handler_Modules::dump() as $page => $handlers) {
    if (strpos($page, 'ajax_') === 0 && isset($handlers['load_user_data'])) {
        add_handler($page, 'local_agent_load_user_data', true, 'site', 'load_user_data', 'before');
    }
}
replace_module('handler', 'version_upgrade_checker', 'local_agent_version_upgrade_checker');
foreach (array_keys(Hm_Output_Modules::dump()) as $page) {
    Hm_Output_Modules::del($page, 'save_reminder');
    Hm_Output_Modules::del($page, 'settings_save_link');
}
Hm_Output_Modules::del('settings', 'no_password_setting');
Hm_Output_Modules::del('settings', 'warn_for_unsaved_changes_setting');
Hm_Handler_Modules::del('settings', 'process_no_password_setting');
Hm_Handler_Modules::del('settings', 'process_warn_for_unsaved_changes_setting');
add_handler('save', 'local_agent_disable_save_page', true, 'site', 'login', 'after');

/* replace module just on the home page */
//replace_module('handler', 'http_headers', 'site_http_headers', 'home');

/* replace module on all pages */
//replace_module('handler', 'http_headers', 'site_http_headers');

/* disable the "servers" link in the settings section of the folder list */
//replace_module('output', 'settings_servers_link', false, 'ajax_hm_folders');

/* redirect request to the servers page to the home page instead */
//add_handler('servers', 'disable_servers_page', true, 'site', 'login', 'after');

/* allowed input */
return array(
    'allowed_pages' => array(),
    'allowed_cookie' => array(),
    'allowed_server' => array(),
    'allowed_get' => array(),
    'allowed_post' => array()
);
