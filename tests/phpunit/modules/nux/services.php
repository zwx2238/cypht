<?php

use PHPUnit\Framework\TestCase;

require_once APP_PATH.'modules/nux/modules.php';

class Hm_Test_Nux_Services extends TestCase {
    public function setUp(): void {
        require APP_PATH.'modules/nux/services.php';
    }

    public function test_qq_and_163_use_tls_and_authorization_codes() {
        foreach (['qq', '163'] as $provider) {
            $details = Nux_Quick_Services::details($provider);
            $this->assertSame('imap.'.$provider.'.com', $details['server']);
            $this->assertSame(993, $details['port']);
            $this->assertTrue($details['tls']);
            $this->assertSame('login', $details['auth']);
            $this->assertSame('smtp.'.$provider.'.com', $details['smtp']['server']);
            $this->assertSame(465, $details['smtp']['port']);
            $this->assertTrue($details['smtp']['tls']);
            $this->assertSame('Authorization code', $details['credential_label']);
            $html = $this->render($provider);
            $this->assertStringContainsString('Authorization code', $html);
            $this->assertStringNotContainsString('enable_auth2', $html);
        }
    }

    public function test_empty_oauth_credentials_keep_gmail_app_password_and_microsoft_status() {
        $config = new Hm_Mock_Config();
        foreach (['gmail', 'outlook', 'office365'] as $provider) {
            $config->set($provider, array_merge($this->oauth_config(), ['client_id' => '', 'client_secret' => '']));
        }
        Nux_Quick_Services::oauth2_setup($config);
        $gmail = $this->render('gmail');
        $this->assertStringContainsString('App password', $gmail);
        $this->assertStringContainsString('id="nux_password"', $gmail);
        $this->assertStringNotContainsString('enable_auth2', $gmail);
        foreach (['outlook', 'office365'] as $provider) {
            $html = $this->render($provider);
            $this->assertStringContainsString('OAuth application is not configured', $html);
            $this->assertStringContainsString('reset_nux_form', $html);
            $this->assertStringNotContainsString('type="password"', $html);
            $this->assertStringNotContainsString('enable_auth2', $html);
        }
    }

    public function test_partial_and_whitespace_oauth_settings_cannot_enable_authorization() {
        foreach (array_keys($this->oauth_config()) as $key) {
            foreach (['', '   ', null] as $missing) {
                require APP_PATH.'modules/nux/services.php';
                $config = new Hm_Mock_Config();
                $config->set('gmail', array_merge($this->oauth_config(), [$key => $missing]));
                Nux_Quick_Services::oauth2_setup($config);
                $this->assertSame('login', Nux_Quick_Services::details('gmail')['auth']);
            }
        }
    }

    public function test_complete_oauth_configuration_enables_only_configured_providers() {
        $config = new Hm_Mock_Config();
        $config->set('gmail', $this->oauth_config());
        Nux_Quick_Services::oauth2_setup($config);
        $this->assertSame('oauth2', Nux_Quick_Services::details('gmail')['auth']);
        $html = $this->render('gmail');
        $this->assertStringContainsString('enable_auth2', $html);
        $this->assertStringContainsString('client_id=example-client', $html);
        $this->assertStringNotContainsString('example-secret', $html);
        $this->assertStringNotContainsString('type="password"', $html);
        $this->assertSame('login', Nux_Quick_Services::details('qq')['auth']);
        $this->assertSame('oauth2_unconfigured', Nux_Quick_Services::details('outlook')['auth']);
    }

    public function test_oauth_form_rejects_missing_credentials_even_with_valid_state() {
        $details = array_merge(Nux_Quick_Services::details('gmail'), [
            'id' => 'gmail', 'email' => 'mail@example.invalid', 'oauth_state' => new_nux_oauth2_state(),
        ]);
        $this->assertSame('', oauth2_form($details, new Hm_Output_filter_service_select([], [])));
    }

    private function render($provider) {
        $details = array_merge(Nux_Quick_Services::details($provider), [
            'id' => $provider, 'email' => 'mail@example.invalid', 'oauth_state' => new_nux_oauth2_state(),
        ]);
        $output = new Hm_Output_filter_service_select(['nux_add_service_details' => $details], []);
        $output->output_content('Hm_Format_AJAX', ['interface_lang' => 'en']);
        return $output->module_output()['nux_service_step_two'];
    }

    private function oauth_config() {
        return [
            'client_id' => 'example-client', 'client_secret' => 'example-secret',
            'client_uri' => 'https://mail.example.test/services/mail/?page=home',
            'auth_uri' => 'https://provider.example.test/authorize',
            'token_uri' => 'https://provider.example.test/token',
            'refresh_uri' => 'https://provider.example.test/token',
        ];
    }
}
