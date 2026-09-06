<?php

use PHPUnit\Framework\TestCase;

require_once APP_PATH.'modules/core/handler_modules.php';
require_once APP_PATH.'modules/api_login/modules.php';

class Hm_Test_Api_Login extends TestCase {

    public function test_api_login_key_must_be_configured_and_match_exactly() {
        $expected = 'managed-api-login-key-abcdefghijklmnopqrstuvwxyz';
        $this->assertTrue(valid_api_login_key($expected, $expected));
        $this->assertFalse(valid_api_login_key('', ''));
        $this->assertFalse(valid_api_login_key($expected, 'wrong'));
        $this->assertFalse(valid_api_login_key($expected, null));
        $this->assertFalse(valid_api_login_key($expected, array()));
    }
}
