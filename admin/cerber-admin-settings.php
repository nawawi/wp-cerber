<?php
/*
	Copyright (C) 2015-22 CERBER TECH INC., https://cerber.tech
	Copyright (C) 2015-22 Markov Gregory, https://wpcerber.com

    Licenced under the GNU GPL.

    This program is free software; you can redistribute it and/or modify
    it under the terms of the GNU General Public License as published by
    the Free Software Foundation; either version 3 of the License, or
    (at your option) any later version.

    This program is distributed in the hope that it will be useful,
    but WITHOUT ANY WARRANTY; without even the implied warranty of
    MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
    GNU General Public License for more details.

    You should have received a copy of the GNU General Public License
    along with this program; if not, write to the Free Software
    Foundation, Inc., 51 Franklin St, Fifth Floor, Boston, MA  02110-1301  USA
*/

/*

*========================================================================*
|                                                                        |
|	       ATTENTION!  Do not change or edit this file!                  |
|                                                                        |
*========================================================================*

*/

if ( ! defined( 'WPINC' ) || ! defined( 'CERBER_VER' ) ) {
	exit;
}

// Cerber's settings form in the WP dashboard
// @since 8.5.9.1
const CRB_SETTINGS_GROUP = 'cerber_settings_group';
const CRB_FIELD_PREFIX = 'crb-input-';

function cerber_admin_init() {

	if ( ! cerber_is_admin_page()
	     && ! strpos( $_SERVER['REQUEST_URI'], '/options.php' )
	     && ! nexus_is_valid_request() ) {
		return;
	}

	if ( crb_get_settings( 'top_admin_menu' ) ) {
		add_filter( 'admin_body_class', function ( $classes ) {
			return $classes . ' crb-top_admin_menu_enabled ';
		} );
	}

	cerber_wp_settings_setup( cerber_get_setting_id() );

	if ( isset( $_POST[ CRB_SETTINGS_GROUP ] ) ) {
		add_action( 'updated_option', function () {
			crb_purge_settings_cache();
		} );
	}

	if ( is_multisite() ) {
		cerber_ms_update();
	}

	if ( cerber_is_http_post()
	     && ! nexus_get_context() ) { // it's crucial

		cerber_settings_update();
	}

}

/**
 * Configure WP Settings API stuff for a given admin page
 *
 * @since 7.9.7
 *
 * @param $screen_id string
 * @param $sections array
 */
function cerber_wp_settings_setup( $screen_id, $sections = array() ) {
	if ( ! $sections && ! $sections = cerber_settings_config( array( 'screen_id' => $screen_id ) ) ) {
		return;
	}

	$option = 'cerber-' . $screen_id;
	register_setting( 'cerberus-' . $screen_id, $option );

	global $tmp;
	foreach ( $sections as $section_id => $section_config ) {

		$desc = crb_array_get( $section_config, 'desc' );

		if ( $links = crb_array_get( $section_config, 'seclinks' ) ) {
			foreach ( $links as $link ) {
				$desc .= '<span class="crb-insetting-link">[ <a target="_blank" href="' . $link[1] . '">' . $link[0] . '</a> ]</span>';
			}
		}

		if ( $doclink = crb_array_get( $section_config, 'doclink' ) ) {
			$desc .= '<span class="crb-insetting-link">[ <a class="" target="_blank" href="' . $doclink . '">' . __( 'Know more', 'wp-cerber' ) . '</a> ]</span>';
		}

		$tmp[ $section_id ] = '<span class="crb-section-desc">' . $desc . '</span>';

		add_settings_section( $section_id, crb_array_get( $section_config, 'name', '' ), function ( $sec ) {
			global $tmp;
			if ( $tmp[ $sec['id'] ] ) {
				echo $tmp[ $sec['id'] ];
			}
		}, $option );

		foreach ( $section_config['fields'] as $field => $config ) {

			if ( ( $req_wp = $config['requires_wp'] ?? false )
			     && ! crb_wp_version_compare( (string) $req_wp ) ) {
				continue;
			}

			if ( ( $cb = $config['requires_true'] ?? false )
			     && is_callable( $cb )
			     && ! $cb() ) {
				continue;
			}

			if ( in_array( $field, CRB_PRO_SETTINGS ) && ! lab_lab() ) {
				continue;
			}

			$config['setting'] = $field;
			$config['group'] = $screen_id;

			$config['type'] = $config['type'] ?? 'text';

			// Setting row (tr) class, to specify the input class use 'input_class'
			$config['class'] = '';

			if ( $config['type'] == 'hidden' ) {
				$config['class'] .= ' crb-display-none';
			}

			if ( isset( $config['enabler'] ) ) {
				$config['class'] .= crb_check_enabler( $config, crb_get_settings( $config['enabler'][0] ) );
			}

			add_settings_field( $field, crb_array_get( $config, 'title', '' ), 'cerber_field_show', $option, $section_id, $config );
		}
	}
}

function cerber_get_setting_id( $tab = null ) {
	$id = ( ! $tab ) ? cerber_get_get( 'tab', CRB_SANITIZE_ID ) : $tab;
	if ( ! $id ) {
		$id = cerber_get_wp_option_id();
	}
	if ( ! $id ) {
		$id = crb_admin_get_page();
	}
	// Mapping: some tab names (or page id) doesn't match WP setting names
	// tab => settings id
	$map = array(
		'scan_settings'    => 'scanner', // define('CERBER_OPT_S','cerber-scanner');
		'scan_schedule'    => 'schedule', // define('CERBER_OPT_E','cerber-schedule');
		'scan_policy'      => 'policies',
		'ti_settings'      => 'traffic',
		'captcha'          => 'recaptcha',
		'cerber-recaptcha' => 'antispam',
		'global_policies'  => 'users',
		'cerber-shield'    => 'user_shield',

		'cerber-nexus' => 'nexus-slave',
		'nexus_slave'  => 'nexus-slave',
	);

	crb_addon_settings_mapper( $map );

	if ( isset( $map[ $id ] ) ) {
		return $map[ $id ];
	}

	return $id;
}

/**
 * Works when updating WP options
 *
 * @return bool|string
 */
function cerber_get_wp_option_id( $option_page = null ) {

	if ( ! $option_page ) {
		$option_page = crb_array_get( $_POST, 'option_page' );
	}
	if ( $option_page && ( 0 === strpos( $option_page, 'cerberus-' ) ) ) {
		return substr( $option_page, 9 ); // 8 = length of 'cerberus-'
	}

	return false;
}

/*
 * Display a settings form on an admin page
 *
 */
function cerber_show_settings_form( $group = null ) {

	$action = '';
	if ( is_multisite() ) {
		$action = '';  // Settings API doesn't work in multisite. Post data will be handled in the cerber_ms_update()
	}
	else {
		if ( nexus_is_valid_request() ) {
			//$action = cerber_admin_link();
		}
		else {
			$action = 'options.php'; // Standard way
		}
	}

	?>
	<div class="crb-admin-form">
		<form id="crb-form-<?php echo $group; ?>" class="crb-settings" method="post" action="<?php echo $action; ?>">

			<?php

			cerber_nonce_field( 'control', true );

			settings_fields( 'cerberus-' . $group ); // option group name, the same as used in register_setting().
			do_settings_sections( 'cerber-' . $group ); // the same as used in add_settings_section()	$page

			echo '<div style="padding-left: 220px">';
			//submit_button();
			echo crb_admin_submit_button();
			echo '</div>';

			?>

			<?php echo '<input type="hidden" name="' . CRB_SETTINGS_GROUP . '" value="' . $group . '">'; ?>
		</form>
	</div>
	<?php
}

/**
 * Generates HTML for a single input field on the admin settings page.
 * Prepares setting value to display.
 *
 * @param array $config Setting field config
 */
function cerber_field_show( $config ) {

	$settings = crb_get_settings();

	$pre = '';
	$atts = '';

	$label = $config['label'] ?? '';

	if ( ! empty( $config['doclink'] ) ) {
		$label .= '<span class="crb-insetting-link">[ <a class="crb-no-wrap" target="_blank" href="' . $config['doclink'] . '">' . __( 'Know more', 'wp-cerber' ) . '</a> ]</span>';
	}

	// Unconditionally required
	$req = $config['required'] ?? false;

    if ( $req ) {
		$atts .= ' required="required" ';
	}

    // Conditionally (when enabled) required
	$required = $config['validate']['required'] ?? false;

	if ( $required ) {
		$atts .= ' data-input_required="1" ';
	}

	$placeholder = $config['placeholder'] ?? '';

	if ( ! $placeholder && ( $required || $req ) ) {
		$placeholder = __( 'Required', 'wp-cerber' );
	}

	if ( $placeholder ) {
		$atts .= ' placeholder="' . crb_attr_escape( $placeholder ) . '" ';
	}

	if ( isset( $config['disabled'] ) ) {
		$atts .= ' disabled="disabled" ';
	}

	$value = $config['value'] ?? '';

	$setting = $config['setting'] ?? '';

	if ( $setting ) {
		if ( ! $value && isset( $settings[ $setting ] ) ) {
			$value = $settings[ $setting ];
		}
		if ( ( $setting == 'loginnowp' || $setting == 'loginpath' ) && ! cerber_is_permalink_enabled() ) {
			$atts .= ' disabled="disabled" ';
		}
		if ( $setting == 'loginpath' ) {
			$pre = cerber_get_home_url() . '/';
			$value = urldecode( $value );
		}
	}

	$value = crb_attr_escape( $value );

	if ( isset( $config['list'] ) ) {
		$dlt = $config['delimiter_show'] ?? $config['delimiter'];
		$value = cerber_array2text( $value, $dlt );
	}

	$name_prefix = 'cerber-' . $config['group'];
	$name = $name_prefix . '[' . $setting . ']';

	$id = $config['id'] ?? CRB_FIELD_PREFIX . $setting;

	$class = $config['input_class'] ?? '';

	$data_atts = '';
	$ena_atts = array();
	if ( isset( $config['enabler'] ) ) {
		$ena_atts['input_enabler'] = CRB_FIELD_PREFIX . $config['enabler'][0];
		if ( isset( $config['enabler'][1] ) ) {
			$ena_atts['input_enabler_value'] = $config['enabler'][1];
		}
		foreach ( $ena_atts as $att => $val ) {
			$data_atts .= ' data-' . $att . '="' . $val . '"';
		}
	}

	switch ( $config['type'] ) {

		case 'limitz':
			$s1 = $config['group'] . '-period';
			$s2 = $config['group'] . '-number';
			$s3 = $config['group'] . '-within';

			$html = sprintf( $label,
				cerber_digi_field( $name_prefix . '[' . $s1 . ']', $settings[ $s1 ] ),
				cerber_digi_field( $name_prefix . '[' . $s2 . ']', $settings[ $s2 ] ),
				cerber_digi_field( $name_prefix . '[' . $s3 . ']', $settings[ $s3 ] ) );
			break;

		case 'attempts':
			$html = sprintf( __( '%s retries are allowed within %s minutes', 'wp-cerber' ),
				cerber_digi_field( $name_prefix . '[attempts]', $settings['attempts'] ),
				cerber_digi_field( $name_prefix . '[period]', $settings['period'] ) );
			break;

		case 'reglimit':
			$html = sprintf( __( '%s registrations are allowed within %s minutes from one IP address', 'wp-cerber' ),
				cerber_digi_field( $name_prefix . '[reglimit_num]', $settings['reglimit_num'] ),
				cerber_digi_field( $name_prefix . '[reglimit_min]', $settings['reglimit_min'], 4, 4 ) );
			break;

		case 'aggressive':
			$html = sprintf( __( 'Increase lockout duration to %s hours after %s lockouts in the last %s hours', 'wp-cerber' ),
				cerber_digi_field( $name_prefix . '[agperiod]', $settings['agperiod'] ),
				cerber_digi_field( $name_prefix . '[aglocks]', $settings['aglocks'] ),
				cerber_digi_field( $name_prefix . '[aglast]', $settings['aglast'] ) );
			break;

		case 'notify':
			$html = '<label class="crb-switch"><input class="screen-reader-text" type="checkbox" id="' . $setting . '" name="cerber-' . $config['group'] . '[' . $setting . ']" value="1" ' . checked( 1, $value, false ) . $atts . ' /><span class="crb-slider round"></span></label>'
			        . __( 'Send notification if the number of active lockouts above', 'wp-cerber' ) . ' ' .
			        cerber_digi_field( $name_prefix . '[above]', $settings['above'] ) .
			        crb_test_notify_link( array( 'channel' => 'email' ) );
			break;

		case 'citadel':
			$html = sprintf( __( 'Enable after %s failed login attempts in the last %s minutes', 'wp-cerber' ),
				cerber_digi_field( $name_prefix . '[cilimit]', $settings['cilimit'] ),
				cerber_digi_field( $name_prefix . '[ciperiod]', $settings['ciperiod'] ) . '<i ' . $data_atts . '></i>' );
			break;

		case 'checkbox':
			$html = '<div style="display: table-cell;"><label class="crb-switch"><input class="screen-reader-text" type="checkbox" id="' . $id . '" name="' . $name . '" value="1" ' . checked( 1, $value, false ) . $atts . ' /><span class="crb-slider round"></span></label></div>';
			//$html .= '<div style="display: table-cell;"><label for="' . $args['setting'] . '">' . $label . '</label></div><i ' . $data . '></i>';
			if ( $label ) {
				$html .= '<div style="display: table-cell;"><label for="' . $setting . '">' . $label . '</label></div>';
			}
			if ( $data_atts ) {
				$html .= '<i ' . $data_atts . '></i>';
			}
			break;

		case 'textarea':
			$html = '<textarea class="large-text crb-monospace" id="' . $id . '" name="' . $name . '" ' . $atts . $data_atts . '>' . $value . '</textarea>';
			if ( $label ) {
				$html .= '<br/><label class="crb-below" for="' . $setting . '">' . $label . '</label>';
			}
			break;

		case 'select':
			$html = cerber_select( $name, $config['set'], $value, $class, $id, '', $placeholder, $ena_atts );
			if ( $label ) {
				$html .= '<br/><label class="crb-below">' . $label . '</label>';
			}
			break;

		case 'role_select':
			if ( $label ) {
				$label = '<p class="crb-label-above"><label for="' . $name . '">' . $label . '</label></p>';
			}
			$html = $label . '<div class="crb-select2-multi">' . cerber_role_select( $name . '[]', $value, '', true, '' ) . '<i ' . $data_atts . '></i></div>';
			break;
		case 'checkbox_set':
			if ( $label ) {
				$label = '<p class="crb-label-above"><label for="' . $name . '">' . $label . '</label></p>';
			}
			$html = '<div class="crb-checkbox_set" style="line-height: 2em;" ' . $data_atts . '>' . $label;
			foreach ( $config['set'] as $key => $item ) {
				$v = ( ! empty( $value[ $key ] ) ) ? $value[ $key ] : 0;
				$html .= '<input type="checkbox" value="1" name="' . $name . '[' . $key . ']" ' . checked( 1, $v, false ) . $atts . '/>' . $item . '<br />';
			}
			$html .= '</div>';
			break;
		case 'reptime':
			$html = cerber_time_select( $config, $settings ) . '<i ' . $data_atts . '></i>';
			break;
		case 'timepicker':
			$html = '<input class="crb-tpicker" type="text" size="7" id="' . $setting . '" name="' . $name . '" value="' . $value . '"' . $atts . '/>';
			$html .= ' <label for="' . $setting . '">' . $label . '</label>';
			break;
		case 'hidden':
			$html = '<input type="hidden" id="' . $setting . '" class="crb-hidden-field" name="' . $name . '" value="' . $value . '" />';
			break;
		case 'text':
		default:

		    $type = $config['type'] ?? 'text';

			if ( in_array( $type, array( 'url', 'number', 'email' ) ) ) {
				$input_type = $type;
			}
			else {
				$input_type = 'text';
			}

			$size = '';
			$class = '';

			if ( $type == 'digits' ) {
				$size = '3';
				$class = 'crb-digits';
			}

		    $size = $config['size'] ?? $size;
			$maxlength = $config['maxlength'] ?? $size;

			if ( $maxlength ) {
				$maxlength = ' maxlength="' . $maxlength . '" ';
			}

			if ( $size ) {
				$size = ' size="' . $size . '"';
			}
			else {
				$class = 'crb-wide';
			}


			if ( isset( $config['pattern'] ) ) {
				$atts .= ' pattern="' . $config['pattern'] . '"';
			}

			if ( isset( $config['attr'] ) ) {
				foreach ( $config['attr'] as $at_name => $at_value ) {
					$atts .= ' ' . $at_name . ' ="' . $at_value . '" ';
				}
			}
			else {
				if ( isset( $config['title'] ) ) {
					$atts .= ' title="' . $config['title'] . '"';
				}
			}

			$html = $pre . '<input type="' . $input_type . '" id="' . $setting . '" name="' . $name . '" value="' . $value . '"' . $atts . ' class="' . $class . '" ' . $size . $maxlength . $atts . $data_atts . ' />';

			if ( $label ) {
				if ( ! $size || crb_array_get( $config, 'label_pos' ) == 'below' ) {
					$label = '<br/><label class="crb-below" for="' . $setting . '">' . $label . '</label>';
				}
				else {
					$label = ' <label for="' . $setting . '">' . $label . '</label>';
				}
			}

			$html .= $label;

		break;
	}

	if ( $loh = $config['act_relation'] ?? false ) {
		foreach ( $loh as $item ) {
			if ( in_array( $value, $item[0] ) ) {
				$html .= '<span class="crb-insetting-link">[ <a href="' . cerber_admin_link( 'activity', $item[1] ) . '" target="_blank">' . $item[2] . '</a> ]</span>';
			}
		}
	}

	if ( ! empty( $config['field_switcher'] ) ) {
		$name = 'cerber-' . $config['group'] . '[' . $setting . '-enabled]';
		$value = $settings[ $setting . '-enabled' ] ?? 0;
		$checkbox = '<label class="crb-switch"><input class="screen-reader-text" type="checkbox" id="' . $setting . '-enabled" name="' . $name . '" value="1" ' . checked( 1, $value, false ) . ' /><span class="crb-slider round"></span></label><span>' . $config['field_switcher'] . '</span>';
		$html = $checkbox . ' ' . $html;
	}

	echo '<div class="crb-settings-field">';
	echo $html;

	if ( ! empty( $config['callback_under'] )
	     && $content = call_user_func( $config['callback_under'] ) ) {
		echo '<div class="crb-settings-under">';
		echo $content;
		echo '</div>';
	}

	echo "</div>\n";
}

function cerber_role_select( $name = 'cerber-roles', $selected = array(), $class = '', $multiple = '', $placeholder = '', $width = '75%' ) {

	if ( ! is_array( $selected ) ) {
		$selected = array( $selected );
	}
	if ( ! $placeholder ) {
		$placeholder = __( 'Select one or more roles', 'wp-cerber' );
	}
	$roles = wp_roles();
	$options = array();
	foreach ( $roles->get_names() as $key => $title ) {
		$s         = ( in_array( $key, $selected ) ) ? 'selected' : '';
		$options[] = '<option value="' . $key . '" ' . $s . '>' . $title . '</option>';
	}

	$m = ( $multiple ) ? 'multiple="multiple"' : '';

	// Setting width via class is not working
	$style = '';
	if ( $width ) {
		$style = 'width: ' . $width.';';
	}

	return ' <select style="' . $style . '" name="' . $name . '" class="crb-select2 ' . $class . '" ' . $m . ' data-placeholder="' . $placeholder . '" data-allow-clear="true">' . implode( "\n", $options ) . '</select>';
}

function cerber_time_select($args, $settings){

	// Week
	$php_week = array(
		__( 'Sunday' ),
		__( 'Monday' ),
		__( 'Tuesday' ),
		__( 'Wednesday' ),
		__( 'Thursday' ),
		__( 'Friday' ),
		__( 'Saturday' ),
	);
	$field = $args['setting'].'-day';
	$selected = $settings[ $field ] ?? '';
	$ret = cerber_select( 'cerber-' . $args['group'] . '[' . $field . ']', $php_week, $selected );
	$ret .= ' &nbsp; ' . _x( 'at', 'preposition of time like: at 11:00', 'wp-cerber' ) . ' &nbsp; ';

	// Hours
	$hours = array();
	for ( $i = 0; $i <= 23; $i ++ ) {
		$hours[] = str_pad( $i, 2, '0', STR_PAD_LEFT ) . ':00';
	}
	$field = $args['setting'] . '-time';
	$selected = $settings[ $field ] ?? '';
	$ret .= cerber_select( 'cerber-' . $args['group'] . '[' . $field . ']', $hours, $selected );

	return $ret . crb_test_notify_link( array( 'type' => 'report' ), __( 'Click to send now', 'wp-cerber' ) );
}

function cerber_checkbox( $name, $value, $label = '', $id = '', $atts = '' ) {
	if ( ! $id ) {
		$id = CRB_FIELD_PREFIX . $name;
	}

	return '<div style="display: table-cell;"><label class="crb-switch"><input class="screen-reader-text" type="checkbox" id="' . $id . '" name="' . $name . '" value="1" ' . checked( 1, $value, false ) . $atts . ' /><span class="crb-slider round"></span></label></div>
	<div style="display: table-cell;"><label for="' . $id . '">' . $label . '</label></div>';
}

function cerber_digi_field( $name, $value = '', $size = '3', $maxlength = '3', $id = '' ) {
	return cerber_txt_field( $name, $value, $id, $size, $maxlength, '\d+', 'crb-digits' );
}

function cerber_txt_field( $name, $value = '', $id = '', $size = '', $maxlength = '', $pattern = '', $class = '' ) {
	$atts = '';
	if ( $id ) {
		$atts .= ' id="' . $id . '" ';
	}
	if ( $class ) {
		$atts .= ' class="' . $class . '" ';
	}
	if ( $size ) {
		$atts .= ' size="' . $size . '" ';
	}
	if ( $maxlength ) {
		$atts .= ' maxlength="' . $maxlength . '" ';
	}
	if ( $pattern ) {
		$atts .= ' pattern="' . $pattern . '" ';
	}

	return '<input type="text" name="' . $name . '" value="' . $value . '" ' . $atts . ' />';
}

function cerber_nonce_field( $action = 'control', $echo = false ) {
	$sf = '';
	if ( nexus_is_valid_request() ) {
		$sf = '<input type="hidden" name="cerber_nexus_seal" value="' . nexus_request_data()->seal . '">';
	}
	$nf = wp_nonce_field( $action, 'cerber_nonce', false, false );
	if ( ! $echo ) {
		return $nf . $sf;
	}

	echo $nf . $sf;
}

function crb_admin_submit_button( $text = '', $echo = false ) {
	if ( ! $text ) {
		$text = __( 'Save Changes' );
	}

	$d    = '';
	$hint = '';
	if ( nexus_is_valid_request() && ! nexus_is_granted( 'submit' ) ) {
		$d    = 'disabled="disabled"';
		$hint = ' not available in the read-only mode';
	}

	$html = '<p class="submit"><input ' . $d . ' type="submit" name="submit" id="submit" class="button button-primary" value="' . $text . '"  /> ' . $hint . '</p>';
	if ( $echo ) {
		echo $echo;
	}

	return $html;
}

/*
	Sanitizing users input for Main Settings
*/
add_filter( 'pre_update_option_'.CERBER_OPT, function ($new, $old, $option) {

	$ret = cerber_set_boot_mode( $new['boot-mode'], $old['boot-mode'] );
	if ( is_wp_error( $ret ) ) {
		cerber_admin_notice( __( 'ERROR:', 'wp-cerber' ) . ' ' . $ret->get_error_message() );
		cerber_admin_notice( __( 'Plugin initialization mode has not been changed', 'wp-cerber' ) );
		$new['boot-mode'] = $old['boot-mode'];
	}

	$new['attempts'] = absint( $new['attempts'] );
	$new['period']   = absint( $new['period'] );
	$new['lockout']  = absint( $new['lockout'] );

	$new['agperiod'] = absint( $new['agperiod'] );
	$new['aglocks']  = absint( $new['aglocks'] );
	$new['aglast']   = absint( $new['aglast'] );

	if ( cerber_is_permalink_enabled() ) {
		$new['loginpath'] = urlencode( str_replace( '/', '', $new['loginpath'] ) );
		$new['loginpath'] = sanitize_text_field( $new['loginpath'] );

		if ( $new['loginpath'] ) {
			if ( $new['loginpath'] == 'wp-admin'
			     || preg_match( '/[#|\.\!\?\:\s]/', $new['loginpath'] ) ) {
				cerber_admin_notice( __( 'ERROR:', 'wp-cerber' ) . ' You may not set this value for Custom login URL. Please specify another one.' );
				$new['loginpath'] = $old['loginpath'];
			}
			elseif ( $new['loginpath'] != $old['loginpath'] ) {
				$href    = cerber_get_home_url() . '/' . $new['loginpath'] . '/';
				$url     = urldecode( $href );
				$msg     = array();
				$msg_e   = array();
				$msg[]   = __( 'Attention! You have changed the login URL! The new login URL is', 'wp-cerber' ) . ': <a href="' . $href . '">' . $url . '</a>';
				$msg_e[] = __( 'Attention! You have changed the login URL! The new login URL is', 'wp-cerber' ) . ': ' . $url;
				$msg[]   = __( 'If you use a caching plugin, you have to add your new login URL to the list of pages not to cache.', 'wp-cerber' );
				$msg_e[] = __( 'If you use a caching plugin, you have to add your new login URL to the list of pages not to cache.', 'wp-cerber' );
				cerber_admin_notice( $msg );
				cerber_send_notification( 'newlurl', array( 'text' => $msg_e ) );
			}
		}
	}
	else {
		$new['loginpath'] = '';
		$new['loginnowp'] = 0;
	}

	if ( $new['loginnowp'] && empty( $new['loginpath'] ) && ! class_exists( 'WooCommerce' ) ) {
		cerber_admin_notice( array(
			'<b>' . __( 'Heads up!' ) . '</b>',
			__( 'You have disabled the default login page. Ensure that you have configured an alternative login page. Otherwise, you will not be able to log in.', 'wp-cerber' )
		) );
	}

	$new['ciduration'] = absint( $new['ciduration'] );
	$new['cilimit']    = absint( $new['cilimit'] );
	$new['cilimit']    = $new['cilimit'] == 0 ? '' : $new['cilimit'];
	$new['ciperiod']   = absint( $new['ciperiod'] );
	$new['ciperiod']   = $new['ciperiod'] == 0 ? '' : $new['ciperiod'];
	if ( ! $new['cilimit'] ) {
		$new['ciperiod'] = '';
	}
	if ( ! $new['ciperiod'] ) {
		$new['cilimit'] = '';
	}

	$new['keeplog'] = absint( $new['keeplog'] );

	if ( $new['keeplog'] == 0 ) {
		$new['keeplog'] = 1;
	}

	if ( $new['cookiepref'] != $old['cookiepref'] ) {
		crb_update_cookie_dependent();
	}

	return $new;
}, 10, 3 );

/*
	Sanitizing/checking user input for anti-spam tab settings
*/
add_filter( 'pre_update_option_' . CERBER_OPT_A, function ( $new, $old, $option ) {

	if ( empty( $new['botsany'] ) && empty( $new['botscomm'] ) && empty( $new['botsreg'] ) ) {
		update_site_option( 'cerber-antibot', '' );
	}

	$warn = false;

	if ( ! empty( $new['botsany'] )
         && crb_array_get( $new, 'botsany' ) != crb_array_get( $old, 'botsany' ) ) {
		$warn = true;
	}

	if ( ! empty( $new['botscomm'] )
         && crb_array_get( $new, 'botscomm' ) != crb_array_get( $old, 'botscomm' ) ) {
		$warn = true;
	}

	if ( ! empty( $new['customcomm'] ) ) {
		if ( ! crb_get_compiled( 'custom_comm_slug' ) ) {
			crb_update_compiled( 'custom_comm_slug', crb_random_string( 20, 30 ) );
			crb_update_compiled( 'custom_comm_mark', crb_random_string( 20, 30 ) );
			$warn = true;
		}
	}
	else {
		if ( crb_get_compiled( 'custom_comm_slug' ) ) {
			crb_update_compiled( 'custom_comm_slug', '' );
			$warn = true;
		}
	}

	if ( $warn ) {
		cerber_admin_notice( array(
			'<b>' . __( 'Important note if you have a caching plugin in place', 'wp-cerber' ) . '</b>',
			__( 'To avoid false positives and get better anti-spam performance, please clear the plugin cache.', 'wp-cerber' )
		) );
	}

	return $new;
}, 10, 3 );
/*
	Sanitizing/checking user input for reCAPTCHA tab settings
*/
add_filter( 'pre_update_option_'.CERBER_OPT_C, function ($new, $old, $option) {

	// Check ability to make external HTTP requests
	if ( ! empty( $new['sitekey'] ) && ! empty( $new['secretkey'] ) ) {
		if ( ( ! $goo = get_wp_cerber()->reCaptchaRequest( '1' ) )
		     || ! isset( $goo['success'] ) ) {
			cerber_admin_notice( __( 'ERROR:', 'wp-cerber' ) . ' ' . cerber_get_labels( 'status', 534 ) );
		}
	}

	$new['recaptcha-period'] = absint( $new['recaptcha-period'] );
	$new['recaptcha-number'] = absint( $new['recaptcha-number'] );
	$new['recaptcha-within'] = absint( $new['recaptcha-within'] );

	return $new;
}, 10, 3 );
/*
	Sanitizing/checking user input for Notifications tab settings
*/
add_filter( 'pre_update_option_'.CERBER_OPT_N, function ($new, $old, $option) {

	$emails = cerber_text2array( $new['email'], ',' );

	$new['email'] = array();
	foreach ( $emails as $item ) {
		if ( is_email( $item ) ) {
			$new['email'][] = $item;
		}
		else {
			cerber_admin_notice( __( '<strong>ERROR</strong>: please enter a valid email address.' ) );
		}
	}

	$emails = cerber_text2array( $new['email-report'], ',' );

	$new['email-report'] = array();
	foreach ( $emails as $item ) {
		if ( is_email( $item ) ) {
			$new['email-report'][] = $item;
		}
		else {
			cerber_admin_notice( __( '<strong>ERROR</strong>: please enter a valid email address.' ) );
		}
	}

	$new['emailrate'] = absint( $new['emailrate'] );

	// When we install a new token, we set proper default value for the device setting

	if ( $new['pbtoken'] != $old['pbtoken'] ) {

		if ( ! $new['pbtoken'] ) {
			$new['pbdevice'] = '';
		}
		else {

			$list = cerber_pb_get_devices( $new['pbtoken'] );

			if ( is_array( $list ) && ! empty( $list ) ) {
				$new['pbdevice'] = 'all';
			}
			else {
				$new['pbdevice'] = '';
			}
		}
	}

	return $new;
}, 10, 3 );

/*
    Sanitizing/checking user input for Hardening tab settings
*/
add_filter( 'pre_update_option_'.CERBER_OPT_H, function ($new, $old, $option) {

	$new['restwhite'] = cerber_text2array( $new['restwhite'], "\n", function ( $v ) {
		$v = preg_replace( '/[^a-z_\-\d\/]/i', '', $v );

		return trim( $v, '/' );
	} );

	$result = cerber_htaccess_sync( 'main', $new );
	if ( is_wp_error( $result ) ) {
		$new['adminphp'] = $old['adminphp'];
		cerber_admin_notice( $result->get_error_message() );
	}

	$result = cerber_htaccess_sync( 'media', $new );
	if ( is_wp_error( $result ) ) {
		$new['phpnoupl'] = $old['phpnoupl'];
		cerber_admin_notice( $result->get_error_message() );
	}

	return $new;
}, 10, 3 );
/*
    Sanitizing/checking user input for Traffic Inspector tab settings
*/
add_filter( 'pre_update_option_'.CERBER_OPT_T, function ($new, $old, $option) {

	$new['tiwhite'] = cerber_text2array( $new['tiwhite'], "\n" );
	foreach ( $new['tiwhite'] as $item ) {
		if ( strrpos( $item, '?' ) ) {
			cerber_admin_notice( 'You may not specify the query string with a question mark: ' . htmlspecialchars( $item, ENT_SUBSTITUTE ) );
		}
		if ( strrpos( $item, '://' ) ) {
			cerber_admin_notice( 'You may not specify the full URL: ' . htmlspecialchars( $item, ENT_SUBSTITUTE ) );
		}
	}

	if ( $new['tithreshold'] ) {
		$new['tithreshold'] = absint( $new['tithreshold'] );
	}

	$new['tikeeprec'] = absint( $new['tikeeprec'] );
	if ( $new['tikeeprec'] == 0 ) {
		$new['tikeeprec'] = 1;
		cerber_admin_notice( 'You may not set <b>Keep records for</b> to 0 days. To completely disable logging set <b>Logging mode</b> to Logging disabled.' );
	}

	return $new;
}, 10, 3 );

add_filter( 'pre_update_option_' . CERBER_OPT_US, function ( $new, $old, $option ) {

	if ( ! empty( $new['ds_4acc'] ) ) {
		CRB_DS::enable_shadowing( 1 );
	}
	else {
		CRB_DS::disable_shadowing( 1 );
	}

	if ( ! empty( $new['ds_4roles'] ) ) {
		CRB_DS::enable_shadowing( 2 );
	}
	else {
		CRB_DS::disable_shadowing( 2 );
	}

	return $new;
}, 10, 3 );

add_filter( 'pre_update_option_' . CERBER_OPT_OS, function ( $new, $old, $option ) {

	if ( ! empty( $new['ds_4opts'] ) ) {
		CRB_DS::enable_shadowing( 3 );
	}
	else {
		CRB_DS::disable_shadowing( 3 );
	}

	return $new;
}, 10, 3 );

/*
    Sanitizing/checking user input for Security Scanner settings
*/
add_filter( 'pre_update_option_' . CERBER_OPT_S, function ( $new, $old, $option ) {

	$new['scan_exclude'] = cerber_normal_dirs( $new['scan_exclude'] );

	return $new;
}, 10, 3 );

/*
    Sanitizing/checking user input for Scanner Schedule settings
*/
add_filter( 'pre_update_option_' . CERBER_OPT_E, function ( $new, $old, $option ) {
	$new['scan_aquick']        = absint( $new['scan_aquick'] );
	$new['scan_afull-enabled'] = ( empty( $new['scan_afull-enabled'] ) ) ? 0 : 1;

	$sec = cerber_sec_from_time( $new['scan_afull'] );
	if ( ! $sec || ! ( $sec >= 0 && $sec <= 86400 ) ) {
		$new['scan_afull'] = '01:00';
	}

	$emails = cerber_text2array( $new['email-scan'], ',' );
	$new['email-scan'] = array();
	foreach ( $emails as $item ) {
		if ( is_email( $item ) ) {
			$new['email-scan'][] = $item;
		}
		else {
			cerber_admin_notice( __( '<strong>ERROR</strong>: please enter a valid email address.' ) );
		}
	}

	if ( lab_lab() ) {
		if ( cerber_cloud_sync( $new ) ) {
			cerber_admin_message( __( 'The schedule has been updated', 'wp-cerber' ) );
		}
		else {
			cerber_admin_message( __( 'Unable to update the schedule', 'wp-cerber' ) );
		}
	}

	return $new;
}, 10, 3 );

add_filter( 'pre_update_option_' . CERBER_OPT_P, function ( $new, $old, $option ) {

	$new['scan_delexdir'] = cerber_normal_dirs($new['scan_delexdir']);

	return $new;
}, 10, 3 );

/**
 * Let's sanitize and normalize them all
 * @since 4.1
 *
 */
add_filter( 'pre_update_option', 'cerber_o_o_sanitizer', 10, 3 );
function cerber_o_o_sanitizer( $value, $option, $old_value ) {

	if ( ! in_array( $option, cerber_get_setting_list() ) ) {
		return $value;
	}

	if ( is_array( $value ) ) {

		// Parsing settings, applying formatting, etc.

		foreach ( $value as $setting => &$setting_val ) {
			if ( ! $conf = cerber_settings_config( array( 'setting' => $setting ) ) ) {
				continue;
			}

			if ( $enabler = $conf['enabler'] ?? '' ) {
				if ( crb_check_enabler( $conf, $value[ $enabler[0] ] ) ) {
					continue;
				}
			}

			$callback = crb_array_get( $conf, 'apply' );
			$regex = crb_array_get( $conf, 'regex_filter' ); // Filtering out not allowed chars
			$validate = crb_array_get( $conf, 'validate' );

			if ( isset( $conf['list'] ) ) {
				// Process the values
				$setting_val = cerber_text2array( $setting_val, $conf['delimiter'], $callback, $regex );

				// Remove not allowed values
				global $_deny;
				if ( $_deny = crb_array_get( $conf, 'deny_filter' ) ) {
					$setting_val = array_filter( $setting_val, function ( $e ) {
						global $_deny;

						return ! in_array( $e, $_deny );
					} );
				}
			}
			else {
				// Process the value
				if ( $callback && is_callable( $callback ) ) {
					$setting_val = call_user_func( $callback, $setting_val );
				}
				if ( $regex ) {
					$setting_val = mb_ereg_replace( $regex, '', $setting_val );
				}
				// Validating the value
				if ( $validate ) {

					$field_name = $conf['title'] ?? $conf['label'] ?? 'Unknown field';
					$error_msg = '';

					if ( ! empty( $validate['required'] )
					     && ! $setting_val ) {
						$error_msg = sprintf( __( 'Field %s may not be empty', 'wp-cerber' ), '<b>' . $field_name . '</b>' );
					}
                    elseif ( $setting_val
					         && ( $sat = $validate['satisfy'] ?? false )
					         && is_callable( $sat )
					         && ! call_user_func( $sat, $setting_val ) ) {
						$error_msg = sprintf( __( 'Field %s contains an invalid value', 'wp-cerber' ), '<b>' . $field_name . '</b>' );
					}

					if ( $error_msg ) {
						cerber_admin_notice( '<b>' . __( 'ERROR:' ) . '</b> ' . $error_msg );
					}
				}
			}
		}
	}

	if ( is_array( $value ) ) {
		array_walk_recursive( $value, function ( &$element, $key ) {
			if ( ! is_array( $element ) ) {
				$element = sanitize_text_field( (string) $element );
			}
		} );
	}
	else {
		$value = sanitize_text_field( (string) $value );
	}

    $value = cerber_normalize( $value, $option );

	return $value;
}

function cerber_normal_dirs( $list = array() ) {
	if ( ! is_array( $list ) ) {
		$list = cerber_text2array( $list, "\n" );
	}
	$ready = array();

	foreach ( $list as $item ) {
		$item = rtrim( cerber_normal_path( $item ), '/\\' ) . DIRECTORY_SEPARATOR;
		if ( ! @is_dir( $item ) ) {
			$dir = cerber_get_abspath() . ltrim( $item, DIRECTORY_SEPARATOR );
			if ( ! @is_dir( $dir ) ) {
				cerber_admin_notice( 'Directory does not exist: ' . htmlspecialchars( $item, ENT_SUBSTITUTE ) );
				continue;
			}
			$item = $dir;
		}
		$ready[] = $item;
	}

	return $ready;
}

/*
 * Save settings on the multisite WP.
 * Process POST Form for settings screens.
 * Because Settings API doesn't work in multisite mode!
 *
 */
function cerber_ms_update() {
	if ( ! cerber_is_http_post() || ! isset( $_POST['action'] ) || $_POST['action'] != 'update' ) {
		return;
	}

	if ( ! $wp_id = cerber_get_wp_option_id() ) {  // 7.9.7
		return;
	}

	if ( ! cerber_user_can_manage() ) {
		return;
	}

	// See wp_nonce_field() in the settings_fields() function
	check_admin_referer($_POST['option_page'].'-options');

	$opt_name = 'cerber-' . $wp_id;

	$old = (array) get_site_option( $opt_name );
	$new = $_POST[ $opt_name ];
	$new = apply_filters( 'pre_update_option_' . $opt_name, $new, $old, $opt_name );

	$new = cerber_normalize( $new, $opt_name ); // @since 8.5.1

	cerber_update_site_option( $opt_name, $new );
}

/**
 * An intermediate level for update_site_option() for Cerber's settings.
 * Goal: have a more granular control over processing settings.
 *
 * @since 8.5.9.1
 *
 * @param string $option_name
 * @param $value
 *
 * @return bool
 */
function cerber_update_site_option( $option_name, $value ) {

	$result = update_site_option( $option_name, $value );

	cerber_settings_update();

	crb_purge_settings_cache();

	return $result;
}

/**
 * Updates Cerber's settings in a new way
 *
 * @since 8.6
 *
 */
function cerber_settings_update() {

	if ( ! cerber_is_http_post()
	     || ! $group = crb_get_post_fields( CRB_SETTINGS_GROUP ) ) {
		return;
	}

	// We do not process some specific cases - not a real settings form
	if ( defined( 'CRB_NX_SLAVE' ) && $group == CRB_NX_SLAVE ) {
		return;
	}

	if ( ! $remote = nexus_is_valid_request() ) {
		if ( ! cerber_user_can_manage() ) {
			return;
		}

		// See wp_nonce_field() in the settings_fields() function
		check_admin_referer( $_POST['option_page'] . '-options' );
	}

	$sections = cerber_settings_config( array( 'screen_id' => $group ));
	$fields = array();
	foreach ( $sections as $sec ) {
		if ( $fls = crb_array_get( $sec, 'fields' ) ) {
			$fields = array_merge( $fields, array_keys( $fls ) );
		}
	}

	$fields = array_fill_keys( $fields, '' );
	$post_fields = crb_get_post_fields( 'cerber-' . $group, array() );
	crb_trim_deep( $post_fields );
	$post_fields = stripslashes_deep( $post_fields );
	crb_sanitize_deep( $post_fields ); // removes all tags

	$new_settings = array_merge( $fields, $post_fields );

	if ( ( ! $old_settings = get_site_option( CERBER_CONFIG ) )
	     || ! is_array( $old_settings ) ) {
		$old_settings = array();
	}

	$settings = array_merge( $old_settings, $new_settings );

	if ( serialize( $settings ) !== serialize( $old_settings ) ) {
		$result = update_site_option( CERBER_CONFIG, $settings );
		$equal  = false;
	}
	else {
		$result = false;
		$equal  = true;
	}

	crb_event_handler( 'update_settings', array(
		'group'      => $group,
		'new_values' => $new_settings,
		'equal'      => $equal,
		'result'     => $result,
		'remote'     => $remote
	) );

}

/**
 * Escaping attributes (values) for forms
 *
 * @param array|string $value
 *
 * @return array|string
 */
function crb_attr_escape( $value ) {
	if ( is_array( $value ) ) {
		array_walk_recursive( $value, function ( &$element ) {
			$element = crb_escape( $element );
		} );
	}
	else {
		$value = crb_escape( $value );
	}

	return $value;
}

/**
 * Helper
 *
 * @param string $val
 *
 * @return string Escaped string
 */
function crb_escape( $val ) {
	if ( ! $val
	     || is_numeric( $val ) ) {
		return $val;
	}

	// the same way as in esc_attr();
	return _wp_specialchars( $val, ENT_QUOTES );
}

/**
 * Check setting field enabler and returns conditional inputs CSS class
 *
 * @param array $config The config of a setting field
 * @param mixed $enab_val The value of the enabler field
 *
 * @return string CSS class to be used
 */
function crb_check_enabler( $config, $enab_val ) {
	if ( ! isset( $config['enabler'] ) ) {
		return '';
	}

	$enabled = true;

	if ( isset( $config['enabler'][1] ) ) {
		$target_val = $config['enabler'][1];
		if ( 0 === strpos( $target_val, '[' ) ) {
			$list = json_decode( $target_val );
			if ( ! in_array( $enab_val, $list ) ) {
				$enabled = false;
			}
		}
		else {
			if ( $enab_val != $target_val ) {
				$enabled = false;
			}
		}
	}
	else {
		if ( empty( $enab_val ) ) {
			$enabled = false;
		}
	}

	return ( ! $enabled ) ? ' crb-disable-this' : '';
}