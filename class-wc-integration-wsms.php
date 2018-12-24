<?php
/**
 * Integration WSMS.
 *
 * @package  WC_Integration_WSMS
 * @category Integration
 * @author   Reuven Karasik
 */
if ( ! class_exists( 'WC_Integration_WSMS' ) ) :
	class WC_Integration_WSMS extends WC_Integration {
		/**
		 * Init and hook in the integration.
		 */
		public function __construct() {
			global $woocommerce;
			$this->id                 = 'wsms';
			$this->method_title       = __( 'Sync Subscriptions', 'wsms' );
			$this->method_description = __( 'Sync WooCommerce Subscriptions with MailChimp', 'wsms' );

			// Define user set variables.
			$this->mailchimp_api_key                = $this->get_option( 'mailchimp_api_key' );
			$this->mailchimp_list                   = $this->get_option( 'mailchimp_list' );
			$this->mailchimp_merge_field            = $this->get_option( 'mailchimp_merge_field' );

			// Load the settings.
			$this->init_form_fields();
			$this->init_settings();

			// Actions.
			add_action( 'woocommerce_update_options_integration_' . $this->id, array( $this, 'process_admin_options' ) );
			add_action( 'woocommerce_update_options_integration_' . $this->id, array( $this, 'display_errors' ) );

			add_action( 'woocommerce_subscription_status_updated', array( $this, 'update_subscriber_status' ), 10, 3 );

			// Filters.

		}

		protected function is_config_screen() {
			return strpos( $_SERVER['REQUEST_URI'], '?page=wc-settings' ) > 0;
		}

		public function get_field( $field ) {
			$post_data = $this->get_post_data();
			// var_dump($post_data);

			if ( isset( $post_data[ 'woocommerce_' . $this->id . '_' . $field ] ) ) {
				return $post_data[ 'woocommerce_' . $this->id . '_' . $field ];
			}

			if ( $this->$field ) {
				return $this->$field;
			}

			return null;
		}

		/**
		 * Initialize integration settings form fields.
		 *
		 * @return void
		 */
		public function init_form_fields() {
			$this->form_fields = array(
				'mailchimp_api_key' => array(
					'title'             => __( 'MailChimp API Key', 'wsms' ),
					'type'              => 'text',
					'description'       => __( 'The MailChimp API Key. <a href="https://eepurl.com/dyijVH" target="_blank">How to create an API Key</a>', 'wsms' ),
					'desc_tip'          => false,
					'default'           => '',
				),
			);

			if ( $this->get_field( 'mailchimp_api_key' ) ) {
				$available_lists = array(
					$this->mailchimp_list => '',
				);

				if ( $this->is_config_screen() ) {
					$available_lists = $this->get_mailchimp_lists();
				}
				$this->form_fields['mailchimp_list'] = array(
					'title'             => __( 'MailChimp List', 'wsms' ),
					'type'              => 'select',
					'default'           => '',
					'desc_tip'          => true,
					'description'       => __( 'Which list should we sync the subscribers to?', 'wsms' ),
					'options'           => $available_lists,

				);
			}

			if ( $this->get_field( 'mailchimp_api_key' ) && $this->get_field( 'mailchimp_list' ) ) {
				$available_fields = array(
					$this->mailchimp_merge_field => '',
				);

				if ( $this->is_config_screen() ) {
					$available_fields = $this->get_mailchimp_merge_fields();
				}
				$this->form_fields['mailchimp_merge_field'] = array(
					'title'             => __( 'MailChimp MERGE Field', 'wsms' ),
					'type'              => 'select',
					'default'           => '',
					'desc_tip'          => true,
					'description'       => __( 'Which MERGE field should we sync the data to?', 'wsms' ),
					'options'           => $available_fields,

				);
			}

			if ( $this->get_field( 'mailchimp_api_key' ) && $this->get_field( 'mailchimp_list' ) && $this->get_field( 'mailchimp_merge_field' ) ) {
				$this->form_fields['sync_now'] = array(
					'title'             => __( 'Sync all subscriptions', 'wsms' ),
					'type'              => 'button',
					'desc_tip'          => true,
					'description'       => __( 'Sync right now all the previous subscriptions to mailchimp', 'wsms' ),
					'custom_attributes' => array(
						'onclick'           => 'location.href=location.href+"&sync_all=1&_wpnonce=' . esc_attr( wp_create_nonce( 'wsms_sync_all' ) ) . '"',
					),
				);
			}
		}


		/**
		 * Generate Button HTML.
		 *
		 * @access public
		 * @param mixed $key
		 * @param mixed $data
		 * @since 1.0.0
		 * @return string
		 */
		public function generate_button_html( $key, $data ) {
			$field    = $this->plugin_id . $this->id . '_' . $key;
			$defaults = array(
				'class'             => 'button-secondary',
				'css'               => '',
				'custom_attributes' => array(),
				'desc_tip'          => false,
				'description'       => '',
				'title'             => '',
			);

			$data = wp_parse_args( $data, $defaults );

			ob_start();
			?>
			<tr valign="top">
				<th scope="row" class="titledesc">
					<label for="<?php echo esc_attr( $field ); ?>"><?php echo wp_kses_post( $data['title'] ); ?></label>
					<?php echo $this->get_tooltip_html( $data ); ?>
				</th>
				<td class="forminp">
					<fieldset>
						<legend class="screen-reader-text"><span><?php echo wp_kses_post( $data['title'] ); ?></span></legend>
						<button class="<?php echo esc_attr( $data['class'] ); ?>" type="button" name="<?php echo esc_attr( $field ); ?>" id="<?php echo esc_attr( $field ); ?>" style="<?php echo esc_attr( $data['css'] ); ?>" <?php echo $this->get_custom_attribute_html( $data ); ?>><?php echo wp_kses_post( $data['title'] ); ?></button>
						<?php echo $this->get_description_html( $data ); ?>
					</fieldset>
				</td>
			</tr>
			<?php
			$this->check_sync_now();
			return ob_get_clean();
		}

		public function sync_now() {
			$args = array(
				'subscriptions_per_page' => -1,
				'subscription_status' => 'active',
			);

			$results = array(
				'success' => array(),
				'fail' => array(),
			);
			$subscriptions = wcs_get_subscriptions( $args );
			foreach ( $subscriptions as $subscription ) {
				$email = $subscription->get_billing_email();

				if ( $this->set_subscriber_active( $email ) ) {
					$results['success'][] = $email;
				} else {
					$results['fail'][] = $email;
				}
			}
			return $results;
		}

		protected function check_sync_now() {
			if ( isset( $_GET['sync_all'] ) && $_GET['sync_all'] ) {
				check_admin_referer( 'wsms_sync_all' );

				$results = $this->sync_now();
				// TRANSLATORS: %$1d: Success count, %2$d: Fail count
				$message = sprintf( __( 'Synced everything. %1$d succeeded, %2$d failed.', 'wsms' ), count( $results['success'] ), count( $results['fail'] ) );
				?>
				<script>
					alert("<?php echo $message; ?>");
					location.href=location.href.replace(/&sync_all(=1|)/, '');
				</script>
				<?php
			}
		}

		/**
		 * Validate the API key
		 * @see validate_settings_fields()
		 */
		public function validate_mailchimp_api_key_field( $key ) {
			// get the posted value
			$value = $_POST[ $this->plugin_id . $this->id . '_' . $key ];
			// check if the MailChimp API has the state code in it
			if ( isset( $value ) &&
			 ( 10 > strlen( $value ) || 2 > count( explode( '-', $value ) ) ) ) {
				$this->errors[] = $key;
			}
			return $value;
		}
		/**
		 * Display errors by overriding the display_errors() method
		 * @see display_errors()
		 */
		public function display_errors() {
			// loop through each error and display it
			foreach ( $this->errors as $key => $value ) {
				switch ( $key ) {
					case 'mailchimp_api_key':
						$error_message = __( 'You did not enter a valid MailChimp API Key. Make sure you copy the entire key, including the suffix.' , 'wsms' );
						break;

					default:
						// TRANSLATORS: %s: The field key
						$error_message = sprintf( __( 'There was an error with the %s field.' , 'wsms' ), $key );
						break;
				}
				?>
				<div class="error">
					<p><?php echo $error_message; ?></p>
				</div>
			<?php
			}
		}

		public function add_notice( $text, $class_name ) {
			AdminNotice::display( $class_name, $text );
			// die('ss');
			/*
			add_action(
				'admin_notices', function() use ( &$text, &$class_name ) {
				?>
				<div class="<?php echo $class_name; ?>">
					<p><?php echo $text; ?></p>
				</div>
			<?php

				}
			);
			*/
		}


		protected function request_mailchimp( $args = array(), $resource = false, $resource_id = false, $sub_resource = false, $sub_resource_id = false ) {
			$api_key = $this->get_field( 'mailchimp_api_key' );
			$us = end( explode( '-', $api_key ) );

			if ( ! $api_key || ! $us ) {
				return false;
			}

			$default_args = array(
				'headers'     => array(
					'Authorization' => 'Basic ' . base64_encode( 'user:' . $api_key ),
					'Access-Control-Allow-Origin' => '*',
				),
			);

			$args = array_merge( $default_args, $args );

			$resource_url = '';
			foreach ( array( $resource, $resource_id, $sub_resource, $sub_resource_id ) as $param ) {
				$resource_url .= '/' . $param;
			}

			$full_url = 'https://' . $us . '.api.mailchimp.com/3.0' . $resource_url;

			$response = wp_remote_request( $full_url, $args );
			$body = json_decode( wp_remote_retrieve_body( $response ) );
			return $body;
		}

		/**
		 * Alias for request_mailchimp( 'GET', [, , ,])
		 */
		protected function get_mailchimp( $resource = false, $resource_id = false, $sub_resource = false, $sub_resource_id = false ) {
			return $this->request_mailchimp(
				array(
					'method' => 'GET',
				), $resource, $resource_id, $sub_resource, $sub_resource_id
			);
		}

		protected function get_mailchimp_lists() {
			$empty = array(
				'' => '(not connected)',
			);

			if ( ! $this->get_field( 'mailchimp_api_key' ) ) {
				return $empty;
			}

			$lists = $this->get_mailchimp( 'lists' )->lists;

			if ( ! $lists ) {
				return array(
					'' => '--- Wrong API Key ---',
				);
			}

			$return_list = array();
			foreach ( $lists as $list ) {
				$return_list[ $list->id ] = $list->name;
			}

			return $return_list;
		}

		protected function get_woocommerce_subscribers_emails() {
			if ( ! $this->is_config_screen() ) {
				return '';
			}

			$args = array(
				'subscriptions_per_page' => -1,
				'subscription_status' => 'active',
			);

			$subscriptions = wcs_get_subscriptions( $args );
			$result_string = '';
			foreach ( $subscriptions as $subscription ) {
				$name = $subscription->get_formatted_billing_full_name();
				$email = $subscription->get_billing_email();
				$result_string .= "$name <$email>, \n";
			}

			return $result_string;

		}

		public function get_mailchimp_merge_fields( $list_id = false ) {
			$empty = array(
				'' => __( '--- No MERGE fields found ---', 'wsms' ),
			);

			if ( ! $list_id ) {
				if ( $this->get_field( 'mailchimp_list' ) ) {
					$list_id = $this->get_field( 'mailchimp_list' );
				} else {
					return $empty;
				}
			}

			if ( ! $this->get_field( 'mailchimp_api_key' ) ) {
				return $empty;
			}

			$fields = $this->get_mailchimp( 'lists', $list_id, 'merge-fields' )->merge_fields;

			if ( ! $fields ) {
				return $empty;
			}

			$result_fields = array();
			foreach ( $fields as $field ) {
				$result_fields[ $field->tag ] = "{$field->name} ({$field->tag})";
			}
			return $result_fields;
		}

		public function get_user_merge_fields( $list_id = null ) {
			if ( ! is_user_logged_in() ) {
				return false;
			}

			if ( ! $list_id ) {
				$list_id = $this->get_field( 'mailchimp_list' );
			}

			$user_email = strtolower( wp_get_current_user()->user_email );
			$email_hash = md5( $user_email );

			$fields = $this->get_mailchimp( 'lists', $list_id, 'members', $email_hash );

			if ( $fields && $fields->merge_fields ) {
				return $fields->merge_fields;
			}
			return false;
		}

		public function set_subscriber( $email, $status ) {
			if ( ! $email || ! is_numeric( $status ) || ! $this->get_field( 'mailchimp_merge_field' ) ) {
				return false;
			}

			$email_hash = md5( $email );
			$result = $this->request_mailchimp(
				array(
					'method' => 'PUT',
					'body'   => json_encode(
						array(
							'merge_fields' => array(
								$this->get_field( 'mailchimp_merge_field' ) => $status,
							),
						)
					),
				), 'lists', $this->get_field( 'mailchimp_list' ), 'members', $email_hash
			);

			return $status == $result->merge_fields->{$this->get_field( 'mailchimp_merge_field' )};
		}

		public function set_subscriber_active( $email ) {
			return $this->set_subscriber( $email, 1 );
		}

		public function set_subscriber_cancel( $email ) {
			return $this->set_subscriber( $email, -1 );
		}

		public function update_subscriber_status( $subscription, $new_status, $old_status ) {
			$email = $subscription->get_billing_email();
			if ( 'active' === $new_status ) {
				$merge_code = 'on';
				$success = $this->set_subscriber_active( $email );
			} else {
				$merge_code = 'off';
				$success = $this->set_subscriber_cancel( $email );
			}

			if ( $success ) {
				// TRANSLATORS: %$1s: User email, %2$s: new status
				$this->add_notice( sprintf( __( 'WSMS Set the appropriate merge fields for user %1$s to %2$s', 'wsms' ), $email, $merge_code ), 'success' );
			} else {
				// TRANSLATORS: %$1s: User email, %2$s: new status
				$this->add_notice( sprintf( __( 'WSMS Could not set the appropriate merge fields for user %1$s to %2$s', 'wsms' ), $email, $merge_code ), 'error' );
			}

		}
	}
endif;
