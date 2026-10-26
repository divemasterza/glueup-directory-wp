<?php

/**
 * Plugin Name:     Glueup
 * Plugin URI:      https://bigambitions.co.za
 * Description:     Plugin to display the members directory
 * Author:          Steph Reinstein
 * Author URI:      https://bigambitions.co.za
 * Text Domain:     glueup
 * Domain Path:     /languages
 * Version:         0.1.6
 * GitHub Plugin URI: divemasterza/glueup-directory-wp
 * Update URI: false
 * @package         Glueup
 */

if (!defined('ABSPATH')) {
    exit; // Prevent direct access
}

// enqueue scripts
function enqueue_scripts()
{
    wp_enqueue_script('jquery');
    wp_enqueue_script('datatables', 'https://cdn.datatables.net/v/dt/dt-1.13.4/datatables.min.js', array('jquery'), '1.10.25', true);
    wp_enqueue_style('datatables', 'https://cdn.datatables.net/v/dt/dt-1.13.4/datatables.min.css', array(), '1.10.25');
    wp_enqueue_script('datatables-responsive', 'https://cdn.datatables.net/responsive/2.2.9/js/dataTables.responsive.min.js', array('jquery', 'datatables'), '2.2.9', true);
    wp_enqueue_style('datatables-responsive', 'https://cdn.datatables.net/responsive/2.2.9/css/responsive.dataTables.min.css', array(), '2.2.9');
    wp_enqueue_style('custom-datatables-style',  plugin_dir_url(__FILE__) . '/datatables-custom.css', array(), '1.0.0');
}

add_action('wp_enqueue_scripts', 'enqueue_scripts');




add_filter('http_request_timeout', 'timeout_extend');

function timeout_extend($time)
{
    // Default timeout is 5
    return 15;
}


// -----------------------------------------------------------------
// Settings
// -----------------------------------------------------------------
function directory_register_settings()
{
    register_setting('directory', 'directory_public_key', array(
        'type'              => 'string',
        'sanitize_callback' => 'sanitize_text_field',
    ));
    register_setting('directory', 'directory_private_key', array(
        'type'              => 'string',
        'sanitize_callback' => 'glueup_sanitize_private_key',
    ));

    add_settings_section('directory_keys', 'API keys', 'glueup_keys_section_callback', 'directory');

    add_settings_field('directory_public_key', 'Public key', 'directory_public_key_callback', 'directory', 'directory_keys', array('label_for' => 'directory_public_key'));
    add_settings_field('directory_private_key', 'Private key', 'directory_private_key_callback', 'directory', 'directory_keys', array('label_for' => 'directory_private_key'));
}
add_action('admin_init', 'directory_register_settings');

function glueup_keys_section_callback()
{
    echo '<p>Find these in Glue Up under your organization\'s API settings. Saving new keys clears the cached directory.</p>';
}

// Keep the stored private key when the field is left blank, so the secret
// never has to be printed back into the page.
function glueup_sanitize_private_key($value)
{
    $value = sanitize_text_field($value);
    if ($value === '') {
        return (string) get_option('directory_private_key', '');
    }
    return $value;
}

function directory_public_key_callback()
{
    $public_key = get_option('directory_public_key');
    echo '<input type="text" class="regular-text code" id="directory_public_key" name="directory_public_key" value="' . esc_attr($public_key) . '" autocomplete="off">';
}

function directory_private_key_callback()
{
    $has_key = (string) get_option('directory_private_key', '') !== '';
    echo '<input type="password" class="regular-text code" id="directory_private_key" name="directory_private_key" value="" autocomplete="new-password" placeholder="' . esc_attr($has_key ? 'Saved. Leave blank to keep the current key' : 'Enter your private key') . '">';
    if ($has_key) {
        echo '<p class="description">A private key is saved. It is not shown here for security.</p>';
    }
}

// Changing either key invalidates the cached directory
function glueup_keys_changed()
{
    delete_transient('api_data');
    delete_option('glueup_last_fetch');
}
add_action('update_option_directory_public_key', 'glueup_keys_changed');
add_action('update_option_directory_private_key', 'glueup_keys_changed');
add_action('add_option_directory_public_key', 'glueup_keys_changed');
add_action('add_option_directory_private_key', 'glueup_keys_changed');

// -----------------------------------------------------------------
// Admin menu, actions and page
// -----------------------------------------------------------------
function directory_create_menu()
{
    add_menu_page('Member Directory', 'Directory', 'manage_options', 'directory', 'directory_display_settings', 'dashicons-groups', 20);
}
add_action('admin_menu', 'directory_create_menu');

// "Settings" link on the Plugins screen
function glueup_plugin_action_links($links)
{
    array_unshift($links, '<a href="' . esc_url(admin_url('admin.php?page=directory')) . '">Settings</a>');
    return $links;
}
add_filter('plugin_action_links_' . plugin_basename(__FILE__), 'glueup_plugin_action_links');

// Refresh now: clear the cache and fetch the full directory immediately
function glueup_handle_refresh()
{
    if (!current_user_can('manage_options')) {
        wp_die('You do not have permission to do this.');
    }
    check_admin_referer('glueup_refresh');

    delete_transient('api_data');
    fetch_and_cache_data();

    $status = get_option('glueup_last_fetch');
    $notice = (is_array($status) && empty($status['error'])) ? 'refreshed' : 'refresh_failed';
    wp_safe_redirect(add_query_arg('glueup_notice', $notice, admin_url('admin.php?page=directory')));
    exit;
}
add_action('admin_post_glueup_refresh', 'glueup_handle_refresh');

// Clear cache: the next visitor to the directory page triggers a fresh fetch
function glueup_handle_clear()
{
    if (!current_user_can('manage_options')) {
        wp_die('You do not have permission to do this.');
    }
    check_admin_referer('glueup_clear');

    clear_transient_data();

    wp_safe_redirect(add_query_arg('glueup_notice', 'cleared', admin_url('admin.php?page=directory')));
    exit;
}
add_action('admin_post_glueup_clear', 'glueup_handle_clear');

// Seconds until the cached directory expires, or null if unknown/not cached
function glueup_cache_expires_in()
{
    if (false === get_transient('api_data') || wp_using_ext_object_cache()) {
        return null;
    }
    $timeout = (int) get_option('_transient_timeout_api_data');
    return $timeout > time() ? $timeout - time() : null;
}

function directory_display_settings()
{
    if (!current_user_can('manage_options')) {
        return;
    }

    $has_keys = get_option('directory_public_key') && get_option('directory_private_key');
    $cached   = get_transient('api_data');
    $status   = get_option('glueup_last_fetch');
    $expires  = glueup_cache_expires_in();

    // Members fetched vs. members actually shown (the table hides expired ones)
    $fetched = null;
    $shown   = null;
    if (is_array($cached) && isset($cached['value']) && is_array($cached['value'])) {
        $fetched = count($cached['value']);
        $shown   = 0;
        foreach ($cached['value'] as $item) {
            if (isset($item['endDate']) && ($item['endDate'] / 1000) >= time()) {
                $shown++;
            }
        }
    }

    $notices = array(
        'refreshed'      => array('success', 'Directory refreshed from Glue Up.'),
        'refresh_failed' => array('error', 'The refresh did not complete. See the status below for details.'),
        'cleared'        => array('success', 'Cache cleared. The directory will be fetched again on its next page view.'),
    );
    $notice_key = isset($_GET['glueup_notice']) ? sanitize_key(wp_unslash($_GET['glueup_notice'])) : '';
?>
    <div class="wrap glueup-admin">
        <h1>Member Directory</h1>

        <?php if (isset($notices[$notice_key])) : ?>
            <div class="notice notice-<?php echo esc_attr($notices[$notice_key][0]); ?> is-dismissible"><p><?php echo esc_html($notices[$notice_key][1]); ?></p></div>
        <?php endif; ?>

        <?php settings_errors('directory'); ?>

        <style>
            .glueup-admin .glueup-card { background: #fff; border: 1px solid #c3c4c7; border-radius: 4px; padding: 4px 20px 16px; margin: 20px 0; max-width: 880px; }
            .glueup-admin .glueup-card h2 { margin-top: 16px; }
            .glueup-admin .glueup-status th { width: 200px; }
            .glueup-admin .glueup-badge { display: inline-block; padding: 2px 10px; border-radius: 10px; font-weight: 600; font-size: 12px; }
            .glueup-admin .glueup-ok { background: #edfaef; color: #00700f; }
            .glueup-admin .glueup-warn { background: #fcf9e8; color: #8a6100; }
            .glueup-admin .glueup-err { background: #fcf0f1; color: #b32d2e; }
            .glueup-admin .glueup-actions { display: flex; gap: 8px; flex-wrap: wrap; margin-top: 12px; }
            .glueup-admin .glueup-actions form { margin: 0; }
            .glueup-admin .glueup-shortcode { display: flex; gap: 8px; align-items: center; max-width: 480px; }
            .glueup-admin .glueup-shortcode input { flex: 1; }
        </style>

        <!-- Status -->
        <div class="glueup-card">
            <h2>Status</h2>
            <table class="form-table glueup-status" role="presentation">
                <tr>
                    <th scope="row">Connection</th>
                    <td>
                        <?php if (!$has_keys) : ?>
                            <span class="glueup-badge glueup-warn">Not configured</span> Add your API keys below.
                        <?php elseif (is_array($status) && !empty($status['error']) && empty($status['count'])) : ?>
                            <span class="glueup-badge glueup-err">Error</span>
                        <?php elseif (is_array($status) && !empty($status['error'])) : ?>
                            <span class="glueup-badge glueup-warn">Partial</span> The last fetch stopped early.
                        <?php elseif (is_array($status)) : ?>
                            <span class="glueup-badge glueup-ok">Connected</span>
                        <?php else : ?>
                            <span class="glueup-badge glueup-warn">Not fetched yet</span> Click <strong>Refresh now</strong> to test the connection.
                        <?php endif; ?>
                    </td>
                </tr>
                <?php if (is_array($status) && !empty($status['error'])) : ?>
                    <tr>
                        <th scope="row">Last error</th>
                        <td><code><?php echo esc_html($status['error']); ?></code></td>
                    </tr>
                <?php endif; ?>
                <tr>
                    <th scope="row">Members fetched</th>
                    <td>
                        <?php
                        if ($fetched !== null) {
                            echo esc_html(number_format_i18n($fetched));
                            if (is_array($status) && isset($status['total']) && $status['total'] !== null && (int) $status['total'] !== $fetched) {
                                echo ' <span class="description">(Glue Up reports ' . esc_html(number_format_i18n($status['total'])) . ')</span>';
                            }
                        } else {
                            echo '<span class="description">Nothing cached</span>';
                        }
                        ?>
                    </td>
                </tr>
                <tr>
                    <th scope="row">Shown in directory</th>
                    <td>
                        <?php if ($shown !== null) : ?>
                            <?php echo esc_html(number_format_i18n($shown)); ?>
                            <span class="description">(members whose membership end date has passed are hidden)</span>
                        <?php else : ?>
                            <span class="description">&mdash;</span>
                        <?php endif; ?>
                    </td>
                </tr>
                <tr>
                    <th scope="row">Last refreshed</th>
                    <td>
                        <?php
                        if (is_array($status) && !empty($status['time'])) {
                            echo esc_html(sprintf('%s ago (%s)', human_time_diff($status['time']), wp_date(get_option('date_format') . ' ' . get_option('time_format'), $status['time'])));
                        } else {
                            echo '<span class="description">Never</span>';
                        }
                        ?>
                    </td>
                </tr>
                <tr>
                    <th scope="row">Cache expires</th>
                    <td>
                        <?php
                        if ($expires !== null) {
                            echo esc_html('in ' . human_time_diff(time(), time() + $expires));
                        } else {
                            echo '<span class="description">&mdash;</span>';
                        }
                        ?>
                    </td>
                </tr>
            </table>

            <div class="glueup-actions">
                <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                    <input type="hidden" name="action" value="glueup_refresh">
                    <?php wp_nonce_field('glueup_refresh'); ?>
                    <button type="submit" class="button button-primary" <?php disabled(!$has_keys); ?>>Refresh now</button>
                </form>
                <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                    <input type="hidden" name="action" value="glueup_clear">
                    <?php wp_nonce_field('glueup_clear'); ?>
                    <button type="submit" class="button">Clear cache</button>
                </form>
            </div>
            <p class="description">The directory is cached for 4 hours. <strong>Refresh now</strong> fetches it from Glue Up straight away. <strong>Clear cache</strong> waits for the next page view.</p>
        </div>

        <!-- API keys -->
        <div class="glueup-card">
            <form method="post" action="options.php">
                <?php
                settings_fields('directory');
                do_settings_sections('directory');
                submit_button('Save keys');
                ?>
            </form>
        </div>

        <!-- Shortcode -->
        <div class="glueup-card">
            <h2>Shortcode</h2>
            <p>Paste this shortcode into any page or post to show the member directory:</p>
            <div class="glueup-shortcode">
                <input id="glueup-shortcode" type="text" class="code" readonly value="[data_table]" onfocus="this.select();">
                <button type="button" id="glueup-copy" class="button">Copy</button>
                <span id="glueup-copied" class="description" style="display:none; color:#00700f;" aria-live="polite">Copied!</span>
            </div>
        </div>
    </div>

    <script>
        (function() {
            var btn = document.getElementById('glueup-copy');
            var input = document.getElementById('glueup-shortcode');
            var msg = document.getElementById('glueup-copied');
            if (!btn || !input) return;

            function done() {
                msg.style.display = 'inline';
                setTimeout(function() { msg.style.display = 'none'; }, 2000);
            }

            btn.addEventListener('click', function() {
                if (navigator.clipboard && window.isSecureContext) {
                    navigator.clipboard.writeText(input.value).then(done);
                } else {
                    input.select();
                    document.execCommand('copy');
                    done();
                }
            });
        })();
    </script>
<?php
}

function fetch_and_cache_data()
{
    $transient_key = 'api_data';

    // Try to get cached data first
    $cached = get_transient($transient_key);

    // If we have valid cached data, return it immediately
    if ($cached && isset($cached['value']) && is_array($cached['value'])) {
        return $cached;
    }

    // If we're here, we need to fetch fresh data

    // Get the keys
    $privateKey = get_option('directory_private_key');
    $publicKey = get_option('directory_public_key');

    // Ensure we have the required keys
    if (empty($privateKey) || empty($publicKey)) {
        glueup_record_fetch_status(0, null, 'API keys are not set.');
        return array('value' => array()); // Return empty data structure instead of false
    }

    $requestMethod = 'POST';
    $version = '1.0';

    // Clear any existing transient before fetching new data
    delete_transient($transient_key);

    // -----------------------------------------------------------------
    // Pagination
    // -----------------------------------------------------------------
    // The Glueup endpoint returns one page of records per request
    // (previously capping the directory at 100 entries). Per the API docs
    // (https://glueupapi.docs.apiary.io) paging uses "offset" + "limit" in
    // the body, and the response carries metadata.pagination.total.
    // We loop through pages until every record has been collected.
    //
    // Safety: records are de-duplicated by id, and the loop also stops as
    // soon as a page returns no *new* records, so it can never spin forever.
    // -----------------------------------------------------------------
    $pageSize   = 100;   // records requested per page
    $offset     = 0;     // offset into the result set
    $total      = null;  // total records reported by the API
    $maxPages   = 200;   // hard safety cap (200 * 100 = 20,000 records)
    $allRecords = array();
    $seen       = array();
    $error      = '';    // set if a page request fails

    for ($page = 0; $page < $maxPages; $page++) {

        // A fresh signature/timestamp is required for every request
        $time   = round(microtime(true) * 1000);
        $digest = hash_hmac('sha256', $requestMethod . $publicKey . $version . $time, $privateKey);
        $ha     = "v=1.0;k=" . $publicKey . ";ts=" . $time . ";d=" . $digest;

        $bdreq = json_encode(array(
            'filter'     => array(),
            'projection' => array(),
            'order'      => array('name' => 'asc'), // stable ordering across pages
            'offset'     => $offset,
            'limit'      => $pageSize,
        ));

        $response = wp_remote_post('https://api.glueup.com/v2/membershipDirectory/corporateMemberships', array(
            'headers'           => array(
                'a'             => $ha,
                'method'        => $requestMethod,
                'Content-Type'  => 'application/json',
            ),
            'body'    => $bdreq,
            'timeout' => 30, // 30 second timeout per request
        ));

        // On a transport error, keep whatever we have gathered so far
        if (is_wp_error($response)) {
            $error = 'Request failed: ' . $response->get_error_message();
            break;
        }

        $code    = (int) wp_remote_retrieve_response_code($response);
        $body    = wp_remote_retrieve_body($response);
        $decoded = empty($body) ? null : json_decode($body, true);

        // Surface any error messages the API sends back
        if (is_array($decoded) && !empty($decoded['errors'])) {
            $error = 'Glue Up API error: ' . glueup_summarize_api_errors($decoded['errors']);
            break;
        }

        if ($code !== 200) {
            $error = 'Glue Up API returned HTTP ' . $code . '.';
            break;
        }

        // Validate the data structure
        if (!is_array($decoded) || !isset($decoded['value']) || !is_array($decoded['value'])) {
            $error = empty($body) ? 'Glue Up API returned an empty response.' : 'Glue Up API returned an unexpected response.';
            break;
        }

        // Remember the total the API reports (first page is enough)
        if ($total === null && isset($decoded['metadata']['pagination']['total'])) {
            $total = (int) $decoded['metadata']['pagination']['total'];
        }

        $batch = $decoded['value'];
        $count = count($batch);

        if ($count === 0) {
            break; // no more records
        }

        // Merge this page in, skipping records we've already collected
        $newInBatch = 0;
        foreach ($batch as $record) {
            $key = isset($record['id']) ? (string) $record['id'] : md5(json_encode($record));
            if (!isset($seen[$key])) {
                $seen[$key]   = true;
                $allRecords[] = $record;
                $newInBatch++;
            }
        }

        // Nothing new arrived (e.g. pagination not honored) -> stop
        if ($newInBatch === 0) {
            break;
        }

        $offset += $count;

        // Stop once we've reached the reported total...
        if ($total !== null && $offset >= $total) {
            break;
        }

        // ...or when a short page signals the end (if no total was given)
        if ($total === null && $count < $pageSize) {
            break;
        }
    }

    $data = array('value' => $allRecords);

    // Cache what we retrieved. A complete fetch is cached for 4 hours; if a
    // page failed part-way, cache the partial list briefly so the API is
    // retried soon without being hit on every page view.
    if (!empty($allRecords)) {
        set_transient($transient_key, $data, $error === '' ? 4 * HOUR_IN_SECONDS : 5 * MINUTE_IN_SECONDS);
    }

    glueup_record_fetch_status(count($allRecords), $total, $error);

    return $data;
}

// Remember the outcome of the last fetch for the admin status panel
function glueup_record_fetch_status($count, $total, $error)
{
    update_option('glueup_last_fetch', array(
        'time'  => time(),
        'count' => (int) $count,
        'total' => $total,
        'error' => (string) $error,
    ), false);
}

// Turn the API's "errors" array into a short readable message
function glueup_summarize_api_errors($errors)
{
    $messages = array();
    foreach ((array) $errors as $err) {
        if (is_array($err)) {
            foreach (array('message', 'description', 'code') as $field) {
                if (!empty($err[$field]) && is_scalar($err[$field])) {
                    $messages[] = (string) $err[$field];
                    break;
                }
            }
        } elseif (is_scalar($err)) {
            $messages[] = (string) $err;
        }
    }
    $summary = $messages ? implode('; ', $messages) : wp_json_encode($errors);
    return substr($summary, 0, 300);
}

function clear_transient_data()
{
    delete_transient('api_data');
}


function display_data_table()
{
    // Get the data
    $mem = fetch_and_cache_data();

    // Ensure we have valid data
    if (!is_array($mem) || !isset($mem['value']) || !is_array($mem['value']) || empty($mem['value'])) {
        return '<div class="notice notice-warning"><p>No data available. Please check your API keys and try again.</p></div>';
    }
    $countryNames = array(
        'AF' => 'Afghanistan',
        'AX' => 'Aland Islands',
        'AL' => 'Albania',
        'DZ' => 'Algeria',
        'AS' => 'American Samoa',
        'AD' => 'Andorra',
        'AO' => 'Angola',
        'AI' => 'Anguilla',
        'AQ' => 'Antarctica',
        'AG' => 'Antigua And Barbuda',
        'AR' => 'Argentina',
        'AM' => 'Armenia',
        'AW' => 'Aruba',
        'AU' => 'Australia',
        'AT' => 'Austria',
        'AZ' => 'Azerbaijan',
        'BS' => 'Bahamas',
        'BH' => 'Bahrain',
        'BD' => 'Bangladesh',
        'BB' => 'Barbados',
        'BY' => 'Belarus',
        'BE' => 'Belgium',
        'BZ' => 'Belize',
        'BJ' => 'Benin',
        'BM' => 'Bermuda',
        'BT' => 'Bhutan',
        'BO' => 'Bolivia',
        'BA' => 'Bosnia And Herzegovina',
        'BW' => 'Botswana',
        'BV' => 'Bouvet Island',
        'BR' => 'Brazil',
        'IO' => 'British Indian Ocean Territory',
        'BN' => 'Brunei Darussalam',
        'BG' => 'Bulgaria',
        'BF' => 'Burkina Faso',
        'BI' => 'Burundi',
        'KH' => 'Cambodia',
        'CM' => 'Cameroon',
        'CA' => 'Canada',
        'CV' => 'Cape Verde',
        'KY' => 'Cayman Islands',
        'CF' => 'Central African Republic',
        'TD' => 'Chad',
        'CL' => 'Chile',
        'CN' => 'China',
        'CX' => 'Christmas Island',
        'CC' => 'Cocos (Keeling) Islands',
        'CO' => 'Colombia',
        'KM' => 'Comoros',
        'CG' => 'Congo',
        'CD' => 'Congo, Democratic Republic',
        'CK' => 'Cook Islands',
        'CR' => 'Costa Rica',
        'CI' => 'Cote D\'Ivoire',
        'HR' => 'Croatia',
        'CU' => 'Cuba',
        'CY' => 'Cyprus',
        'CZ' => 'Czech Republic',
        'DK' => 'Denmark',
        'DJ' => 'Djibouti',
        'DM' => 'Dominica',
        'DO' => 'Dominican Republic',
        'EC' => 'Ecuador',
        'EG' => 'Egypt',
        'SV' => 'El Salvador',
        'GQ' => 'Equatorial Guinea',
        'ER' => 'Eritrea',
        'EE' => 'Estonia',
        'ET' => 'Ethiopia',
        'FK' => 'Falkland Islands (Malvinas)',
        'FO' => 'Faroe Islands',
        'FJ' => 'Fiji',
        'FI' => 'Finland',
        'FR' => 'France',
        'GF' => 'French Guiana',
        'PF' => 'French Polynesia',
        'TF' => 'French Southern Territories',
        'GA' => 'Gabon',
        'GM' => 'Gambia',
        'GE' => 'Georgia',
        'DE' => 'Germany',
        'GH' => 'Ghana',
        'GI' => 'Gibraltar',
        'GR' => 'Greece',
        'GL' => 'Greenland',
        'GD' => 'Grenada',
        'GP' => 'Guadeloupe',
        'GU' => 'Guam',
        'GT' => 'Guatemala',
        'GG' => 'Guernsey',
        'GN' => 'Guinea',
        'GW' => 'Guinea-Bissau',
        'GY' => 'Guyana',
        'HT' => 'Haiti',
        'HM' => 'Heard Island & Mcdonald Islands',
        'VA' => 'Holy See (Vatican City State)',
        'HN' => 'Honduras',
        'HK' => 'Hong Kong',
        'HU' => 'Hungary',
        'IS' => 'Iceland',
        'IN' => 'India',
        'ID' => 'Indonesia',
        'IR' => 'Iran, Islamic Republic Of',
        'IQ' => 'Iraq',
        'IE' => 'Ireland',
        'IM' => 'Isle Of Man',
        'IL' => 'Israel',
        'IT' => 'Italy',
        'JM' => 'Jamaica',
        'JP' => 'Japan',
        'JE' => 'Jersey',
        'JO' => 'Jordan',
        'KZ' => 'Kazakhstan',
        'KE' => 'Kenya',
        'KI' => 'Kiribati',
        'KR' => 'Korea',
        'KW' => 'Kuwait',
        'KG' => 'Kyrgyzstan',
        'LA' => 'Lao People\'s Democratic Republic',
        'LV' => 'Latvia',
        'LB' => 'Lebanon',
        'LS' => 'Lesotho',
        'LR' => 'Liberia',
        'LY' => 'Libyan Arab Jamahiriya',
        'LI' => 'Liechtenstein',
        'LT' => 'Lithuania',
        'LU' => 'Luxembourg',
        'MO' => 'Macao',
        'MK' => 'Macedonia',
        'MG' => 'Madagascar',
        'MW' => 'Malawi',
        'MY' => 'Malaysia',
        'MV' => 'Maldives',
        'ML' => 'Mali',
        'MT' => 'Malta',
        'MH' => 'Marshall Islands',
        'MQ' => 'Martinique',
        'MR' => 'Mauritania',
        'MU' => 'Mauritius',
        'YT' => 'Mayotte',
        'MX' => 'Mexico',
        'FM' => 'Micronesia, Federated States Of',
        'MD' => 'Moldova',
        'MC' => 'Monaco',
        'MN' => 'Mongolia',
        'ME' => 'Montenegro',
        'MS' => 'Montserrat',
        'MA' => 'Morocco',
        'MZ' => 'Mozambique',
        'MM' => 'Myanmar',
        'NA' => 'Namibia',
        'NR' => 'Nauru',
        'NP' => 'Nepal',
        'NL' => 'Netherlands',
        'AN' => 'Netherlands Antilles',
        'NC' => 'New Caledonia',
        'NZ' => 'New Zealand',
        'NI' => 'Nicaragua',
        'NE' => 'Niger',
        'NG' => 'Nigeria',
        'NU' => 'Niue',
        'NF' => 'Norfolk Island',
        'MP' => 'Northern Mariana Islands',
        'NO' => 'Norway',
        'OM' => 'Oman',
        'PK' => 'Pakistan',
        'PW' => 'Palau',
        'PS' => 'Palestinian Territory, Occupied',
        'PA' => 'Panama',
        'PG' => 'Papua New Guinea',
        'PY' => 'Paraguay',
        'PE' => 'Peru',
        'PH' => 'Philippines',
        'PN' => 'Pitcairn',
        'PL' => 'Poland',
        'PT' => 'Portugal',
        'PR' => 'Puerto Rico',
        'QA' => 'Qatar',
        'RE' => 'Reunion',
        'RO' => 'Romania',
        'RU' => 'Russian Federation',
        'RW' => 'Rwanda',
        'BL' => 'Saint Barthelemy',
        'SH' => 'Saint Helena',
        'KN' => 'Saint Kitts And Nevis',
        'LC' => 'Saint Lucia',
        'MF' => 'Saint Martin',
        'PM' => 'Saint Pierre And Miquelon',
        'VC' => 'Saint Vincent And Grenadines',
        'WS' => 'Samoa',
        'SM' => 'San Marino',
        'ST' => 'Sao Tome And Principe',
        'SA' => 'Saudi Arabia',
        'SN' => 'Senegal',
        'RS' => 'Serbia',
        'SC' => 'Seychelles',
        'SL' => 'Sierra Leone',
        'SG' => 'Singapore',
        'SK' => 'Slovakia',
        'SI' => 'Slovenia',
        'SB' => 'Solomon Islands',
        'SO' => 'Somalia',
        'ZA' => 'South Africa',
        'GS' => 'South Georgia And Sandwich Isl.',
        'ES' => 'Spain',
        'LK' => 'Sri Lanka',
        'SD' => 'Sudan',
        'SR' => 'Suriname',
        'SJ' => 'Svalbard And Jan Mayen',
        'SZ' => 'Swaziland',
        'SE' => 'Sweden',
        'CH' => 'Switzerland',
        'SY' => 'Syrian Arab Republic',
        'TW' => 'Taiwan',
        'TJ' => 'Tajikistan',
        'TZ' => 'Tanzania',
        'TH' => 'Thailand',
        'TL' => 'Timor-Leste',
        'TG' => 'Togo',
        'TK' => 'Tokelau',
        'TO' => 'Tonga',
        'TT' => 'Trinidad And Tobago',
        'TN' => 'Tunisia',
        'TR' => 'Turkey',
        'TM' => 'Turkmenistan',
        'TC' => 'Turks And Caicos Islands',
        'TV' => 'Tuvalu',
        'UG' => 'Uganda',
        'UA' => 'Ukraine',
        'AE' => 'United Arab Emirates',
        'GB' => 'United Kingdom',
        'US' => 'United States',
        'UM' => 'United States Outlying Islands',
        'UY' => 'Uruguay',
        'UZ' => 'Uzbekistan',
        'VU' => 'Vanuatu',
        'VE' => 'Venezuela',
        'VN' => 'Viet Nam',
        'VG' => 'Virgin Islands, British',
        'VI' => 'Virgin Islands, U.S.',
        'WF' => 'Wallis And Futuna',
        'EH' => 'Western Sahara',
        'YE' => 'Yemen',
        'ZM' => 'Zambia',
        'ZW' => 'Zimbabwe',
    );
    // Start output buffering
    ob_start();

?>
    <table id="data-table" class="display compact" style="width:100%;">
        <thead>
            <tr>
                <th>Name</th>
                <th>Type</th>
                <th>Date joined</th>
                <th class="none">Phone</th>
                <th>City</th>
                <th class="none">Address</th>
                <th class="none">Website</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($mem['value'] as $item) : ?>
                <?php
                // Skip entries where endDate is in the past
                $endDate = $item['endDate'] / 1000; // Convert milliseconds to seconds
                if ($endDate < time()) {
                    continue; // Skip this entry
                }
                ?>
                <tr>
                    <!-- column 1     -->
                    <td><?php echo esc_html($item['name']); ?></td>
                    <!-- column 2     -->
                    <td><?php echo esc_html($item['membershipType']['title']); ?></td>
                    <!-- column 3 -->
                    <?php

                    $da = $item['startDate'] / 1000;
                    if ($da > 0) {
                        $da = gmdate('Y-m-d', $da);
                    } else $da = 'Needs Update';
                    ?>
                    <td><?php echo esc_html($da); ?></td>
                    <!-- column 4 -->
                    <?php
                    if (isset($item['phone']['value'])) {
                        $ph  = $item['phone']['value'];
                    } else $ph = ""; ?>
                    <td><?php echo esc_html($ph); ?></td>
                    <!-- column 5 -->
                    <td> <?php

                            /*if (isset($item['properties']['asata-region']['title']['en'])) {
                                echo esc_html($item['properties']['asata-region']['title']['en']);
                            }*/

                            //Change to display city

                            if (isset($item['address']['cityName'])) {
                                echo esc_html(strtolower($item['address']['cityName']));
                            }
                            else {
                                echo ('');
                            } ?> </td>
                    <!-- column 6 -->
                    <td> <?php
                            $parts = array();
                            if (isset($item['address']['streetAddress'])) {
                                $parts[] = $item['address']['streetAddress'];
                            }
                            if (isset($item['address']['cityName'])) {
                                $parts[] = $item['address']['cityName'];
                            }
                            if (isset($item['address']['province'])) {
                                $parts[] = $item['address']['province'];
                            }
                            if (isset($item['address']['zipCode'])) {
                                $parts[] = $item['address']['zipCode'];
                            }
                            if (isset($item['address']['country']['code'])) {
                                $countryCode = $item['address']['country']['code'];
                                $countryName = isset($countryNames[$countryCode]) ? $countryNames[$countryCode] : $countryCode;
                                $parts[] = $countryName;
                            }

                            $addie = implode(", ", $parts);

                            echo (!empty($addie)) ? esc_html(ucwords(strtolower($addie))) : '';  ?> </td>
                    <!-- column 7 -->
                    <td>
                        <?php if (isset($item['companyWebsiteAddress']) && !empty($item['companyWebsiteAddress'])) : ?>
                            <a href="<?php echo esc_url($item['companyWebsiteAddress']); ?>" target="_blank">
                                <?php
                                // Remove 'http://' and 'https://' for display
                                $displayAddress = str_replace(["http://", "https://"], "", $item['companyWebsiteAddress']);
                                echo esc_html($displayAddress);
                                ?>
                            </a>
                        <?php else : ?>
                            Not entered
                        <?php endif; ?>
                    </td>

                </tr>
            <?php endforeach; ?>
        <tfoot>
            <tr>
                <th>Name</th>
                <th>Type</th>
                <th>Date joined</th>
                <th class="none">Phone</th>
                <th>City</th>
                <th class="none">Address</th>
                <th class="none">Website</th>
            </tr>
        </tfoot>
        </tbody>
    </table>
    <script>
        jQuery(document).ready(function($) {
            $('#data-table').DataTable({
                "pageLength": 25,
                "lengthMenu": [
                    [10, 25, 50, -1],
                    [10, 25, 50, "All"]
                ],
                "responsive": true
            });
        });
    </script>


<?php

    // End output buffering and return the buffered content
    return ob_get_clean();
}

// Create a shortcode to display the data table
add_shortcode('data_table', 'display_data_table');
