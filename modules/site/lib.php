<?php

/**
 * To use these overrides, you must first enable the "site" module in your
 * config/app.php file to activate the module.
 */

/**
 * Override the session class. These are the methods that must be overriden to
 * create a new session backend. The "session_type" value in your config/app.php must
 * be set to "custom" to activate this class. There are several other
 * properties and methods that can be modified to create custom sessions:
 *
 *  https://cypht.org/docs/code_docs/class-Hm_Session.html
 *
 * This example extends the standard PHP session class. You can also extend the
 * DB or Memcached classes, or the base session class. In this example we just
 * defer to the PHP session class methods.
 *
 * @package modules
 * @subpackage site
 */
class Custom_Session extends Hm_PHP_Session {

    /**
     * check for an active session or an attempt to start one
     * @param object $request request object
     * @return bool
     */
    public function check($request, $user=false, $pass=false, $fingerprint=true) {
        return parent::check($request, $user, $pass, $fingerprint);
    }

    /**
     * Start the session. This could be an existing session or a new login
     * @param object $request request details
     * @return void
     */
    public function start($request, $existing_session=false) {
        return parent::start($request, $existing_session);
    }

    /**
     * Call the configured authentication method to check user credentials
     * @param string $user username
     * @param string $pass password
     * @return bool true if the authentication was successful
     */
    public function auth($user, $pass) {
        return parent::auth($user, $pass);
    }

    /**
     * Return a session value, or a user settings value stored in the session
     * @param string $name session value name to return
     * @param mixed $default value to return if $name is not found
     * @return mixed the value if found, otherwise $defaultHm_Auth
     */
    public function get($name, $default=false, $user=false) {
        return parent::get($name, $default, $user);
    }

    /**
     * Save a value in the session
     * @param string $name the name to save
     * @param string $value the value to save
     * @return void
     */
    public function set($name, $value, $user=false) {
        return parent::set($name, $value);
    }

    /**
     * Delete a value from the session
     * @param string $name name of value to deleteHm_Auth
     * @return void
     */
    public function del($name) {
        return parent::del($name);
    }

    /**
     * End a session after a page request is complete. This only closes the session and
     * does not destroy it
     * @return void
     */
    public function end() {
        return parent::end();
    }

    /**
     * Destroy a session for good
     * @param object $request request details
     * @return void
     */
    public function destroy($request) {
        return parent::destroy($request);
    }

}

/**
 * Override the authentication class. This method needs to be overriden to
 * create a custom authentication backend. You must set the "auth_type" setting
 * in your config/app.php file to "custom" to activate this class. More information
 * about the base class for authentication is located here:
 *
 * https://cypht.org/docs/code_docs/class-Hm_Auth.html
 *
 * This example extends the auth DB class, and simply defers the parent class
 * method
 * @package modules
 * @subpackage site
 */
class Custom_Auth extends Hm_Auth_DB {

    /**
     * This is the method new auth mechs need to override.
     * @param string $user username
     * @param string $pass password
     * @return bool true if the user is authenticated, false otherwise
     */
    public function check_credentials($user, $pass) {
        return parent::check_credentials($user, $pass);
    }
}

/**
 * Authenticate the single local Cypht identity managed by the Hub gateway.
 *
 * The managed password is also the encryption key for the user's persisted
 * mailbox settings. It must never be sent to the browser.
 */
class Local_Agent_Auth extends Hm_Auth {

    public function check_credentials($user, $pass) {
        $expected_user = getenv('LOCAL_AGENT_AUTH_USERNAME');
        $expected_pass = getenv('LOCAL_AGENT_AUTH_PASSWORD');
        if (!is_string($expected_user) || !is_string($expected_pass) ||
            $expected_user === '' || strlen($expected_pass) < 32) {
            Hm_Debug::add('Local Agent managed authentication is not configured');
            return false;
        }
        return hash_equals($expected_user, (string) $user) &&
            hash_equals($expected_pass, (string) $pass);
    }
}

/**
 * Persist settings immediately with the server-managed encryption key.
 *
 * Cypht normally asks the user to re-enter their local Cypht password before
 * saving settings. The Hub owns that local identity, so every mutation is
 * encrypted and saved without exposing the key to the browser.
 */
class Local_Agent_User_Config extends Hm_User_Config_File {

    private $managed_username;
    private $managed_password;
    private $site_config;
    private $baseline;

    public function __construct($config) {
        parent::__construct($config);
        $this->site_config = $config;
        $this->managed_username = getenv('LOCAL_AGENT_AUTH_USERNAME');
        $this->managed_password = getenv('LOCAL_AGENT_AUTH_PASSWORD');
        if (!is_string($this->managed_username) || $this->managed_username === '' ||
            !is_string($this->managed_password) || strlen($this->managed_password) < 32) {
            throw new Exception('Local Agent managed settings are not configured');
        }
        $this->config['no_password_save_setting'] = false;
        $this->baseline = $this->config;
    }

    public function load($username, $key) {
        if (!hash_equals($this->managed_username, (string) $username)) {
            $this->decrypt_failed = true;
            return;
        }
        parent::load($this->managed_username, $this->managed_password);
        $this->config['no_password_save_setting'] = false;
        $this->baseline = $this->config;
    }

    public function reload($data, $username = false) {
        $this->assert_can_persist();
        parent::reload($data, $this->managed_username);
        $this->config['no_password_save_setting'] = false;
        $this->save($this->managed_username, $this->managed_password);
    }

    public function save($username, $key) {
        $this->assert_can_persist();
        $this->config['no_password_save_setting'] = false;
        $this->persist(function($latest) {
            return $this->merge_config($this->baseline, $this->config, $latest);
        });
    }

    public function set($name, $value) {
        $this->assert_can_persist();
        if ($name === 'no_password_save_setting') {
            $value = false;
        }
        parent::set($name, $value);
        $this->save($this->managed_username, $this->managed_password);
    }

    public function del($name) {
        $this->assert_can_persist();
        $result = parent::del($name);
        if ($result) {
            $this->save($this->managed_username, $this->managed_password);
        }
        return $result;
    }

    public function reset_factory() {
        $this->assert_can_persist();
        parent::reset_factory();
        $this->config['no_password_save_setting'] = false;
        $this->save($this->managed_username, $this->managed_password);
    }

    private function assert_can_persist() {
        if ($this->decrypt_failed) {
            throw new Exception('Refusing to overwrite managed settings after decryption failed');
        }
    }

    private function persist($update) {
        $destination = $this->get_path($this->managed_username);
        $folder = dirname($destination);
        if (!is_dir($folder)) {
            throw new Exception("\"Users\" folder doesn't exist, please contact your site administrator.");
        }
        $lock = fopen($destination.'.lock', 'c');
        if ($lock === false) {
            throw new Exception('Unable to open managed settings lock');
        }
        chmod($destination.'.lock', 0600);
        try {
            if (!flock($lock, LOCK_EX)) {
                throw new Exception('Unable to lock managed settings');
            }
            $latest = $this->load_latest();
            $next = $update($latest);
            if (!is_array($next)) {
                throw new Exception('Managed settings update returned invalid data');
            }
            $this->config = $next;
            $this->config['no_password_save_setting'] = false;
            $this->write_atomic($destination);
            $this->baseline = $this->config;
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    private function load_latest() {
        $latest = new Hm_User_Config_File($this->site_config);
        $latest->load($this->managed_username, $this->managed_password);
        if ($latest->decrypt_failed) {
            throw new Exception('Refusing to overwrite managed settings after decryption failed');
        }
        $config = $latest->dump();
        $config['no_password_save_setting'] = false;
        return $config;
    }

    private function write_atomic($destination) {
        $removed = $this->filter_servers();
        $temporary = false;
        try {
            $json = json_encode($this->config);
            if (!is_string($json)) {
                throw new Exception('Unable to encode managed settings');
            }
            $data = Hm_Crypt::ciphertext($json, $this->managed_password);
            if (!is_string($data) || $data === '') {
                throw new Exception('Unable to encrypt managed settings');
            }
            $temporary = tempnam(dirname($destination), '.hub-settings-');
            if ($temporary === false) {
                throw new Exception('Unable to create managed settings file');
            }
            chmod($temporary, 0600);
            if (file_put_contents($temporary, $data, LOCK_EX) === false ||
                !rename($temporary, $destination)) {
                throw new Exception('Unable to write managed settings');
            }
            $temporary = false;
        } finally {
            $this->restore_servers($removed);
            if (is_string($temporary) && file_exists($temporary)) {
                unlink($temporary);
            }
        }
    }

    private function merge_config($baseline, $incoming, $latest) {
        $result = $latest;
        foreach ($baseline as $name => $value) {
            if (!array_key_exists($name, $incoming)) {
                unset($result[$name]);
            }
        }
        foreach ($incoming as $name => $value) {
            if (!array_key_exists($name, $baseline) || $baseline[$name] !== $value) {
                $result[$name] = $this->merge_value(
                    $baseline[$name] ?? null,
                    $value,
                    $latest[$name] ?? null
                );
            }
        }
        return $result;
    }

    private function merge_value($baseline, $incoming, $latest) {
        if (!is_array($baseline) || !is_array($incoming) || !is_array($latest)) {
            return $incoming;
        }
        $result = $latest;
        foreach ($baseline as $name => $value) {
            if (!array_key_exists($name, $incoming)) {
                unset($result[$name]);
            }
        }
        foreach ($incoming as $name => $value) {
            if (!array_key_exists($name, $baseline) || $baseline[$name] !== $value) {
                $result[$name] = $this->merge_value(
                    $baseline[$name] ?? null,
                    $value,
                    $latest[$name] ?? null
                );
            }
        }
        return $this->merge_oauth2_token_fields(
            $baseline,
            $incoming,
            $latest,
            $result
        );
    }

    private function merge_oauth2_token_fields($baseline, $incoming, $latest, $result) {
        if (($incoming['auth'] ?? false) !== 'xoauth2' ||
            ($latest['auth'] ?? false) !== 'xoauth2') {
            return $result;
        }
        $baseline_version = $baseline['oauth_refreshed_at'] ?? false;
        $incoming_version = $incoming['oauth_refreshed_at'] ?? false;
        $latest_version = $latest['oauth_refreshed_at'] ?? false;
        if (!is_numeric($incoming_version) || !is_numeric($latest_version) ||
            $incoming_version === $baseline_version ||
            $latest_version === $baseline_version) {
            return $result;
        }
        $winner = (float) $incoming_version > (float) $latest_version
            ? $incoming
            : $latest;
        foreach (array('pass', 'expiration', 'refresh_token', 'oauth_refreshed_at') as $name) {
            if (array_key_exists($name, $winner)) {
                $result[$name] = $winner[$name];
            } else {
                unset($result[$name]);
            }
        }
        return $result;
    }
}

/**
 * Store sessions in SQLite while suppressing obsolete unsaved-setting state.
 */
class Local_Agent_Session extends Hm_DB_Session {

    public function record_unsaved($value) {
        return;
    }
}

/*
function format_msg_html($str, $images=false) {
    return '';
}
*/
