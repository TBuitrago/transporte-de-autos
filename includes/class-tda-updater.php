<?php
/**
 * GitHub updater for Transporte de Autos plugin.
 */

if (!defined('ABSPATH')) {
    exit;
}

class TDA_GitHub_Updater {
    /**
     * Plugin file path.
     *
     * @var string
     */
    private $plugin_file;

    /**
     * Plugin basename.
     *
     * @var string
     */
    private $plugin_basename;

    /**
     * Plugin slug used in the update API.
     *
     * @var string
     */
    private $slug;

    /**
     * Owner of the GitHub repository.
     *
     * @var string
     */
    private $github_user;

    /**
     * Repository name on GitHub.
     *
     * @var string
     */
    private $github_repo;

    /**
     * Cached release data.
     *
     * @var object|false|null
     */
    private $release_data = null;

    /**
     * Cache key for release information.
     *
     * @var string
     */
    private $cache_key;

    /**
     * Additional slugs that should resolve to this plugin.
     *
     * @var array
     */
    private $additional_slugs = array();

    /**
     * Constructor.
     *
     * @param string $plugin_file Absolute path to the main plugin file.
     * @param array  $options     Optional configuration overrides.
     */
    public function __construct($plugin_file, $options = array()) {
        $this->plugin_file     = $plugin_file;
        $this->plugin_basename = plugin_basename($plugin_file);
        $default_slug          = basename($this->plugin_basename, '.php');
        $this->slug            = isset($options['slug']) ? $options['slug'] : $default_slug;
        $this->additional_slugs = isset($options['additional_slugs']) ? (array) $options['additional_slugs'] : array('transporte-de-autos', $default_slug);
        $this->additional_slugs = array_values(array_unique(array_filter($this->additional_slugs)));
        $this->github_user     = isset($options['user']) ? $options['user'] : 'tbadigitals';
        $this->github_repo     = isset($options['repo']) ? $options['repo'] : 'transporte-de-autos';
        $this->cache_key       = 'tda_github_release_' . md5($this->github_user . '/' . $this->github_repo);

        add_filter('pre_set_site_transient_update_plugins', array($this, 'check_for_update'));
        add_filter('plugins_api', array($this, 'plugins_api'), 10, 3);
        add_filter('http_request_args', array($this, 'set_github_headers'), 10, 2);
    }

    /**
     * Add headers required by the GitHub API.
     */
    public function set_github_headers($args, $url) {
        if (strpos($url, 'api.github.com') === false) {
            return $args;
        }

        if (empty($args['headers']['User-Agent'])) {
            $args['headers']['User-Agent'] = 'Transporte-de-Autos-Updater';
        }

        if (empty($args['headers']['Accept'])) {
            $args['headers']['Accept'] = 'application/vnd.github+json';
        }

        return $args;
    }

    /**
     * Retrieve and cache the latest GitHub release.
     *
     * @return object|false
     */
    private function get_latest_release() {
        if (!is_null($this->release_data)) {
            return $this->release_data;
        }

        $cached = get_site_transient($this->cache_key);
        if ($cached !== false) {
            if (is_array($cached) && !empty($cached['success'])) {
                $this->release_data = $cached['release'];
                return $this->release_data;
            }

            if (is_array($cached) && isset($cached['success']) && !$cached['success']) {
                $this->release_data = false;
                return false;
            }
        }

        $response = wp_remote_get(
            sprintf('https://api.github.com/repos/%s/%s/releases', $this->github_user, $this->github_repo),
            array(
                'timeout' => 15,
                'headers' => array(
                    'Accept'     => 'application/vnd.github+json',
                    'User-Agent' => 'Transporte-de-Autos-Updater',
                ),
            )
        );

        if (is_wp_error($response)) {
            set_site_transient($this->cache_key, array('success' => false), HOUR_IN_SECONDS);
            $this->release_data = false;
            return false;
        }

        $code = wp_remote_retrieve_response_code($response);
        if ((int) $code !== 200) {
            set_site_transient($this->cache_key, array('success' => false), HOUR_IN_SECONDS);
            $this->release_data = false;
            return false;
        }

        $body = wp_remote_retrieve_body($response);
        $data = json_decode($body);

        if (!is_array($data)) {
            set_site_transient($this->cache_key, array('success' => false), HOUR_IN_SECONDS);
            $this->release_data = false;
            return false;
        }

        foreach ($data as $release) {
            if (!empty($release->draft) || !empty($release->prerelease)) {
                continue;
            }

            $this->release_data = $release;
            set_site_transient($this->cache_key, array(
                'success' => true,
                'release' => $release,
            ), 6 * HOUR_IN_SECONDS);

            return $this->release_data;
        }

        set_site_transient($this->cache_key, array('success' => false), HOUR_IN_SECONDS);
        $this->release_data = false;

        return false;
    }

    /**
     * Normalize version strings by removing the leading "v" if present.
     *
     * @param string $tag Version tag from GitHub.
     *
     * @return string|null
     */
    private function normalize_version($tag) {
        if (!is_string($tag) || $tag === '') {
            return null;
        }

        return ltrim($tag, 'vV');
    }

    /**
     * Determine the download URL for the release package.
     *
     * @param object $release GitHub release data.
     *
     * @return string
     */
    private function get_package_url($release) {
        if (!empty($release->assets) && is_array($release->assets)) {
            foreach ($release->assets as $asset) {
                if (!empty($asset->browser_download_url)) {
                    return $asset->browser_download_url;
                }
            }
        }

        return !empty($release->zipball_url) ? $release->zipball_url : '';
    }

    /**
     * Hooked into the update transient to inject release information.
     *
     * @param object $transient Update transient object.
     *
     * @return object
     */
    public function check_for_update($transient) {
        if (!is_object($transient)) {
            $transient = new stdClass();
        }

        if (empty($transient->checked) || !isset($transient->checked[$this->plugin_basename])) {
            return $transient;
        }

        $release = $this->get_latest_release();
        if (!$release) {
            return $transient;
        }

        $current_version = $transient->checked[$this->plugin_basename];
        $new_version     = $this->normalize_version($release->tag_name);

        if (empty($new_version) || version_compare($new_version, $current_version, '<=')) {
            return $transient;
        }

        $package = $this->get_package_url($release);
        if (empty($package)) {
            return $transient;
        }

        $plugin              = new stdClass();
        $plugin->slug        = $this->slug;
        $plugin->plugin      = $this->plugin_basename;
        $plugin->new_version = $new_version;
        $plugin->package     = $package;
        $plugin->url         = !empty($release->html_url) ? $release->html_url : sprintf('https://github.com/%s/%s', $this->github_user, $this->github_repo);

        $transient->response[$this->plugin_basename] = $plugin;

        return $transient;
    }

    /**
     * Provide plugin information for the modal dialog in WordPress.
     *
     * @param mixed  $result Existing result.
     * @param string $action Requested action.
     * @param object $args   Request arguments.
     *
     * @return mixed
     */
    public function plugins_api($result, $action, $args) {
        if ('plugin_information' !== $action) {
            return $result;
        }

        $valid_slugs = array_merge(
            array(
                $this->slug,
                $this->plugin_basename,
                basename($this->plugin_basename, '.php'),
            ),
            $this->additional_slugs
        );
        $valid_slugs = array_values(array_unique(array_filter($valid_slugs)));

        if (empty($args->slug) || !in_array($args->slug, $valid_slugs, true)) {
            return $result;
        }

        $release = $this->get_latest_release();
        if (!$release) {
            return $result;
        }

        $version     = $this->normalize_version($release->tag_name);
        $package_url = $this->get_package_url($release);

        $release_notes = !empty($release->body) ? wp_kses_post(wpautop($release->body)) : wp_kses_post(wpautop(esc_html__('No release notes available.', 'transporte-de-autos')));

        $info = new stdClass();
        $info->name          = 'Transporte de Autos';
        $info->slug          = $this->slug;
        $info->version       = $version ? $version : (defined('TDA_VERSION') ? TDA_VERSION : SDPI_VERSION);
        $info->author        = '<a href="https://tbadigitals.com">TBA Digitals</a>';
        $info->homepage      = sprintf('https://github.com/%s/%s', $this->github_user, $this->github_repo);
        $info->requires      = '5.0';
        $info->tested        = '6.4';
        $info->download_link = $package_url;
        $info->last_updated  = !empty($release->published_at) ? mysql2date('Y-m-d H:i:s', $release->published_at, false) : '';
        $info->sections      = array(
            'description' => wp_kses_post(wpautop(__('Transporte de Autos integra la API de Super Dispatch para cotizar envíos de vehículos en tiempo real.', 'transporte-de-autos'))),
            'changelog'   => $release_notes,
        );
        $info->banners       = array();
        $info->external      = true;

        return $info;
    }
}
