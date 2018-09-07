<?php

/**
 * Mutiple Domain WordPress plugin.
 *
 * @author  Gustavo Straube <https://github.com/straube>
 * @author  Vivek Athalye <https://github.com/vnathalye>
 * @author  Clay Allsopp <https://github.com/clayallsopp>
 * @author  Alexander Nosov <https://github.com/cyberaleks>
 * @version 0.7
 * @package multiple-domain
 */
class MultipleDomain
{

    /**
     * The plugin version.
     *
     * @var   string
     * @since 0.3
     */
    const VERSION = '0.7.1';

    /**
     * The current domain.
     *
     * This property's value also may include the host port when it's
     * different than 80 (default HTTP port) or 443 (default HTTPS port).
     *
     * @var   string
     * @since 0.2
     */
    private $domain = null;

    /**
     * The original domain set in WordPress installation.
     *
     * @var   string
     * @since 0.3
     */
    private $originalDomain = null;

    /**
     * The list of available domains.
     *
     * In standard situtations, this array will hold all available domains as
     * its keys. The optional base URL will be the value for a given domain
     * (key) when set, otherwise the value will be `NULL`.
     *
     * @var string
     */
    private $domains = [];

    /**
     * Plugin activation tasks.
     *
     * @return void
     * @since  0.7
     */
    public static function activate()
    {
        add_option('multiple-domain-domains', []);
    }

    /**
     * Adds actions and filters required by the plugin.
     *
     * @return void
     */
    public function setup()
    {
        $this->initAttributes();
        $this->hookActions();
        $this->hookFilters();
    }

    /**
     * Return the current domain.
     *
     * Since this value is checked against plugin settings, it may not reflect
     * the actual current domain in `HTTP_HOST` element from `$_SERVER`.
     *
     * @return string|null The domain.
     * @since  0.2
     */
    public function getDomain()
    {
        return $this->domain;
    }

    /**
     * Return original domain set in WordPress installation.
     *
     * @return string The domain.
     * @since  0.3
     */
    public function getOriginalDomain()
    {
        return $this->originalDomain;
    }

    /**
     * When the current domains has a base URL restriction, redirects the user
     * if the current request URI doesn't match it.
     *
     * @return void
     */
    public function redirect()
    {
        /*
         * Allow developers to create their own logic for redirection.
         */
        do_action('multiple_domain_redirect', $this->domain);
        $base = !empty($this->domains[$this->domain]) ? $this->domains[$this->domain] : '';
        $base = is_array($base) ? $base['base'] : $base;
        if (!empty($base) && !empty($_SERVER['REQUEST_URI']) && strpos($_SERVER['REQUEST_URI'], $base) !== 0) {
            wp_redirect(home_url($base));
            exit;
        }
    }

    /**
     * Sets up the required settings to show in the admin.
     *
     * @return void
     */
    public function settings()
    {
        add_settings_section('multiple-domain', __('Multiple Domain', 'multiple-domain'), [ $this, 'settingsHeading' ], 'general');
        add_settings_field('multiple-domain-domains', __('Domains', 'multiple-domain'), [ $this, 'settingsFields' ], 'general', 'multiple-domain');
        register_setting('general', 'multiple-domain-domains', [ $this, 'sanitizeSettings' ]);
    }

    /**
     * Sanitizes the settings.
     *
     * It takes the value sent by the user in the settings form and parses it
     * to store in the correct format.
     *
     * @param  array $value The user defined option value.
     * @return array The sanitized option value.
     */
    public function sanitizeSettings($value)
    {
        $domains = [];
        if (is_array($value)) {
            foreach ($value as $row) {
                if (empty($row['host'])) {
                    continue;
                }
                $base = !empty($row['base']) ? $row['base'] : null;
                $lang = !empty($row['lang']) ? $row['lang'] : null;
                $domains[$row['host']] = [
                    'base' => $base,
                    'lang' => $lang,
                ];
            }
        }
        return $domains;
    }

    /**
     * Renders the settings heading.
     *
     * @return void
     */
    public function settingsHeading()
    {
        echo '<p>' . __('You can use multiple domains in your WordPress defining them below. It\'s possible to limit the access for each domain to a base URL.', 'multiple-domain') . '</p>';
    }

    /**
     * Renders the settings field.
     *
     * @return void
     */
    public function settingsFields()
    {
        $fields = '';
        $counter = 0;
        foreach ($this->domains as $domain => $values) {
            /*
             * Backward compatibility with earlier versions.
             */
            if (is_string($values)) {
                $base = $value;
                $lang = null;
            } else {
                $base = !empty($values['base']) ? $values['base'] : null;
                $lang = !empty($values['lang']) ? $values['lang'] : null;
            }
            $fields .= $this->getDomainFields($counter++, $domain, $base, $lang);
        }
        if (empty($fields)) {
            $fields = $this->getDomainFields(0);
        }
        $fieldsToAdd = $this->getDomainFields('COUNT');
        echo $fields .
            '<p><button type="button" class="button multiple-domain-add">' . __('Add domain', 'multiple-domain') . '</button></p>' .
            '<p class="description">' . __('A domain may contain the port number when that\'s not the default HTTP (80) or HTTPS (443) port. If a base URL restriction is set for a domain, all requests that don\'t start with the base URL will be redirected to the base URL. <b>Example</b>: the domain and base URL are <code>example.com</code> and </code>/base/path</code>, when requesting <code>example.com/other/path</code> it will be redirected to <code>example.com/base/path</code>. Additionaly, it\'s possible to set a language for each domain, which will be used to add <code>&lt;link&gt;</code> tags with a <code>hreflang</code> attribute to the document head.', 'multiple-domain') . '</p>' .
            '<script type="text/javascript">var multipleDomainFields = ' . json_encode($fieldsToAdd) . ';</script>';
    }

    /**
     * Enqueues the required scripts.
     *
     * @param  string $hook The current admin page.
     * @return void
     * @since  0.3
     */
    public function scripts($hook)
    {
        if ($hook !== 'options-general.php') {
            return;
        }
        wp_enqueue_script('multiple-domain-settings', plugins_url('settings.js', __FILE__), [ 'jquery' ], self::VERSION, true);
    }

    /**
     * Replaces the domain.
     *
     * The domain in the given URL is replaced by the current domain. If the
     * URL contains `/wp-admin/` it'll be ignored when replacing the domain and
     * returned as is.
     *
     * @param  string $url The URL to update.
     * @return string The domain replaced URL.
     */
    public function replaceDomain($url)
    {
        if (array_key_exists($this->domain, $this->domains) && !preg_match('/\/wp-admin\/?/', $url)) {
            $domain = $this->getDomainFromUrl($url);
            $url = str_replace($domain, $this->domain, $url);
        }
        return $url;
    }

    /**
     * Replaces the domain in upload_dir filter used by `wp_upload_dir()`.
     *
     * The domain in the given `url` and `baseurl` is replaced by the current domain.
     *
     * @param  array $uploads The array of `url`, `baseurl` and other properties.
     * @return array The domain replaced URLs in the given array.
     * @since  0.4
     */
    public function fixUploadDir($uploads)
    {
        $uploads['url'] = $this->replaceDomain($uploads['url']);
        $uploads['baseurl'] = $this->replaceDomain($uploads['baseurl']);
        return $uploads;
    }

    /**
     * Add `hreflang` links to head for SEO purpose.
     *
     * @return void
     * @author Alexander Nosov <https://github.com/cyberaleks>
     * @since  0.4
     */
    public function addHrefLangHeader()
    {
        $uri = $_SERVER['REQUEST_URI'];
        $protocol = !isset($_SERVER['HTTPS']) || 'off' == $_SERVER['HTTPS'] ? 'http://' : 'https://';
        $this->outputHrefLangHeader($protocol . $this->originalDomain . $uri);

        foreach ($this->domains as $key => $values) {
            if (!is_array($values) || empty($values['lang'])) {
                continue;
            }

            $url = $key . $values['base'] . $uri;

            /*
             * Prepend the current protocol if none is set.
             */
            if (!preg_match('/https?:\/\//', $values['base'])) {
                $url = $protocol . $url;
            }
            $this->outputHrefLangHeader($url, $values['lang']);
        }
    }

    private function initAttributes()
    {
        $ignoreDefaultPort = true;
        $headerHost = !empty($_SERVER['HTTP_X_HOST']) ? $_SERVER['HTTP_X_HOST'] : $_SERVER['HTTP_HOST'];
        if (!empty($headerHost)) {
            $domain = $headerHost;
            $matches = [];
            if (preg_match('/^(.*):(\d+)$/', $domain, $matches) && $this->isDefaultPort($matches[2])) {
                $domain = $matches[1];
            }
            $this->domain = $domain;
        }
        $this->domains = get_option('multiple-domain-domains');
        if (!is_array($this->domains)) {
            $this->domains = [];
        }
        $this->originalDomain = $this->getDomainFromUrl(get_option('home'), $ignoreDefaultPort);
        if (!array_key_exists($this->domain, $this->domains)) {
            $this->domain = $this->originalDomain;
        }
    }

    private function hookActions()
    {
        add_action('init', [ $this, 'redirect' ]);
        add_action('admin_init', [ $this, 'settings' ]);
        add_action('admin_enqueue_scripts', [ $this, 'scripts' ]);
        add_action('wp_head', [ $this, 'addHrefLangHeader' ]);
    }

    private function hookFilters()
    {
        add_filter('content_url', [ $this, 'replaceDomain' ]);
        add_filter('option_siteurl', [ $this, 'replaceDomain' ]);
        add_filter('option_home', [ $this, 'replaceDomain' ]);
        add_filter('plugins_url', [ $this, 'replaceDomain' ]);
        add_filter('wp_get_attachment_url', [ $this, 'replaceDomain' ]);
        add_filter('upload_dir', [ $this, 'fixUploadDir' ]);
    }

    /**
     * Parses the given URL to return only its domain.
     *
     * The server port may be included in the returning value.
     *
     * @param string $url The URL to parse.
     * @param bool $ignoreDefaultPort If `true` is passed to this value, a
     *              default HTTP or HTTPS port will be ignored even if it's
     *              present in the URL.
     * @return string The domain.
     * @since 0.2
     */
    private function getDomainFromUrl($url, $ignoreDefaultPort = false)
    {
        $parts = parse_url($url);
        $domain = $parts['host'];
        if (!empty($parts['port']) && !($ignoreDefaultPort && $this->isDefaultPort($parts['port']))) {
            $domain .= ':' . $parts['port'];
        }
        return $domain;
    }

    /**
     * Checks if the given port is a default HTTP (80) or HTTPS (443) port.
     *
     * @param  int $port The port to check.
     * @return bool Indicates if the port is a default one.
     * @since  0.2
     */
    private function isDefaultPort($port)
    {
        $port = (int) $port;
        return $port === 80 || $port === 443;
    }

    /**
     * Returns the fields for a domain setting.
     *
     * @param  int $count The field count. It's used within the field name,
     *              since it's an array.
     * @param  string $host The host field value.
     * @param  string $base The base URL field value.
     * @return string
     * @since  0.3
     */
    private function getDomainFields($count, $host = null, $base = null, $lang = null)
    {
        $fields = '<p class="multiple-domain-domain">' .
            '<input type="text" name="multiple-domain-domains[' . $count . '][host]" value="' . ($host ?: '') . '" class="regular-text code" placeholder="example.com" title="Domain"> ' .
            '<input type="text" name="multiple-domain-domains[' . $count . '][base]" value="' . ($base ?: '') . '" class="regular-text code" placeholder="/base/path" title="Base path restriction"> ' .
            '<input type="text" name="multiple-domain-domains[' . $count . '][lang]" value="' . ($lang ?: '') . '" class="regular-text code" placeholder="en-US" title="Language"> ' .
            '<button type="button" class="button multiple-domain-remove"><span class="required">Remove</span></button>' .
            '</p>';
        return $fields;
    }

    /**
     * Prints a `hreflang` link tag.
     *
     * @param  string $url The URL to be set into `href` attribute.
     * @param  string $lang The language code to be set into `hreflang`
     *              attribute. Defaults to `'x-default'`.
     * @return void
     * @since  0.5
     */
    private function outputHrefLangHeader($url, $lang = 'x-default')
    {
        printf('<link rel="alternate" href="%s" hreflang="%s"/>', $url, $lang);
    }
}
