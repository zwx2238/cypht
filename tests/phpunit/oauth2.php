<?php

use PHPUnit\Framework\TestCase;

require_once APP_PATH.'modules/nux/functions.php';

class Hm_Test_Oauth2 extends TestCase {

    public $oauth2;
    public function setUp(): void {
        $this->oauth2 = new Hm_Oauth2('client_id', 'secret', 'uri');
    }
    /**
     * @preserveGlobalState disabled
     * @runInSeparateProcess
     */
    public function test_request_authorization_url() {
        $res = $this->oauth2->request_authorization_url('url', 'scope', 'state', 'hint');
        $this->assertEquals('url?response_type=code&amp;scope=scope&amp;state=state&amp;prompt=consent&amp;access_type=offline&amp;client_id=client_id&amp;redirect_uri=uri&amp;login_hint=hint', $res);
    }
    /**
     * @preserveGlobalState disabled
     * @runInSeparateProcess
     */
    public function test_request_authorization_url_encodes_query_values() {
        $res = $this->oauth2->request_authorization_url(
            'https://example.test/authorize',
            'offline_access https://example.test/SMTP.Send',
            'state with spaces',
            'mail+alias@example.test'
        );
        $this->assertStringContainsString('scope=offline_access%20https%3A%2F%2Fexample.test%2FSMTP.Send', $res);
        $this->assertStringContainsString('state=state%20with%20spaces', $res);
        $this->assertStringContainsString('login_hint=mail%2Balias%40example.test', $res);
    }
    /**
     * @preserveGlobalState disabled
     * @runInSeparateProcess
     */
    public function test_nux_oauth2_state_is_random_and_validated() {
        $first = new_nux_oauth2_state();
        $second = new_nux_oauth2_state();
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/D', $first);
        $this->assertNotSame($first, $second);
        $this->assertTrue(valid_nux_oauth2_state($first, $first));
        $this->assertFalse(valid_nux_oauth2_state($first, $second));
        $this->assertFalse(valid_nux_oauth2_state($first, 'nux_authorization'));
    }
    /**
     * @preserveGlobalState disabled
     * @runInSeparateProcess
     */
    public function test_refresh_token() {
        $res = $this->oauth2->refresh_token('url', 'refresh_token');
        $this->assertEquals(array('unit' => 'test'), $res);
    }
    /**
     * @preserveGlobalState disabled
     * @runInSeparateProcess
     */
    public function test_request_token() {
        $res = $this->oauth2->request_token('url', 'auth_code');
        $this->assertEquals(array('unit' => 'test'), $res);
    }
    public function tearDown(): void {
        unset($this->oauth2);
    }
}
