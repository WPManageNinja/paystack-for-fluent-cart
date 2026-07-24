<?php

namespace PaystackFluentCart\PluginManager;

if (!defined('ABSPATH')) {
    exit;
}

class Updater
{
    private $store_url = '';
    private $name = '';
    private $slug = '';
    private $version = '';
    private $addon_slug = '';
    private $parent_product_id = '';
    private $license_key = '';
    private $activation_hash = '';
    private $plugin_title = '';
    private $is_free = false;

    private $response_transient_key;
    private $license_notice_transient_key;

    /**
     * @param string $_store_url   The FluentCart store URL.
     * @param string $_plugin_file Path to the plugin file.
     * @param array  $_config      Configuration:
     *   - version           (string) Current addon version
     *   - addon_slug        (string) Must match the slug in Addon Assets settings on the store
     *   - parent_product_id (int)    FluentCart product ID on the store
     *   - license_key       (string) Optional, auto-detected from FluentCart Pro if empty
     *   - activation_hash   (string) Optional, auto-detected from FluentCart Pro if empty
     *   - plugin_title      (string) Display name for update notices
     *   - is_free           (bool)   When true, the updater never reads or sends a
     *                                FluentCart Pro license and never shows a license
     *                                activation notice. Updates are served by the store's
     *                                free (verify_license = false) addon asset flow.
     */
    public function __construct($_store_url, $_plugin_file, $_config = [])
    {
        $this->store_url = rtrim($_store_url, '/');
        $this->name = plugin_basename($_plugin_file);
        $this->slug = basename($_plugin_file, '.php');

        $this->response_transient_key = md5(sanitize_key($this->name) . 'response_transient');
        $this->license_notice_transient_key = md5(sanitize_key($this->name) . 'license_notice_transient');

        $this->version = $_config['version'] ?? '1.0.0';
        $this->addon_slug = $_config['addon_slug'] ?? '';
        $this->parent_product_id = $_config['parent_product_id'] ?? '';
        $this->license_key = $_config['license_key'] ?? '';
        $this->activation_hash = $_config['activation_hash'] ?? '';
        $this->plugin_title = $_config['plugin_title'] ?? '';
        $this->is_free = !empty($_config['is_free']);

        $this->init();
    }

    public function init()
    {
        $this->maybeDeleteTransients();

        add_filter('pre_set_site_transient_update_plugins', [$this, 'checkUpdate'], 51);
        add_action('delete_site_transient_update_plugins', [$this, 'deleteTransients']);
        add_filter('plugins_api', [$this, 'pluginsApiFilter'], 10, 3);

        remove_action('after_plugin_row_' . $this->name, 'wp_plugin_update_row');
        add_action('after_plugin_row_' . $this->name, [$this, 'showUpdateNotification'], 10, 2);

        // Free addons update without a license, so the license activation notice
        // is never registered.
        if (!$this->is_free) {
            add_action('admin_notices', [$this, 'showLicenseActivationNotice']);
        }
    }

    public function checkUpdate($_transient_data)
    {
        global $pagenow;

        if (!is_object($_transient_data)) {
            $_transient_data = new \stdClass();
        }

        if ('plugins.php' === $pagenow && is_multisite()) {
            return $_transient_data;
        }

        return $this->checkTransientData($_transient_data);
    }

    private function checkTransientData($_transient_data)
    {
        if (!is_object($_transient_data)) {
            $_transient_data = new \stdClass();
        }

        if (empty($_transient_data->checked)) {
            return $_transient_data;
        }

        $versionInfo = $this->getTransient($this->response_transient_key);

        if (false === $versionInfo) {
            $versionInfo = $this->apiRequest();
            if (is_wp_error($versionInfo)) {
                $versionInfo = new \stdClass();
                $versionInfo->error = true;
            }
            $this->setTransient($this->response_transient_key, $versionInfo);
        }

        if (!empty($versionInfo->error) || !$versionInfo) {
            unset($_transient_data->response[$this->name]);
            unset($_transient_data->no_update[$this->name]);
            return $_transient_data;
        }

        if (is_object($versionInfo) && isset($versionInfo->new_version)) {
            $hasValidPackage = !empty($versionInfo->package) && wp_http_validate_url($versionInfo->package);

            if (version_compare($this->version, $versionInfo->new_version, '<') && $hasValidPackage) {
                $_transient_data->response[$this->name] = $versionInfo;
            } else {
                unset($_transient_data->response[$this->name]);
            }

            $_transient_data->last_checked = time();
            $_transient_data->checked[$this->name] = $this->version;
        }

        return $_transient_data;
    }

    public function showUpdateNotification($file, $plugin)
    {
        if (is_network_admin() || !current_user_can('update_plugins')) {
            return;
        }

        if ($this->name !== $file) {
            return;
        }

        remove_filter('pre_set_site_transient_update_plugins', [$this, 'checkUpdate']);

        $updateCache = get_site_transient('update_plugins');
        $updateCache = $this->checkTransientData($updateCache);
        set_site_transient('update_plugins', $updateCache);

        add_filter('pre_set_site_transient_update_plugins', [$this, 'checkUpdate']);
    }

    public function pluginsApiFilter($_data, $_action = '', $_args = null)
    {
        if ('plugin_information' !== $_action) {
            return $_data;
        }

        if (!isset($_args->slug) || $_args->slug !== $this->slug) {
            return $_data;
        }

        $cacheKey = $this->slug . '_api_request_' . substr(md5(serialize($this->slug)), 0, 15);

        global $pagenow;
        $apiRequestTransient = ('plugin-install.php' === $pagenow) ? false : get_site_transient($cacheKey);

        if (empty($apiRequestTransient)) {
            $apiRequestTransient = $this->apiRequest();

            if ($apiRequestTransient && !is_wp_error($apiRequestTransient)) {
                set_site_transient($cacheKey, $apiRequestTransient, DAY_IN_SECONDS * 2);
            }
        }

        if ($apiRequestTransient && !is_wp_error($apiRequestTransient)) {
            $_data = $apiRequestTransient;
        } else {
            $_data = $this->getFallbackPluginInfo();
        }

        return $_data;
    }

    private function getFallbackPluginInfo()
    {
        $pluginPageUrl = $this->store_url ?: 'https://fluentcart.com';
        $pluginName = $this->plugin_title ?: $this->slug;

        $info = new \stdClass();
        $info->name = $pluginName;
        $info->slug = $this->slug;
        $info->version = $this->version;
        $info->homepage = $pluginPageUrl;
        $info->author = '<a href="' . esc_url($pluginPageUrl) . '">' . esc_html($pluginName) . '</a>';
        $info->sections = [
            'description' => sprintf(
                '<p>%s</p><p><a href="%s" target="_blank" rel="noopener noreferrer" class="button button-primary">%s</a></p>',
                esc_html__('Full version details are available on the plugin page.', 'paystack-for-fluent-cart'),
                esc_url($pluginPageUrl),
                esc_html__('View Plugin Page &rarr;', 'paystack-for-fluent-cart')
            ),
        ];

        return $info;
    }

    private function apiRequest()
    {
        if ($this->store_url === home_url()) {
            return false;
        }

        $siteUrl = is_multisite() ? network_site_url() : home_url();

        $licenseKey = '';
        $activationHash = '';

        // Free addons never depend on a FluentCart Pro license. Only resolve and
        // send license data for paid addons.
        if (!$this->is_free) {
            $licenseKey = $this->license_key;
            $activationHash = $this->activation_hash;

            if (!$licenseKey && !$activationHash) {
                $stored = $this->getParentLicenseInfo();
                $licenseKey = $stored['license_key'];
                $activationHash = $stored['activation_hash'];
            }
        }

        $request = wp_remote_post(add_query_arg(['fluent-cart' => 'get_license_version'], $this->store_url), [
            'timeout'   => 15,
            'sslverify' => true,
            'body'      => [
                'item_id'         => $this->parent_product_id,
                'addon_slug'      => $this->addon_slug,
                'license_key'     => $licenseKey,
                'activation_hash' => $activationHash,
                'site_url'        => $siteUrl,
                'current_version' => $this->version,
            ],
        ]);

        if (is_wp_error($request)) {
            return $request;
        }

        $request = json_decode(wp_remote_retrieve_body($request));

        // A free addon never surfaces a license activation notice.
        if (!$this->is_free) {
            if ($request && isset($request->license_status) && $request->license_status !== 'valid') {
                $this->setTransient($this->license_notice_transient_key, [
                    'status'  => sanitize_text_field($request->license_status),
                    'message' => sanitize_text_field($request->license_message ?? ''),
                ]);
            } else {
                $this->deleteTransient($this->license_notice_transient_key);
            }
        }

        if ($request && isset($request->sections)) {
            if (isset($request->slug) && $request->slug !== $this->addon_slug) {
                return false;
            }

            $sections = maybe_unserialize($request->sections);

            if (is_object($sections)) {
                $sections = (array) $sections;
            }

            if (!is_array($sections)) {
                $sections = [];
            }

            if (empty($sections['description'])) {
                $sections['description'] = sprintf(
                    '<p>%s</p>',
                    esc_html__('Full version details are available on the plugin page.', 'paystack-for-fluent-cart')
                );
            }

            if (empty($sections['changelog'])) {
                $sections['changelog'] = $sections['description'];
            }

            $request->sections = $sections;
            $request->slug = $this->slug;
            $request->plugin = $this->name;
        } else {
            $request = false;
        }

        return $request;
    }

    public function showLicenseActivationNotice()
    {
        // Free addons do not require a license, so no activation notice is shown.
        if ($this->is_free) {
            return;
        }

        global $pagenow;

        if (!in_array($pagenow, ['plugins.php', 'update-core.php'], true)) {
            return;
        }

        if (!current_user_can('update_plugins')) {
            return;
        }

        $notice = $this->getTransient($this->license_notice_transient_key);
        if (!$notice || (($notice['status'] ?? '') === 'valid')) {
            return;
        }

        $activateUrl = admin_url('admin.php?page=fluent-cart#/settings/licensing');
        $pluginTitle = $this->plugin_title ?: $this->slug;

        /* translators: %1$s: Plugin title such as Paystack for FluentCart */
        $message = sprintf(
            __('%1$s updates require an active FluentCart Pro license. Please activate your FluentCart Pro license to receive updates.', 'paystack-for-fluent-cart'),
            esc_html($pluginTitle)
        );

        printf(
            '<div class="notice notice-warning"><p>%1$s <a href="%2$s">%3$s</a></p></div>',
            wp_kses_post($message),
            esc_url($activateUrl),
            esc_html__('Activate License', 'paystack-for-fluent-cart')
        );
    }

    private function getParentLicenseInfo()
    {
        $licenseInfo = get_option('__fluent-cart-pro_sl_info', []);

        if (!empty($licenseInfo['license_key'])) {
            return [
                'license_key'     => $licenseInfo['license_key'] ?? '',
                'activation_hash' => $licenseInfo['activation_hash'] ?? '',
            ];
        }

        return ['license_key' => '', 'activation_hash' => ''];
    }

    private function maybeDeleteTransients()
    {
        global $pagenow;

        if ('update-core.php' === $pagenow && isset($_GET['force-check'])) {
            $this->deleteTransients();
        }

        $checkUpdateKey = $this->slug . '-check-update';

        if (isset($_GET[$checkUpdateKey]) && current_user_can('update_plugins')) {
            check_admin_referer($checkUpdateKey);

            $this->deleteTransients();

            remove_filter('pre_set_site_transient_update_plugins', [$this, 'checkUpdate']);

            $updateCache = get_site_transient('update_plugins');
            if ($updateCache && is_object($updateCache)) {
                if (!empty($updateCache->response[$this->name])) {
                    unset($updateCache->response[$this->name]);
                }
                if (!empty($updateCache->no_update[$this->name])) {
                    unset($updateCache->no_update[$this->name]);
                }
            }

            $updateCache = $this->checkTransientData($updateCache);
            set_site_transient('update_plugins', $updateCache);

            add_filter('pre_set_site_transient_update_plugins', [$this, 'checkUpdate']);

            wp_safe_redirect(admin_url('plugins.php?s=' . rawurlencode($this->slug) . '&plugin_status=all'));
            exit();
        }
    }

    public function deleteTransients()
    {
        $this->deleteTransient($this->response_transient_key);
        $this->deleteTransient($this->license_notice_transient_key);
    }

    protected function deleteTransient($cache_key)
    {
        delete_option($cache_key);
    }

    protected function getTransient($cache_key)
    {
        $cacheData = get_option($cache_key);

        if (empty($cacheData['timeout']) || current_time('timestamp') > $cacheData['timeout']) {
            return false;
        }

        return $cacheData['value'];
    }

    protected function setTransient($cache_key, $value, $expiration = 0)
    {
        if (empty($expiration)) {
            $expiration = strtotime('+12 hours', current_time('timestamp'));
        }

        update_option($cache_key, ['timeout' => $expiration, 'value' => $value], 'no');
    }
}
