<?php

/**
 * Plugin Name:     Glueup
 * Plugin URI:      https://bigambitions.co.za
 * Description:     Plugin to display the members directory
 * Author:          Steph Reinstein
 * Author URI:      https://bigambitions.co.za
 * Text Domain:     glueup
 * Domain Path:     /languages
 * Version:         0.1.4
 * GitHub Plugin URI: divemasterza/glueup-directory-wp
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

    // Get the keys
    $privateKey = get_option('directory_private_key');
    $publicKey = get_option('directory_public_key');
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
    delete_transient('api_data');
    echo '<div class="notice notice-success"><p>Transients cleared successfully!</p></div>';
}


function display_data_table()
{
    $mem = fetch_and_cache_data();
    if (!$mem) {
        return 'Failed to fetch data.';
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
                <th>ASATA Region</th>
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
                    <td> <?php if (isset($item['properties']['asata-region']['title']['en'])) {
                                echo esc_html($item['properties']['asata-region']['title']['en']);
                            } else {
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
                <th>ASATA Region</th>
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
