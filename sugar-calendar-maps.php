<?php
/**
 * Plugin Name:       Sugar Calendar - Google Maps
 * Plugin URI:        https://sugarcalendar.com/downloads/google-maps/
 * Description:       Adds Google Maps to events in Sugar Calendar
 * Author:            Sandhills Development, LLC
 * Author URI:        https://sandhillsdev.com
 * Text Domain:       sugar-event-calendar-google-maps
 * Requires PHP:      5.6.20
 * Requires at least: 5.2
 * Version:           1.4.1
 */

// Exit if accessed directly
defined( 'ABSPATH' ) || exit;

/**
 * Initialize the plugin
 *
 * @since 1.4.0
 */
function sc_maps_init() {

	// Load the text domain
	load_plugin_textdomain( 'sugar-event-calendar-google-maps' );

	// Save
	add_action( 'save_post', 'sc_maps_meta_box_save' );

	// Output after content
	add_action( 'sc_after_event_content', 'sc_maps_show_map' );

	// Scripts
	add_action( 'init',               'sc_maps_register_scripts', 11 );
	add_action( 'wp_enqueue_scripts', 'sc_maps_enqueue_scripts' );
	add_action( 'wp_head',            'sc_maps_map_css' );

	// Settings
	add_action( 'sugar_calendar_register_settings',    'sc_maps_regsiter_api_key_setting' );
	add_filter( 'sugar_calendar_settings_sections',    'sc_maps_register_maps_section'    );
	add_filter( 'sugar_calendar_settings_subsections', 'sc_maps_register_maps_subsection' );

	// Legacy support
	if ( ! sc_maps_is_20() ) {
		add_action( 'sc_event_meta_box_after', 'sc_maps_add_forms_meta_box' );
	}
}
add_action( 'init', 'sc_maps_init' );

/**
 * Register scripts.
 *
 * @since 1.4.1
 */
function sc_maps_register_scripts() {

	// Get the API key
	$key = sc_maps_get_api_key();

	// Only enqueue if key is valid
	if ( ! empty( $key ) ) {
		wp_register_script(
			'sc-google-maps-api',
			'//maps.googleapis.com/maps/api/js?key=' . $key,
			array(),
			'20201021'
		);
	}
}

/**
 * Enqueue scripts on the relevant pages.
 *
 * @since 1.4.1
 */
function sc_maps_enqueue_scripts() {

	// Get post types and taxonomies
	$pts = sugar_calendar_allowed_post_types();
	$tax = sugar_calendar_get_object_taxonomies( $pts );

	// Return true if single event, event archive, or allowed taxonomy archive
	if ( is_singular( $pts ) || is_post_type_archive( $pts ) || is_tax( $tax ) ) {
		sc_maps_load_scripts();
	}
}

/**
 * Loads Google Maps JavaScript API
 *
 * @since 1.0.0
 */
function sc_maps_load_scripts() {

	// Check the API key
	$key = sc_maps_get_api_key();

	// Only enqueue if key is valid
	if ( ! empty( $key ) ) {
		wp_enqueue_script( 'sc-google-maps-api' );
	}
}

/**
 * Are we running Sugar Calendar 2.0?
 *
 * @since 1.3.0
 * @return bool
 */
function sc_maps_is_20() {

	$ret = false;

	if ( defined( 'SC_PLUGIN_VERSION' ) ) {
		$sc_version = preg_replace( '/[^0-9.].*/', '', SC_PLUGIN_VERSION );
		$ret        = version_compare( $sc_version, '2.0', '>=' );
	}

	return $ret;
}

/**
 * Retrieve event address
 *
 * @since 1.3.0
 * @return string
*/
function sc_maps_get_address( $event_id = 0 ) {

	if ( empty( $event_id ) ) {
		$event_id = get_the_ID();
	}

	if ( sc_maps_is_20() ) {
		$event   = sugar_calendar_get_event_by_object( $event_id );
		$address = $event->location;

	} else {
		$address = get_post_meta( $event_id, 'sc_map_address', true );
	}

	return $address;
}

/**
 * Show admin address field
 *
 * @since 1.0.0
 */
function sc_maps_add_forms_meta_box() {
	global $post;

	// 2.0 has a default address field so we do not need to register one
	if ( sc_maps_is_20() ) {
		return;
	} ?>

	<tr class="sc_meta_box_row">
		<td class="sc_meta_box_td" colspan="2" valign="top"><?php esc_html_e( 'Event Location', 'sugar-event-calendar-google-maps' ); ?></td>
		<td class="sc_meta_box_td" colspan="4">
			<input type="text" class="regular-text" name="sc_map_address" value="<?php echo esc_attr( sc_maps_get_address( $post->ID ) ); ?> "/>
			<span class="description"><?php esc_html_e( 'Enter the event address.', 'sugar-event-calendar-google-maps' ); ?></span>
			<br/>
			<input type="hidden" name="sc_maps_meta_box_nonce" value="<?php echo wp_create_nonce( basename( __FILE__ ) ); ?>" />
		</td>
	</tr>

	<?php
}

/**
 * Save Address field
 *
 * Save data from meta box.
 *
 * @since 1.0.0
 */
function sc_maps_meta_box_save( $event_id ) {
	global $post;

	// 2.0 has a default address field so we do not need to save one
	if ( sc_maps_is_20() ) {
		return;
	}

	// verify nonce
	if ( empty( $_POST['sc_maps_meta_box_nonce'] ) || ! wp_verify_nonce( $_POST['sc_maps_meta_box_nonce'], basename( __FILE__ ) ) ) {
		return $event_id;
	}

	// check autosave
	if ( ( defined('DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) || ( defined( 'DOING_AJAX' ) && DOING_AJAX) || isset( $_REQUEST['bulk_edit'] ) ) {
		 return $event_id;
	}

	//don't save if only a revision
	if ( isset( $post->post_type ) && $post->post_type == 'revision' ) {
		return $event_id;
	}

	// check permissions
	if ( ! current_user_can( 'edit_post', $event_id ) ) {
		return $event_id;
	}

	$address = sanitize_text_field( $_POST['sc_map_address'] );

	update_post_meta( $event_id, 'sc_map_address', $address );
}

/**
 * Displays the event map
 *
 * @since 1.0.0
 * @param int $event_id
 */
function sc_maps_show_map( $event_id = 0 ) {

	// Bail if no API key
	if ( ! sc_maps_get_api_key() ) {
		return;
	}

	// Get the address
	$address = sc_maps_get_address( $event_id );

	// Bail if no address
	if ( empty( $address ) ) {
		return;
	}

	// Get coordinates
	$coordinates = sc_maps_get_coordinates( $address );

	// Show warnings in the console
	if ( is_string( $coordinates ) ) : ?>

		<script type="text/javascript">
			console.warn( '[SCGM]: <?php echo esc_js( $coordinates ); ?>' );
		</script>

	<?php endif;

	// Bail if not an array
	if ( ! is_array( $coordinates ) ) {
		return;
	}

	// generate a unique ID for this map
	$map_id = uniqid( 'sc_map_' . $event_id );

	ob_start(); ?>

	<div class="sc_map_canvas" id="<?php echo esc_attr( $map_id ); ?>" style="width: 100%; height: 400px; margin-bottom: 1em; background-color: rgba(0,0,0,0.1)"></div>
	<script type="text/javascript">

		/**
		 * Unique function for this specific location
		 *
		 * @since 1.0.0
		 */
		function sc_run_map_<?php echo $map_id ; ?>() {

			// Define variables
			var element     = document.getElementById( '<?php echo $map_id ; ?>' ),

				// Latitude & Longitude
				location    = new google.maps.LatLng(
					'<?php echo $coordinates['lat']; ?>',
					'<?php echo $coordinates['lng']; ?>'
				),

				// Options
				map_options = {
					zoom:      15,
					center:    location,
					mapTypeId: google.maps.MapTypeId.ROADMAP
				},

				// Create Map with Options
				map_<?php echo $map_id ; ?> = new google.maps.Map( element, map_options ),
				marker      = {
					position: location,
					map:      map_<?php echo $map_id ; ?>
				};

			// Create marker
			new google.maps.Marker( marker );
		}

		// Call if Google API exists
		if ( typeof google !== 'undefined' ) {
			sc_run_map_<?php echo $map_id ; ?>();

		// Warn if Google API does not exist
		} else {
			console.warn( '[SCGM]: Check your Google API Key' );
		}
	</script>

	<?php

	echo ob_get_clean();
}

/**
 * Retrieve coordinates for an address
 *
 * Coordinates are cached using transients and a hash of the address
 *
 * @since 1.0.0
 */
function sc_maps_get_coordinates( $address, $force_refresh = false ) {

	// Create the transient hash
    $address_hash = 'scgm_' . md5( $address );

	// Check for this transient
    $coordinates = get_transient( $address_hash );

	// Not cached, or forcing a refresh
    if ( ! empty( $force_refresh ) || ( false === $coordinates ) ) {

		// Query arguments
    	$args = array(
			'address' => urlencode( $address ),
			'sensor'  => 'false',
			'key'     => sc_maps_get_api_key()
		);

		// URL
    	$url = add_query_arg( $args, 'https://maps.googleapis.com/maps/api/geocode/json' );

		// Send the remote request
     	$response = wp_remote_get( $url );

		// Bail if error
     	if ( is_wp_error( $response ) ) {
     		return $response->get_error_message();
		}

		// Get the body of the request
     	$data = wp_remote_retrieve_body( $response );

		// Bail if error
     	if ( is_wp_error( $data ) ) {
     		return $response->get_error_message();
     	}

		// OK
		if ( 200 === $response['response']['code'] ) {

			$data = json_decode( $data );

			// Valid
			if ( 'OK' === $data->status ) {

			  	$coordinates = $data->results[0]->geometry->location;

			  	$cache_value['lat'] 	= $coordinates->lat;
			  	$cache_value['lng'] 	= $coordinates->lng;
			  	$cache_value['address'] = (string) $data->results[0]->formatted_address;

			  	// cache coordinates for 1 month
			  	set_transient( $address_hash, $cache_value, MONTH_IN_SECONDS );

			  	$data = $cache_value;

			// Invalid
			} else {

				// Address not found
				if ( 'ZERO_RESULTS' === $data->status ) {
					return esc_html__( 'No location found for the entered address.', 'sugar-event-calendar-google-maps' );

				// Request invalid
				} elseif ( 'INVALID_REQUEST' === $data->status ) {
					return esc_html__( 'Invalid request. Did you enter an address?', 'sugar-event-calendar-google-maps' );

				// Request denied (billing, auth, etc...)
				} elseif ( 'REQUEST_DENIED' === $data->status ) {
					return esc_html__( 'Request Denied. ' . esc_js( $data->error_message ), 'sugar-event-calendar-google-maps' );

				// Unknown
				} else {
					return esc_html__( 'Something went wrong while retrieving your map.', 'sugar-event-calendar-google-maps' );
				}
			}

		// Not 200 OK
		} else {
		 	return esc_html__( 'Unable to contact Google API service.', 'sugar-event-calendar-google-maps' );
		}

	// Return results from transient
    } else {
       $data = $coordinates;
    }

    return $data;
}

/**
 * Fixes a problem with responsive themes
 *
 * @since 1.2.0
 */
function sc_maps_map_css() {
	echo '<style type="text/css">/* =Responsive Map fix
-------------------------------------------------------------- */
.sc_map_canvas img {
	max-width: none;
}</style>';
}

/**
 * Register the "Maps" section
 *
 * @param array $subsections
 *
 * @return string
 */
function sc_maps_register_maps_section( $subsections = array() ) {

	// Add "Maps" section
	$subsections['maps'] = array(
		'id'   => 'maps',
		'name' => esc_html__( 'Maps', 'sugar-event-calendar-google-maps' ),
		'url'  => admin_url( 'admin.php?page=sc-settings&section=maps' ),
		'func' => 'sc_maps_google_api_key_display'
	);

	// Return all sections
	return $subsections;
}

/**
 * Register the "Google" subsection
 *
 * @since 1.3.0
 *
 * @param array $subsections
 * @return array
 */
function sc_maps_register_maps_subsection( $subsections = array() ) {

	// Add "Google" subsection
	$subsections['maps']['google'] = array(
		'name' => esc_html__( 'Google', 'sugar-event-calendar-google-maps' ),
		'url'  => admin_url( 'admin.php?page=sc-settings&section=maps' ),
		'func' => 'sc_maps_google_api_key_display'
	);

	// Return all subsections
	return $subsections;
}

/**
 * Register the Google Maps API Key option/setting.
 *
 * @since 1.3.0
 */
function sc_maps_regsiter_api_key_setting() {
	register_setting( 'sc_maps_google', 'sc_maps_google_api_key', array(
		'sanitize_callback' => 'sc_maps_sanitize_google_api_key'
	) );
}

/**
 * Return the Google Maps API Key.
 *
 * @sinc 1.4.1
 * @return string
 */
function sc_maps_get_api_key() {
	return get_option( 'sc_maps_google_api_key', '' );
}

/**
 * Sanitize the Google Maps API Key.
 *
 * Also deletes all transients using this key.
 *
 * @since 1.4.1
 * @param string $value
 */
function sc_maps_sanitize_google_api_key( $value = '' ) {
	global $wpdb;

	// Sanitize the text field
	$value = sanitize_text_field( $value );

	// Query for transients
	$sql     = "SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE %s";
	$like    = $wpdb->esc_like( '_transient_scgm_' ) . '%';
	$prepare = $wpdb->prepare( $sql, $like );
	$query   = $wpdb->get_results( $prepare );

	// Delete all transients related to this key
	if ( ! empty( $query ) ) {
		foreach ( $query as $transient ) {
			delete_transient( $transient->option_name );
		}
	}

	// Return the sanitized value
	return $value;
}

/**
 * Output the table HTML for the settings page.
 *
 * Used by section & subsection callbacks.
 *
 * @since 1.3.0
 */
function sc_maps_google_api_key_display() {
	$api_key = sc_maps_get_api_key(); ?>

	<table class="form-table">
		<tbody>
			<tr valign="top">
				<th scope="row" valign="top">
					<label for="sc_maps_google_api_key"><?php esc_html_e( 'API Key', 'sugar-event-calendar-google-maps' ); ?></label>
				</th>
				<td>
					<input type="text" class="code" name="sc_maps_google_api_key" id="sc_maps_google_api_key" value="<?php echo esc_attr( $api_key ); ?>">
					<p class="description">
						<?php esc_html_e( 'Enter your Google Maps API Key', 'sugar-event-calendar-google-maps' ) ?>
					</p>
				</td>
			</tr>
		</tbody>
	</table>

<?php
}
