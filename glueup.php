<?php

/**
 * Plugin Name:     Glueup
 * Plugin URI:      https://bigambitions.co.za
 * Description:     Plugin to display the members directory
 * Author:          Steph Reinstein
 * Author URI:      https://bigambitions.co.za
 * Text Domain:     glueup
 * Domain Path:     /languages
 * Version:         0.1.2
 *
 * @package         Glueup
 */

if (isset($_POST['clear_transient']) && $_POST['clear_transient'] == 1) {
    clear_transient_data();
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


// Register settings and fields
function directory_register_settings()
{
    register_setting('directory', 'directory_private_key');
    register_setting('directory', 'directory_public_key');

    add_settings_section('directory_keys', 'Keys', null, 'directory');

    add_settings_field('directory_private_key', 'Private Key', 'directory_private_key_callback', 'directory', 'directory_keys');
    add_settings_field('directory_public_key', 'Public Key', 'directory_public_key_callback', 'directory', 'directory_keys');
}
add_action('admin_init', 'directory_register_settings');

// Callbacks for the fields
function directory_private_key_callback()
{
    $private_key = get_option('directory_private_key');
    echo '<input type="password" name="directory_private_key" value="' . esc_attr($private_key) . '">';
}

function directory_public_key_callback()
{
    $public_key = get_option('directory_public_key');
    echo '<input type="text" name="directory_public_key" value="' . esc_attr($public_key) . '">';
}

// Create the menu page
// Create the menu page
function directory_create_menu()
{
    add_menu_page('Directory', 'Directory', 'manage_options', 'directory', 'directory_display_settings', 'dashicons-admin-network', 20);
}
add_action('admin_menu', 'directory_create_menu');

function directory_display_settings()
{
    ob_start();
?>
    <div class="wrap">
        <h1>Directory</h1>
        <form method="post" action="options.php">
            <?php
            settings_fields('directory');
            do_settings_sections('directory');
            submit_button();
            ?>
        </form>
    </div>

    <h2>Clear Transients</h2>
    <p>Click the button below to clear the transient data stored:</p>
    <form method="post" action="">
        <input type="hidden" name="clear_transient" value="1">
        <p><button type="submit" class="button danger">Clear Transient</button></p>
    </form>

    <!-- Shortcode Display and Copy to Clipboard -->
    <h2>Shortcode</h2>
    <p>Use this shortcode to display the data table on your page:</p>
    <input id="shortcode" readonly="readonly" value="[data_table]" style="width: 100%;">
    <button id="copyShortcodeBtn" class="button">Copy to clipboard</button>
    <span id="copySuccessMsg" style="display: none; color: green; margin-left: 10px;">Shortcode copied to clipboard!</span>

    <script>
        document.querySelector("#copyShortcodeBtn").addEventListener("click", function() {
            var copyText = document.querySelector("#shortcode");
            var copySuccessMsg = document.querySelector("#copySuccessMsg");
            copyText.select();
            document.execCommand("copy");

            // Show success message
            copySuccessMsg.style.display = 'inline';

            // Hide the success message after 2 seconds
            setTimeout(function() {
                copySuccessMsg.style.display = 'none';
            }, 2000);
        });
    </script>


<?php
    echo ob_get_clean();
}



function fetch_and_cache_data()
{
    $transient_key = 'api_data';

    // Check if the data is cached
    $data = get_transient($transient_key);

    // get the key
    // Get the keys
    $private_key = get_option('directory_private_key');
    $public_key = get_option('directory_public_key');
    $requestMethod = 'POST';
    $version = '1.0';
    $time = round(microtime(true) * 1000);
    $digest = hash_hmac('sha256', $requestMethod . $publicKey . $version . $time, $privateKey);
    $ts = $time;
    $d = $digest;
    $ha = "v=1.0;k=" . $publicKey . ";ts=" . $ts . ";d=" . $d;
    $bdreq = json_encode(array(
        'filter' => array(),
        'projection' => array(),
    ));

    // Add ts and d to your HTTP request headers

    // If the data is not cached, fetch it and cache it
    if (false === $data) {
        $response = wp_remote_post('https://api.glueup.com/v2/membershipDirectory/corporateMemberships', array(
            'headers'           => array(
                'a'             => $ha,
                'method'        => $requestMethod,
                'Content-Type'  => 'application/json',
            ),
            'body' => $bdreq,
        ));

        if (is_wp_error($response)) {
            return false;
        }
        $data = json_decode(wp_remote_retrieve_body($response), true);

        // Store the data in the cache for 4 hours
        set_transient($transient_key, $data, 4 * HOUR_IN_SECONDS);
    }

    return $data;
}
function clear_transient_data()
{
    delete_transient('glueup_members_data');
    echo '<div class="notice notice-success"><p>Transients cleared successfully!</p></div>';
}


function display_data_table()
{
    $mem = fetch_and_cache_data();
    if (!$mem) {
        return 'Failed to fetch data.';
    }

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
                <th>ASATA Region</th>
                <th class="none">Address</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($mem['value'] as $item) : ?>
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
                    <td> <?php if (isset($item['properties']['asata-region']['title']['en'])) {
                                echo esc_html($item['properties']['asata-region']['title']['en']);
                            } else {
                                echo ('');
                            } ?> </td>
                    <!-- column 6 -->
                    <td> <?php
                            $addie = '';
                            if (isset($item['address']['streetAddress'])) {
                                $addie = $addie . $item['address']['streetAddress'] . ", ";
                            }
                            if (isset($item['address']['cityName'])) {
                                $addie = $addie . $item['address']['cityName'] . ", ";
                            }
                            if (isset($item['address']['province'])) {
                                $addie = $addie . $item['address']['province'] . ", ";
                            }
                            if (isset($item['address']['zipCode'])) {
                                $addie = $addie . $item['address']['zipCode'] . ", ";
                            }
                            if (isset($item['address']['country'])) {
                                $addie = $addie . $item['address']['country']['code'];
                            }
                            if ($addie != NULL) {
                                echo esc_html(ucwords(strtolower($addie)));
                            } else {
                                echo ('');
                            }  ?> </td>
                </tr>
            <?php endforeach; ?>
        <tfoot>
            <tr>
                <th>Name</th>
                <th>Type</th>
                <th>Date joined</th>
                <th class="none">Phone</th>
                <th>ASATA Region</th>
                <th class="none">Address</th>
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
