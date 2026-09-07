<?php

require_once APP_PATH.'modules/imap/hm-imap.php';
require_once APP_PATH.'modules/smtp/hm-smtp.php';

class Hm_Transport_IMAP_Probe extends Hm_IMAP {
    public $extensions = ['starttls'];
    public $accept_starttls = true;
    public $capability_requests = 0;

    public function get_capability() {
        $this->capability_requests++;
        $this->supported_extensions = $this->extensions;
        return '';
    }

    public function get_response($max=false, $chunked=false, $line_length=8192, $sort=false) {
        $status = !$this->accept_starttls && str_contains($this->current_command, 'STARTTLS') ? 'NO' : 'OK';
        return ['A'.$this->command_count.' '.$status.' test response'];
    }
}

class Hm_Transport_SMTP_Probe extends Hm_SMTP {
    public $replies = [];
    public $sent = [];
    public $auth_calls = 0;

    public function send_command($command) {
        $this->sent[] = $command;
    }

    public function get_response($chunked=true) {
        return array_map([$this, 'parse_line'], array_shift($this->replies) ?? []);
    }

    public function authenticate($username, $password, $mech) {
        $this->auth_calls++;
        $this->state = 'authed';
        return false;
    }
}
