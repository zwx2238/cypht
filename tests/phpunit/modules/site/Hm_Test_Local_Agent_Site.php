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

    public function test_managed_document_keeps_links_and_assets_under_the_mail_prefix() {
        foreach (['home', 'servers', 'compose', 'settings'] as $page) {
            $header = new Hm_Output_local_agent_header_content([
                'router_url_path' => '/',
                'router_login_state' => true,
                'router_page_name' => $page,
            ], []);
            $html = $header->output_content('Hm_Format_HTML5', [
                'interface_lang' => 'en',
                'interface_direction' => 'ltr',
            ]);
            $this->assertStringContainsString('<base href="/services/mail/"', $html);
            $this->assertStringNotContainsString('<base href="/"', $html);
            $this->assertStringContainsString('<title>'.ucfirst($page).'</title>', $html);
        }
    }

    public function test_protocol_account_links_target_existing_server_sections() {
        require_once APP_PATH.'modules/nux/modules.php';
        foreach ([0, 1] as $count) {
            $welcome = new Hm_Output_end_welcome_dialog([
                'page_param_name' => 'page',
                'tzone' => 'Asia/Shanghai',
                'nux_server_setup' => array_fill_keys(['imap', 'jmap', 'smtp', 'ews', 'feeds', 'profiles'], $count),
            ], []);
            $html = $welcome->output_content('Hm_Format_HTML5', [
                'interface_lang' => 'en', 'interface_direction' => 'ltr',
            ]);
            $start = new Hm_Output_start_welcome_dialog(['page_param_name' => 'page'], []);
            $html = $start->output_content('Hm_Format_HTML5', [
                'interface_lang' => 'en', 'interface_direction' => 'ltr',
            ]).$html;
            $document = new DOMDocument();
            $document->loadHTML($html);
            $xpath = new DOMXPath($document);
            foreach (['imap', 'jmap', 'smtp', 'ews'] as $protocol) {
                $link = $xpath->query('//li[contains(concat(" ", @class, " "), " nux_'.$protocol.' ")]/a')->item(0);
                $this->assertNotNull($link);
                $section = $protocol === 'ews' ? 'ews_server_config' : 'server_config';
                $this->assertSame('?page=servers#'.$section.'_section', $link->getAttribute('href'));
            }
        }
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

    public function test_managed_refresh_updates_stale_session_before_core_loading() {
        $settings = new Local_Agent_User_Config($this->site_config());
        $settings->reload(['version' => VERSION, 'imap_servers' => [], 'smtp_servers' => [], 'feeds' => []]);
        $parent = build_parent_mock();
        $parent->user_config = new Local_Agent_User_Config($this->site_config());
        $parent->session->set('username', $this->username);
        $parent->session->set('user_data', $settings->dump());
        $parent->request->server['REQUEST_URI'] = '/?page=servers';
        $settings->set('imap_servers', ['phone' => [
            'id' => 'phone', 'server' => 'imap.phone.test', 'pass' => 'mailbox-secret',
        ]]);

        $refresh = new Hm_Handler_local_agent_load_user_data($parent, 'servers');
        $refresh->process();
        $core = new Hm_Handler_load_user_data(
            $parent, 'servers', $refresh->module_output(), $refresh->output_protected()
        );
        $core->process();
        $this->assertSame('imap.phone.test', $parent->user_config->get('imap_servers')['phone']['server']);
        $this->assertSame($parent->user_config->dump(), $parent->session->get('user_data'));
        $this->assertFalse($core->module_output()['warn_for_unsaved_changes']);
        $this->assertFalse($core->module_output()['no_password_save']);
    }

    /**
     * @preserveGlobalState disabled
     * @runInSeparateProcess
     */
    public function test_managed_module_graph_initializes_repositories_before_account_handlers() {
        $environment = (new Symfony\Component\Dotenv\Dotenv())->parse(
            file_get_contents(APP_PATH.'docker/.env.local-agent')
        );
        Hm_Handler_Modules::load([]);
        Hm_Output_Modules::load([]);
        foreach (explode(',', $environment['CYPHT_MODULES']) as $module) {
            $setup = APP_PATH.'modules/'.$module.'/setup.php';
            if (is_readable($setup)) {
                require $setup;
            }
        }
        Hm_Handler_Modules::try_queued_modules();
        Hm_Handler_Modules::process_all_page_queue();
        Hm_Handler_Modules::try_queued_modules();

        foreach (['servers', 'ajax_nux_service_select', 'ajax_nux_add_service', 'compose'] as $page) {
            $handlers = array_keys(Hm_Handler_Modules::get_for_page($page));
            $this->assertContains('load_user_data', $handlers, $page.' missing load_user_data');
            $load = array_search('load_user_data', $handlers, true);
            $refresh = array_search('local_agent_load_user_data', $handlers, true);
            $this->assertNotFalse($refresh, $page.' missing managed refresh');
            $this->assertLessThan($load, $refresh);
            if ($page === 'ajax_nux_service_select') {
                continue;
            }
            foreach (['imap', 'smtp'] as $protocol) {
                $init = array_search('load_'.$protocol.'_servers_from_config', $handlers, true);
                $this->assertNotFalse($init, $page.' missing '.$protocol.' initialization');
                $this->assertGreaterThan($load, $init);
                $save = array_search('save_'.$protocol.'_servers', $handlers, true);
                if ($save !== false) {
                    $this->assertLessThan($save, $init);
                }
            }
        }
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
