<?php
/**
 * Class Google\WP_Reporting_API\REST\Reporting_Controller
 *
 * @package Google\WP_Reporting_API
 * @license GNU General Public License, version 2
 * @link    https://wordpress.org/plugins/reporting-api/
 */

namespace Google\WP_Reporting_API\REST;

use Google\WP_Reporting_API\Plugin;
use Google\WP_Reporting_API\Report;
use Google\WP_Reporting_API\Report_Log;
use WP_REST_Server;
use WP_REST_Request;
use WP_REST_Response;
use WP_Error;

/**
 * REST API controller class recording reports via the Reporting API spec.
 *
 * @since 0.1.0
 */
class Reporting_Controller {

	/**
	 * The namespace of this controller's route.
	 *
	 * @since 0.1.0
	 * @var string
	 */
	protected $namespace = 'reporting-api/v1';

	/**
	 * The base of this controller's route.
	 *
	 * @since 0.1.0
	 * @var string
	 */
	protected $rest_base = 'reporting';

	/**
	 * List of valid report types.
	 *
	 * @since 0.1.0
	 * @var array
	 */
	protected $valid_types = array(
		'csp',
		'deprecation',
		'intervention',
		'crash',
	);

	/**
	 * Registers the routes for the controller.
	 *
	 * @since 0.1.0
	 */
	public function register_routes() {
		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base,
			array(
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'log_reports' ),
					'permission_callback' => '__return_true',
					'args'                => $this->get_log_reports_args(),
				),
			)
		);

		// Workaround for WordPress core issue.
		add_filter(
			'rest_pre_dispatch',
			function( $result, WP_REST_Server $server, WP_REST_Request $request ) {
				$this->fix_request_content_type( $request );

				return $result;
			},
			10,
			3
		);
	}

	/**
	 * Logs multiple reports.
	 *
	 * @since 0.1.0
	 *
	 * @param WP_REST_Request $request Full details about the request.
	 * @return WP_REST_Response|WP_Error Response object on success, or WP_Error object on failure.
	 */
	public function log_reports( WP_REST_Request $request ) {
		$content_type = $request->get_content_type();
		if ( 'application/reports+json' !== $content_type['value'] && 'application/reports+json' !== $request->get_header( 'Content-Type-Original' ) ) {
			return new WP_Error( 'invalid_content_type', __( 'Invalid content type.', 'reporting-api' ), array( 'status' => 400 ) );
		}

		$now         = current_time( 'timestamp', 1 );
		$reports     = Plugin::instance()->reports();
		$report_logs = Plugin::instance()->report_logs();

		$existing_reports = $reports->query(
			array(
				'body'   => array_map( 'wp_json_encode', array_filter( wp_list_pluck( $report_logs, 'body' ) ) ),
				'number' => 100,
			)
		);
		foreach ( $existing_reports as $report ) {
			$key                      = $report->type . ':' . wp_json_encode( $report->body );
			$existing_reports[ $key ] = $report;
		}

		$errors  = new WP_Error();
		$log_ids = array();

		$report_logs = $request->get_param( 'data' );
		foreach ( $report_logs as $report_log ) {
			if ( ! $report_log['body'] ) {
				$errors->add( 'empty_report_body', __( 'Empty report body.', 'reporting-api' ) );
				continue;
			}

			$key = $report_log['type'] . ':' . wp_json_encode( $report_log['body'] );

			if ( isset( $existing_reports[ $key ] ) ) {
				$report_id = $existing_reports[ $key ]->id;
			} else {
				$report = $reports->insert(
					new Report(
						array(
							'type' => $report_log['type'],
							'body' => $report_log['body'],
						)
					)
				);
				if ( is_wp_error( $report ) ) {
					$errors->add( $report->get_error_code(), $report->get_error_message(), $report->get_error_data() );
					continue;
				}

				$key                      = $report->type . ':' . wp_json_encode( $report->body );
				$existing_reports[ $key ] = $report;

				$report_id = $report->id;
			}

			$report_log = $report_logs->insert(
				new Report_Log(
					array(
						'report_id'  => $report_id,
						'url'        => $report_log['url'],
						'user_agent' => $report_log['user_agent'],
						'triggered'  => date( 'Y-m-d H:i:s', (int) ( $now - $report_log['age'] / 1000.0 ) ),
						'reported'   => date( 'Y-m-d H:i:s', $now ),
					)
				)
			);
			if ( is_wp_error( $report_log ) ) {
				$errors->add( $report_log->get_error_code(), $report_log->get_error_message(), $report_log->get_error_data() );
				continue;
			}

			$log_ids[] = $report_log->id;
		}

		if ( ! empty( $errors->errors ) ) {
			return $errors;
		}

		return new WP_REST_Response( $log_ids );
	}

	/**
	 * Gets the arguments definition for a request to log reports.
	 *
	 * @since 0.1.0
	 *
	 * @return array Log reports arguments definition.
	 */
	protected function get_log_reports_args() {
		return array(
			'data' => array(
				'validate_callback' => 'rest_validate_request_arg',
				'sanitize_callback' => 'rest_sanitize_request_arg',
				'description'       => __( 'List of reports.', 'reporting-api' ),
				'type'              => 'array',
				'items'             => array(
					'validate_callback' => 'rest_validate_request_arg',
					'sanitize_callback' => 'rest_sanitize_request_arg',
					'description'       => __( 'A single report.', 'reporting-api' ),
					'type'              => 'object',
					'properties'        => array(
						'age'        => array(
							'validate_callback' => 'rest_validate_request_arg',
							'sanitize_callback' => 'rest_sanitize_request_arg',
							'description'       => __( 'The number of milliseconds between report timestamp and the current time.', 'reporting-api' ),
							'type'              => 'integer',
							'required'          => true,
						),
						'type'       => array(
							'validate_callback' => 'rest_validate_request_arg',
							'sanitize_callback' => 'rest_sanitize_request_arg',
							'description'       => __( 'The report type.', 'reporting-api' ),
							'type'              => 'string',
							'enum'              => $this->valid_types,
							'required'          => true,
						),
						'url'        => array(
							'validate_callback' => 'rest_validate_request_arg',
							'sanitize_callback' => 'rest_sanitize_request_arg',
							'description'       => __( 'The report URL.', 'reporting-api' ),
							'type'              => 'string',
							'format'            => 'uri',
							'required'          => true,
						),
						'user_agent' => array(
							'validate_callback' => 'rest_validate_request_arg',
							'sanitize_callback' => 'rest_sanitize_request_arg',
							'description'       => __( 'The report user agent.', 'reporting-api' ),
							'type'              => 'string',
							'required'          => true,
						),
						'body'       => array(
							'validate_callback' => 'rest_validate_request_arg',
							'sanitize_callback' => 'rest_sanitize_request_arg',
							'description'       => __( 'The report body.', 'reporting-api' ),
							'type'              => 'object',
							'required'          => true,
						),
					),
				),
			),
		);
	}

	/**
	 * Fixes the request content type to properly handle 'application/reports+json'.
	 *
	 * WordPress core does not parse JSON unless the request content type is 'application/json'. This method works
	 * around that problem.
	 *
	 * Furthermore, WordPress core cannot deal with an array of JSON data being passed. Therefore this method parses
	 * that data into a 'data' parameter if it is a numeric array.
	 *
	 * @since 0.1.0
	 *
	 * @param WP_REST_Request $request Full details about the request.
	 */
	private function fix_request_content_type( WP_REST_Request $request ) {
		$content_type = $request->get_content_type();
		if ( 'application/reports+json' !== $content_type['value'] ) {
			return;
		}

		// Fix content type.
		$request->set_header( 'Content-Type', 'application/json' );
		$request->set_header( 'Content-Type-Original', 'application/reports+json' );

		// Fix numeric array JSON params.
		$json_params = $request->get_json_params();
		if ( wp_is_numeric_array( $json_params ) ) {
			$request->set_param( 'data', $json_params );
		}
	}
}