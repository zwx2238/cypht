<?php

/**
 * example site modules
 * @package modules
 * @subpackage site
 */

if (!defined('DEBUG_MODE')) { die(); }

/**
 * The gateway removes the public prefix before forwarding requests to Cypht.
 * Keep relative links, scripts and AJAX requests inside the Mail service.
 */
class Hm_Output_local_agent_header_content extends Hm_Output_header_content {
    protected function output() {
        $this->out('router_url_path', '/services/mail/', false);
        return parent::output();
    }
}

/**
 * @subpackage site/handler
 */
class Hm_Handler_site_http_headers extends Hm_Handler_Module {
    public function process() {
        /* output custom headers here */
    }
}

/**
 * @subpackage site/handler
 */
class Hm_Handler_disable_servers_page extends Hm_Handler_Module {
    public function process() {
        Hm_Dispatch::page_redirect($this->build_page_url('home'));
    }
}

/**
 * The managed installation saves every settings mutation immediately.
 */
class Hm_Handler_local_agent_disable_save_page extends Hm_Handler_Module {
    public function process() {
        Hm_Dispatch::page_redirect($this->build_page_url('settings'));
    }
}

/**
 * The managed fork is upgraded by commit deployment, not a runtime API check.
 */
class Hm_Handler_local_agent_version_upgrade_checker extends Hm_Handler_Module {
    public function process() {
        $this->out('need_upgrade', false);
        $this->out('latest_version', CYPHT_VERSION);
    }
}

/**
 * Keep every active device synchronized with the encrypted settings file.
 */
class Hm_Handler_local_agent_load_user_data extends Hm_Handler_Module {
    public function process() {
        if ($this->session->is_active()) {
            $username = $this->session->get('username', false);
            if ($username) {
                $this->user_config->load($username, '');
                $this->session->set('user_data', $this->user_config->dump());
            }
        }
        $this->out('warn_for_unsaved_changes', false);
        $this->out('no_password_save', false);
    }
}

/**
 * Never expose a Cypht local-login form. The Hub gateway restores sessions.
 */
class Hm_Output_local_agent_managed_login extends Hm_Output_Module {
    protected function output() {
        if ($this->get('router_login_state')) {
            return '';
        }
        return '<div data-local-agent-managed-login="true" class="d-flex w-100 justify-content-center align-items-center vh-100 p-3 flex-column">'.
            '<p>'.$this->trans('The managed mail session is unavailable.').'</p>'.
            '<a class="btn btn-primary" href="'.$this->build_page_url('home').'">'.$this->trans('Reload').'</a>'.
            '</div>';
    }
}

/**
 * Keep reload and navigation controls while removing the local logout action.
 */
class Hm_Output_local_agent_folder_list_content_end extends Hm_Output_Module {
    protected function output() {
        $res = '<div class="sidebar-footer">';
        $res .= '<a href="#" class="update_message_list" title="'.$this->trans('Reload').'">';
        if (!$this->get('hide_folder_icons')) {
            $res .= '<i class="bi bi-arrow-clockwise menu-icon"></i>';
        }
        $res .= '<span class="nav-label">'.$this->trans('Reload').'</span></a>';
        $res .= '<div class="menu-toggle fw-bold cursor-pointer no_mobile">'.
            '<i class="bi bi-list fs-5 fw-bold"></i></div></div>';
        if ($this->format == 'HTML5') {
            return $res;
        }
        $this->concat('formatted_folder_list', $res);
    }
}
