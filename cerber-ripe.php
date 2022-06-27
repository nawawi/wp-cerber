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




// If this file is called directly, abort executing.
if ( ! defined( 'WPINC' ) ) { exit; }

/**
 * RIPE REST API
 *
 * RIPE Database Acceptable Use Policy
 * https://www.ripe.net/manage-ips-and-asns/db/support/documentation/ripe-database-acceptable-use-policy
 * https://www.ripe.net/manage-ips-and-asns/db/faq/faq-db
 *
 */

const RIPE_ERR_EXPIRE = 300;
const RIPE_OK_EXPIRE = 24 * 3600;
const RIPE_HOST = 'http://rest.db.ripe.net/';

/**
 * Search for the information about IP by using RIPE REST API, method 'search'
 * @since 2.7
 *
 */
function ripe_search( $ip = '' ) {

	if ( ! cerber_is_ip_or_net( $ip ) ) {
		return false;
	}

	$key = 'wp-cerber-ripe-' . cerber_get_id_ip( $ip );
	$ripe = get_transient( $key );
	//$ripe = false;

	if ( ! $ripe ) {
		$args = array();
		$args['headers']['Accept'] = 'application/json';
		$args['headers']['User-Agent'] = 'WP Cerber Security';
		$ripe_response = wp_remote_get( RIPE_HOST . 'search?query-string=' . $ip, $args );

		if ( is_wp_error( $ripe_response ) ) {
			return 'WHOIS ERROR: ' . $ripe_response->get_error_message();
		}

		if ( absint( $ripe_response['response']['code'] ) != 200 ) {
			$error = 'WHOIS ERROR: ' . $ripe_response['response']['message'] . ' / ' . $ripe_response['response']['code'];
			set_transient( $key, json_encode( array( 'ripe_error' => $error ) ), RIPE_ERR_EXPIRE );

			return $error;
		}

		$ret = array();
		$ret['body'] = json_decode( $ripe_response['body'], true );
		if ( JSON_ERROR_NONE != json_last_error() ) {
			$error = 'WHOIS ERROR: ' . json_last_error_msg();
			set_transient( $key, json_encode( array( 'ripe_error' => $error ) ), RIPE_ERR_EXPIRE );

			return $error;
		}

		$ret['abuse_email'] = ripe_find_abuse_contact( $ret['body'], $ip );
		set_transient( $key, json_encode( $ret ), RIPE_OK_EXPIRE );
	}
	else {
		$ret = json_decode( $ripe, true );
		if ( ! empty( $ret['ripe_error'] ) ) {
			return $ret['ripe_error'];
		}
	}

	return $ret;
}
/*
 * Retrieve abuse email from response, rollback to direct request to the API
 * @since 2.7
 *
 */
function ripe_find_abuse_contact($ripe_body, $ip){
	//http://rest.db.ripe.net/abuse-contact
	$email = '';
	foreach ( $ripe_body['objects']['object'] as $object ) {
		foreach ( $object['attributes']['attribute'] as $att ) {
			if ( $att['name'] == 'abuse-mailbox' && is_email( $att['value'] ) ) {
				$email = $att['value'];
				break;
			}
		}
	}

	if ( ! $email ) { // make an API request
		$args = array();
		$args['headers']['Accept'] = 'application/json';
		$ripe_response = wp_remote_get( RIPE_HOST . 'abuse-contact/' . $ip, $args );
		if ( is_wp_error( $ripe_response ) ) {
			return $ripe_response->get_error_message();
		}
		$abuse = json_decode( $ripe_response['body'] );
		$abuse = get_object_vars( $abuse );
		if ( is_email( $abuse['abuse-contacts']->email ) ) {
			$email = $abuse['abuse-contacts']->email;
		}
	}

	return $email;
}
/*
 * Get and parse RIPE response to human readable view
 * @since 2.7
 *
 */
function ripe_readable_info($ip){
	$ripe = ripe_search($ip);

	if ( ! is_array( $ripe ) ) { // Error
		if ( ! $ripe ) {
			return array( 'error' => 'RIPE error' );
		}

		return array( 'whois' => $ripe );
	}

	$ret = array();

	$body = $ripe['body'];

	if ( $body['service']['name'] != 'search' ) {
		return $ret;  // only for RIPE search requests & responses
	}

	$info = '';
	foreach ( $body['objects']['object'] as $object ) {
		$info .= '<table class="whois-object otype-' . $object['type'] . '"><tr><td colspan="2"><b>' . strtoupper( $object['type'] ) . '</b></td></tr>';
		foreach ( $object['attributes']['attribute'] as $att ) {
			$value = $att['value'];
			$ret['data'][ $att['name'] ] = $att['value'];

			if ( is_email( $value ) ) {
				$value = '<a href="mailto:' . $value . '">' . $value . '</a>';
			}
			elseif ( strtolower( $att['name'] ) == 'country' ) {
				$value = cerber_get_flag_html( $value, '<b>' . cerber_country_name( $value ) . ' (' . $value . ')</b>' );
				$ret['country'] = $value;
			}

			$info .= '<tr><td>' . $att['name'] . '</td><td>' . $value . '</td></tr>';
		}
		$info .= '</table>';
	}

	if ( ! empty( $ripe['abuse_email'] ) && is_email( $ripe['abuse_email'] ) ) {
		$ret['data']['abuse-mailbox'] = $ripe['abuse_email'];
	}

	// Network
	if ( ! empty( $ret['data']['inetnum'] ) ) {
		$ret['data']['network'] = $ret['data']['inetnum'];
	}

	$ret['whois'] = $info;

	return $ret;
}

