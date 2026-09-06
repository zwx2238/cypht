<?php

use PHPUnit\Framework\TestCase;

require_once APP_PATH.'modules/site/lib.php';
require_once APP_PATH.'modules/site/modules.php';

class Hm_Test_Local_Agent_Site extends TestCase {

    private $settings_dir;
    private $username = 'hub';
    private $password = 'managed-password-abcdefghijklmnopqrstuvwxyz-123456';

    public function setUp(): void {
        $this->settings_dir = sys_get_temp_dir().'/cypht-local-agent-'.bin2hex(random_bytes(8));
        mkdir($this->settings_dir, 0700, true);
        putenv('LOCAL_AGENT_AUTH_USERNAME='.$this->username);
        putenv('LOCAL_AGENT_AUTH_PASSWORD='.$this->password);
    }

    public function test_managed_authentication_accepts_only_the_configured_identity() {
        $auth = new Local_Agent_Auth(new Hm_Mock_Config());
        $this->assertTrue($auth->check_credentials($this->username, $this->password));
        $this->assertFalse($auth->check_credentials('other', $this->password));
        $this->assertFalse($auth->check_credentials($this->username, 'wrong-password'));
    }

    public function test_settings_are_encrypted_and_persisted_immediately() {
        $config = $this->site_config();
        $settings = new Local_Agent_User_Config($config);
        $settings->reload([
            'version' => VERSION,
            'feeds' => [],
            'imap_servers' => [],
            'smtp_servers' => [],
        ]);
        $settings->set('imap_servers', [[
            'server' => 'imap.example.test',
            'user' => 'mail@example.test',
            'pass' => 'external-mailbox-password',
        ]]);

        $raw = file_get_contents($this->settings_dir.'/'.$this->username.'.txt');
        $this->assertStringNotContainsString('external-mailbox-password', $raw);
        $this->assertSame(
            0600,
            fileperms($this->settings_dir.'/'.$this->username.'.txt') & 0777
        );

        $reloaded = new Local_Agent_User_Config($config);
        $reloaded->load($this->username, 'browser-supplied-key-is-ignored');
        $this->assertSame(
            'external-mailbox-password',
            $reloaded->get('imap_servers')[0]['pass']
        );

        $reloaded->set('no_password_save_setting', true);
        $this->assertFalse($reloaded->get('no_password_save_setting'));
    }

    public function test_decryption_failure_cannot_overwrite_existing_settings() {
        $config = $this->site_config();
        $settings = new Local_Agent_User_Config($config);
        $settings->reload([
            'version' => VERSION,
            'feeds' => [],
            'imap_servers' => [],
            'smtp_servers' => [],
        ]);
        $settings->set('imap_servers', [[
            'server' => 'imap.example.test',
            'user' => 'mail@example.test',
            'pass' => 'external-mailbox-password',
        ]]);
        $file = $this->settings_dir.'/'.$this->username.'.txt';
        $encrypted = file_get_contents($file);

        putenv('LOCAL_AGENT_AUTH_PASSWORD='.str_repeat('x', 48));
        $unreadable = new Local_Agent_User_Config($config);
        $unreadable->load($this->username, 'ignored');
        $this->assertTrue($unreadable->decrypt_failed);
        try {
            $unreadable->set('theme_setting', 'dark');
            $this->fail('Expected managed settings write protection');
        } catch (Exception $error) {
            $this->assertStringContainsString('decryption failed', $error->getMessage());
        }
        $this->assertSame($encrypted, file_get_contents($file));
    }

    public function test_concurrent_devices_merge_independent_mailbox_additions() {
        $config = $this->site_config();
        $initial = new Local_Agent_User_Config($config);
        $initial->reload([
            'version' => VERSION,
            'feeds' => [],
            'imap_servers' => [],
            'smtp_servers' => [],
        ]);

        $desktop = new Local_Agent_User_Config($config);
        $desktop->load($this->username, 'ignored');
        $phone = new Local_Agent_User_Config($config);
        $phone->load($this->username, 'ignored');

        $desktop->set('imap_servers', [
            'desktop' => [
                'id' => 'desktop',
                'server' => 'imap.desktop.test',
                'pass' => 'desktop-secret',
            ],
        ]);
        $phone->set('imap_servers', [
            'phone' => [
                'id' => 'phone',
                'server' => 'imap.phone.test',
                'pass' => 'phone-secret',
            ],
        ]);

        $latest = new Local_Agent_User_Config($config);
        $latest->load($this->username, 'ignored');
        $this->assertSame(
            ['desktop', 'phone'],
            array_keys($latest->get('imap_servers'))
        );
    }

    public function test_concurrent_oauth_refresh_keeps_the_newest_token_generation() {
        $config = $this->site_config();
        $initial = new Local_Agent_User_Config($config);
        $initial->reload([
            'version' => VERSION,
            'feeds' => [],
            'imap_servers' => [
                'shared' => [
                    'id' => 'shared',
                    'auth' => 'xoauth2',
                    'server' => 'outlook.office365.com',
                    'pass' => 'old-access',
                    'expiration' => 100,
                    'refresh_token' => 'old-refresh',
                    'oauth_refreshed_at' => 100.0,
                ],
            ],
            'smtp_servers' => [],
        ]);

        $older = new Local_Agent_User_Config($config);
        $older->load($this->username, 'ignored');
        $newer = new Local_Agent_User_Config($config);
        $newer->load($this->username, 'ignored');

        $newer_servers = $newer->get('imap_servers');
        $newer_servers['shared']['pass'] = 'newest-access';
        $newer_servers['shared']['expiration'] = 300;
        $newer_servers['shared']['refresh_token'] = 'newest-refresh';
        $newer_servers['shared']['oauth_refreshed_at'] = 300.0;
        $newer->set('imap_servers', $newer_servers);

        $older_servers = $older->get('imap_servers');
        $older_servers['shared']['pass'] = 'older-access';
        $older_servers['shared']['expiration'] = 200;
        $older_servers['shared']['refresh_token'] = 'older-refresh';
        $older_servers['shared']['oauth_refreshed_at'] = 200.0;
        $older_servers['shared']['name'] = 'Concurrent display edit';
        $older->set('imap_servers', $older_servers);

        $latest = new Local_Agent_User_Config($config);
        $latest->load($this->username, 'ignored');
        $server = $latest->get('imap_servers')['shared'];
        $this->assertSame('newest-access', $server['pass']);
        $this->assertSame(300, $server['expiration']);
        $this->assertSame('newest-refresh', $server['refresh_token']);
        $this->assertSame(300.0, (float) $server['oauth_refreshed_at']);
        $this->assertSame('Concurrent display edit', $server['name']);
    }

    public function test_reload_persists_internal_repository_migrations() {
        $config = $this->site_config();
        $settings = new Local_Agent_User_Config($config);
        $settings->reload([
            'version' => VERSION,
            'feeds' => [],
            'imap_servers' => [
                'migrated' => [
                    'id' => 'migrated',
                    'server' => 'imap.example.test',
                    'pass' => 'migrated-secret',
                ],
            ],
            'smtp_servers' => [],
        ]);

        $latest = new Local_Agent_User_Config($config);
        $latest->load($this->username, 'ignored');
        $this->assertSame(
            'imap.example.test',
            $latest->get('imap_servers')['migrated']['server']
        );
    }

    public function test_managed_ui_contains_no_local_password_or_logout_controls() {
        $login = new Hm_Output_local_agent_managed_login([
            'router_login_state' => false,
            'page_param_name' => 'page',
        ], []);
        $html = $login->output_content('Hm_Format_HTML5', [
            'interface_lang' => 'en',
            'interface_direction' => 'ltr',
        ]);
        $this->assertStringNotContainsString('type="password"', $html);
        $this->assertStringNotContainsString('name="password"', $html);
        $this->assertStringContainsString('data-local-agent-managed-login="true"', $html);

        $footer = new Hm_Output_local_agent_folder_list_content_end([
            'hide_folder_icons' => false,
        ], []);
        $html = $footer->output_content('Hm_Format_HTML5', [
            'interface_lang' => 'en',
            'interface_direction' => 'ltr',
        ]);
        $this->assertStringNotContainsString('logout', strtolower($html));
        $this->assertStringContainsString('update_message_list', $html);
    }

    public function test_managed_session_does_not_record_unsaved_settings() {
        $session = new Local_Agent_Session($this->site_config(), Local_Agent_Auth::class);
        $session->record_unsaved('IMAP server added');
        $this->assertSame([], $session->get('changed_settings', []));
    }

    public function test_managed_version_check_never_requires_an_external_request() {
        $handler = new Hm_Handler_local_agent_version_upgrade_checker(
            build_parent_mock(),
            'home'
        );
        $handler->process();
        $output = $handler->module_output();
        $this->assertFalse($output['need_upgrade']);
        $this->assertSame(CYPHT_VERSION, $output['latest_version']);
    }

    public function tearDown(): void {
        putenv('LOCAL_AGENT_AUTH_USERNAME');
        putenv('LOCAL_AGENT_AUTH_PASSWORD');
        if (is_dir($this->settings_dir)) {
            foreach (glob($this->settings_dir.'/*') as $file) {
                unlink($file);
            }
            rmdir($this->settings_dir);
        }
    }

    private function site_config() {
        $config = new Hm_Mock_Config();
        $config->set('user_settings_dir', $this->settings_dir);
        $config->set('single_server_mode', false);
        return $config;
    }
}
