<?php

use PHPUnit\Framework\TestCase;

/**
 * @runTestsInSeparateProcesses
 * @preserveGlobalState disabled
 */
class Hm_Test_Mail_Transport extends TestCase {
    private $handles = [];
    private $error_log;
    private $log_file;

    public function setUp(): void {
        require_once __DIR__.'/transport-probes.php';
        $this->log_file = tempnam(sys_get_temp_dir(), 'mail-transport-log-');
        $this->error_log = ini_set('error_log', $this->log_file);
        Hm_Functions::$stream_factory = function() {
            $handle = fopen('php://temp', 'w+');
            $this->handles[] = $handle;
            return $handle;
        };
        Hm_Functions::$crypto_result = true;
    }

    public function tearDown(): void {
        Hm_Functions::$stream_factory = null;
        Hm_Functions::$crypto_result = true;
        foreach ($this->handles as $handle) {
            if (is_resource($handle)) fclose($handle);
        }
        ini_set('error_log', $this->error_log);
        unlink($this->log_file);
    }

    public function test_imap_verifies_certificates_and_refreshes_capabilities_after_starttls() {
        $imap = new Hm_Transport_IMAP_Probe();
        $this->assertTrue($imap->connect($this->config(false)));
        $this->assertSame('authenticated', $imap->state);
        $this->assertSame(3, $imap->capability_requests);
        $this->assertVerifiedContext();
    }

    public function test_imap_never_authenticates_when_starttls_is_missing_rejected_or_fails() {
        foreach (['missing', 'rejected', 'failed'] as $failure) {
            $imap = new Hm_Transport_IMAP_Probe();
            $imap->extensions = $failure === 'missing' ? [] : ['starttls'];
            $imap->accept_starttls = $failure !== 'rejected';
            Hm_Functions::$crypto_result = $failure !== 'failed';
            $this->assertFalse($imap->connect($this->config(false)), $failure);
            $commands = array_keys($imap->show_debug(true, true, true)['commands']);
            $this->assertStringNotContainsString('LOGIN', implode(' ', $commands), $failure);
            $this->assertSame('disconnected', $imap->state);
        }
    }

    public function test_imap_implicit_tls_verifies_the_peer() {
        $imap = new Hm_Transport_IMAP_Probe();
        $this->assertTrue($imap->connect($this->config(true)));
        $this->assertVerifiedContext();
    }

    public function test_smtp_authenticates_only_after_verified_starttls() {
        $smtp = new Hm_Transport_SMTP_Probe($this->config(false));
        $smtp->replies = [['220 test ready'], ['250-STARTTLS', '250 AUTH LOGIN'], ['220 ready'], ['250 AUTH LOGIN']];
        $this->assertFalse($smtp->connect());
        $this->assertSame(1, $smtp->auth_calls);
        $this->assertSame('STARTTLS', $smtp->sent[1]);
        $this->assertVerifiedContext();
    }

    public function test_smtp_never_authenticates_when_starttls_is_missing_rejected_or_fails() {
        foreach (['missing', 'rejected', 'failed'] as $failure) {
            $smtp = new Hm_Transport_SMTP_Probe($this->config(false));
            $caps = $failure === 'missing' ? ['250 AUTH LOGIN'] : ['250-STARTTLS', '250 AUTH LOGIN'];
            $smtp->replies = [['220 test ready'], $caps, [$failure === 'rejected' ? '454 not available' : '220 ready']];
            Hm_Functions::$crypto_result = $failure !== 'failed';
            $this->assertIsString($smtp->connect(), $failure);
            $this->assertSame(0, $smtp->auth_calls, $failure);
            $this->assertSame('disconnected', $smtp->state);
        }
    }

    public function test_smtp_implicit_tls_verifies_the_peer() {
        $smtp = new Hm_Transport_SMTP_Probe($this->config(true));
        $smtp->replies = [['220 test ready'], ['250 AUTH LOGIN']];
        $this->assertFalse($smtp->connect());
        $this->assertSame(1, $smtp->auth_calls);
        $this->assertNotContains('STARTTLS', $smtp->sent);
        $this->assertVerifiedContext();
    }

    private function assertVerifiedContext() {
        $this->assertTrue(Hm_Functions::$stream_context['ssl']['verify_peer']);
        $this->assertTrue(Hm_Functions::$stream_context['ssl']['verify_peer_name']);
    }

    private function config($tls) {
        return ['server' => 'mail.example.test', 'port' => 993, 'tls' => $tls,
            'username' => 'fixture-user', 'password' => 'fixture-password'];
    }
}
