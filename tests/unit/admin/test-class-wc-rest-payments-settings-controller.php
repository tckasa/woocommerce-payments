<?php
/**
 * Class WC_REST_Payments_Settings_Controller_Test
 *
 * @package WooCommerce\Payments\Tests
 */

use Automattic\WooCommerce\Blocks\Package;
use Automattic\WooCommerce\Blocks\RestApi;
use WCPay\Constants\Payment_Method;
use WCPay\Database_Cache;
use WCPay\Duplicate_Payment_Prevention_Service;
use WCPay\Payment_Methods\Eps_Payment_Method;
use WCPay\Payment_Methods\UPE_Payment_Gateway;
use WCPay\Payment_Methods\UPE_Split_Payment_Gateway;
use WCPay\Payment_Methods\CC_Payment_Method;
use WCPay\Payment_Methods\Bancontact_Payment_Method;
use WCPay\Payment_Methods\Becs_Payment_Method;
use WCPay\Payment_Methods\Giropay_Payment_Method;
use WCPay\Payment_Methods\Sofort_Payment_Method;
use WCPay\Payment_Methods\P24_Payment_Method;
use WCPay\Payment_Methods\Ideal_Payment_Method;
use WCPay\Payment_Methods\Sepa_Payment_Method;
use WCPay\Payment_Methods\Link_Payment_Method;
use WCPay\Session_Rate_Limiter;

/**
 * WC_REST_Payments_Settings_Controller_Test unit tests.
 */
class WC_REST_Payments_Settings_Controller_Test extends WCPAY_UnitTestCase {

	/**
	 * Tested REST route.
	 * @var string
	 */
	protected static $settings_route;

	/**
	 * The system under test.
	 *
	 * @var WC_REST_Payments_Settings_Controller
	 */
	private $controller;

	/**
	 * Gateway.
	 *
	 * @var WC_Payment_Gateway_WCPay
	 */
	private $gateway;

	/**
	 * @var WC_Payments_API_Client
	 */
	private $mock_api_client;

	/**
	 * Mock WC_Payments_Account.
	 *
	 * @var WC_Payments_Account|PHPUnit_Framework_MockObject_MockObject
	 */
	private $mock_wcpay_account;
	/**
	 * @var \PHPUnit\Framework\MockObject\MockObject|Database_Cache
	 */
	private $mock_db_cache;

	/**
	 * An array of mocked split UPE payment gateways mapped to payment method ID.
	 *
	 * @var array
	 */
	private $mock_upe_payment_gateway;

	/**
	 * An array of mocked split UPE payment gateways mapped to payment method ID.
	 *
	 * @var array
	 */
	private $mock_split_upe_payment_gateways;

	/**
	 * UPE system under test.
	 *
	 * @var WC_REST_Payments_Settings_Controller
	 */
	private $upe_controller;

	/**
	 * UPE system under test.
	 *
	 * @var WC_REST_Payments_Settings_Controller
	 */
	private $upe_split_controller;

	/**
	 * WC_Payments_Localization_Service instance.
	 *
	 * @var WC_Payments_Localization_Service
	 */
	private $mock_localization_service;

	/**
	 * Pre-test setup
	 */
	public function set_up() {
		parent::set_up();

		self::$settings_route = '/wc/v3/' . ( $this->is_wpcom() ? 'sites/3/' : '' ) . 'payments/settings';

		require_once __DIR__ . '/../helpers/class-wc-blocks-rest-api-registration-preventer.php';
		WC_Blocks_REST_API_Registration_Preventer::prevent();

		// Set the user so that we can pass the authentication.
		wp_set_current_user( 1 );

		$this->mock_api_client = $this->getMockBuilder( WC_Payments_API_Client::class )
			->disableOriginalConstructor()
			->getMock();

		$this->mock_wcpay_account        = $this->createMock( WC_Payments_Account::class );
		$this->mock_db_cache             = $this->createMock( Database_Cache::class );
		$customer_service                = new WC_Payments_Customer_Service( $this->mock_api_client, $this->mock_wcpay_account, $this->mock_db_cache );
		$token_service                   = new WC_Payments_Token_Service( $this->mock_api_client, $customer_service );
		$order_service                   = new WC_Payments_Order_Service( $this->mock_api_client );
		$action_scheduler_service        = new WC_Payments_Action_Scheduler_Service( $this->mock_api_client, $order_service );
		$mock_rate_limiter               = $this->createMock( Session_Rate_Limiter::class );
		$mock_dpps                       = $this->createMock( Duplicate_Payment_Prevention_Service::class );
		$this->mock_localization_service = $this->createMock( WC_Payments_Localization_Service::class );

		$this->gateway    = new WC_Payment_Gateway_WCPay(
			$this->mock_api_client,
			$this->mock_wcpay_account,
			$customer_service,
			$token_service,
			$action_scheduler_service,
			$mock_rate_limiter,
			$order_service,
			$mock_dpps,
			$this->mock_localization_service
		);
		$this->controller = new WC_REST_Payments_Settings_Controller( $this->mock_api_client, $this->gateway, $this->mock_wcpay_account );

		$mock_payment_methods   = [];
		$payment_method_classes = [
			Becs_Payment_Method::class,
			CC_Payment_Method::class,
			Bancontact_Payment_Method::class,
			Eps_Payment_Method::class,
			Giropay_Payment_Method::class,
			Sofort_Payment_Method::class,
			Sepa_Payment_Method::class,
			P24_Payment_Method::class,
			Ideal_Payment_Method::class,
			Link_Payment_Method::class,
		];

		foreach ( $payment_method_classes as $payment_method_class ) {
			$mock_payment_method = $this->getMockBuilder( $payment_method_class )
				->setConstructorArgs( [ $token_service ] )
				->setMethods( [ 'is_subscription_item_in_cart' ] )
				->getMock();
			$mock_payment_method->expects( $this->any() )
				->method( 'is_subscription_item_in_cart' )
				->will( $this->returnValue( false ) );

			$mock_payment_methods[ $mock_payment_method->get_id() ] = $mock_payment_method;
		}

		$this->mock_upe_payment_gateway = new UPE_Payment_Gateway(
			$this->mock_api_client,
			$this->mock_wcpay_account,
			$customer_service,
			$token_service,
			$action_scheduler_service,
			$mock_payment_methods,
			$mock_rate_limiter,
			$order_service,
			$mock_dpps,
			$this->mock_localization_service
		);

		$this->upe_controller = new WC_REST_Payments_Settings_Controller( $this->mock_api_client, $this->mock_upe_payment_gateway, $this->mock_wcpay_account );

		$this->mock_upe_split_payment_gateway = new UPE_Split_Payment_Gateway(
			$this->mock_api_client,
			$this->mock_wcpay_account,
			$customer_service,
			$token_service,
			$action_scheduler_service,
			$mock_payment_methods['card'],
			$mock_payment_methods,
			$mock_rate_limiter,
			$order_service,
			$mock_dpps,
			$this->mock_localization_service
		);

		$this->upe_split_controller = new WC_REST_Payments_Settings_Controller( $this->mock_api_client, $this->mock_upe_split_payment_gateway, $this->mock_wcpay_account );

		$this->mock_api_client
			->method( 'is_server_connected' )
			->willReturn( true );

		$this->mock_wcpay_account
			->expects( $this->any() )
			->method( 'get_fees' )
			->willReturn( $mock_payment_methods );

		$this->mock_wcpay_account
			->expects( $this->any() )
			->method( 'is_card_present_eligible' )
			->willReturn( true );

		$this->mock_wcpay_account
			->expects( $this->any() )
			->method( 'get_is_live' )
			->willReturn( true );
	}

	public function tear_down() {
		parent::tear_down();
		update_option( WC_Payments_Features::UPE_FLAG_NAME, '0' );
		update_option( WC_Payments_Features::UPE_SPLIT_FLAG_NAME, '0' );
		update_option( WC_Payments_Features::UPE_DEFERRED_INTENT_FLAG_NAME, '0' );
		WC_Blocks_REST_API_Registration_Preventer::stop_preventing();
	}

	public function test_get_settings_request_returns_status_code_200() {
		$request = new WP_REST_Request( 'GET', self::$settings_route );

		$response = rest_do_request( $request );

		$this->assertEquals( 200, $response->get_status() );
	}

	public function test_get_settings_returns_enabled_payment_method_ids() {
		$this->mock_localization_service->method( 'get_country_locale_data' )->willReturn(
			[
				'currency_code' => 'usd',
			]
		);

		$response           = $this->controller->get_settings();
		$enabled_method_ids = $response->get_data()['enabled_payment_method_ids'];

		$this->assertEquals(
			[ Payment_Method::CARD ],
			$enabled_method_ids
		);
	}

	public function test_upe_get_settings_returns_available_payment_method_ids() {
		$this->mock_localization_service->method( 'get_country_locale_data' )->willReturn(
			[
				'currency_code' => 'usd',
			]
		);
		$response           = $this->upe_controller->get_settings();
		$enabled_method_ids = $response->get_data()['available_payment_method_ids'];

		$this->assertEquals(
			[
				Payment_Method::CARD,
				Payment_Method::BECS,
				Payment_Method::BANCONTACT,
				Payment_Method::EPS,
				Payment_Method::GIROPAY,
				Payment_Method::IDEAL,
				Payment_Method::SOFORT,
				Payment_Method::SEPA,
				Payment_Method::P24,
				Payment_Method::LINK,
			],
			$enabled_method_ids
		);
	}

	public function test_split_upe_get_settings_returns_available_payment_method_ids() {
		$this->mock_localization_service->method( 'get_country_locale_data' )->willReturn(
			[
				'currency_code' => 'usd',
			]
		);
		$response           = $this->upe_split_controller->get_settings();
		$enabled_method_ids = $response->get_data()['available_payment_method_ids'];

		$this->assertEquals(
			[
				Payment_Method::CARD,
				Payment_Method::BECS,
				Payment_Method::BANCONTACT,
				Payment_Method::EPS,
				Payment_Method::GIROPAY,
				Payment_Method::IDEAL,
				Payment_Method::SOFORT,
				Payment_Method::SEPA,
				Payment_Method::P24,
				Payment_Method::LINK,
			],
			$enabled_method_ids
		);
	}

	public function test_get_settings_request_returns_test_mode_flag() {
		$this->mock_localization_service->method( 'get_country_locale_data' )->willReturn(
			[
				'currency_code' => 'usd',
			]
		);
		WC_Payments::mode()->dev();
		$this->assertEquals( true, $this->controller->get_settings()->get_data()['is_test_mode_enabled'] );

		$this->gateway->update_option( 'test_mode', 'no' );
		$this->assertEquals( true, $this->controller->get_settings()->get_data()['is_test_mode_enabled'] );

		WC_Payments::mode()->test();
		$this->assertEquals( true, $this->controller->get_settings()->get_data()['is_test_mode_enabled'] );

		WC_Payments::mode()->live();
		$this->assertEquals( false, $this->controller->get_settings()->get_data()['is_test_mode_enabled'] );
	}

	public function test_get_settings_returns_if_wcpay_is_enabled() {
		$this->mock_localization_service->method( 'get_country_locale_data' )->willReturn(
			[
				'currency_code' => 'usd',
			]
		);
		$this->gateway->enable();
		$response = $this->controller->get_settings();
		$this->assertTrue( $response->get_data()['is_wcpay_enabled'] );

		$this->gateway->disable();
		$response = $this->controller->get_settings();
		$this->assertFalse( $response->get_data()['is_wcpay_enabled'] );
	}

	public function test_get_settings_fails_if_user_cannot_manage_woocommerce() {
		$cb = $this->create_can_manage_woocommerce_cap_override( false );
		add_filter( 'user_has_cap', $cb );
		$response = rest_do_request( new WP_REST_Request( 'GET', self::$settings_route ) );
		$this->assertEquals( 403, $response->get_status() );
		remove_filter( 'user_has_cap', $cb );

		$cb = $this->create_can_manage_woocommerce_cap_override( true );
		add_filter( 'user_has_cap', $cb );
		$response = rest_do_request( new WP_REST_Request( 'GET', self::$settings_route ) );
		$this->assertEquals( 200, $response->get_status() );
		remove_filter( 'user_has_cap', $cb );
	}

	public function test_get_settings_without_error_when_faulty_enabled_payment_methods() {
		$this->mock_localization_service->method( 'get_country_locale_data' )->willReturn(
			[
				'currency_code' => 'usd',
			]
		);
		$this->gateway->update_option(
			'available_payment_method_ids',
			[
				Payment_Method::CARD,
				Payment_Method::SEPA,
			]
		);

		$request = new WP_REST_Request();
		$request->set_param( 'enabled_payment_method_ids', [ Payment_Method::CARD, Payment_Method::LINK ] );

		$response = $this->controller->get_settings( $request );

		$this->assertSame( [ Payment_Method::CARD ], $response->get_data()['enabled_payment_method_ids'] );
	}

	public function test_update_settings_request_returns_status_code_200() {
		$request = new WP_REST_Request( 'POST', self::$settings_route );
		$request->set_param( 'is_wcpay_enabled', true );
		$request->set_param( 'enabled_payment_method_ids', [ Payment_Method::CARD ] );

		$response = rest_do_request( $request );

		$this->assertEquals( 200, $response->get_status() );
	}

	public function test_update_settings_enables_wcpay() {
		$request = new WP_REST_Request();
		$request->set_param( 'is_wcpay_enabled', true );

		$this->controller->update_settings( $request );

		$this->assertTrue( $this->gateway->is_enabled() );
	}

	public function test_update_settings_disables_wcpay() {
		$request = new WP_REST_Request();
		$request->set_param( 'is_wcpay_enabled', false );

		$this->controller->update_settings( $request );

		$this->assertFalse( $this->gateway->is_enabled() );
	}

	public function test_update_settings_does_not_toggle_is_wcpay_enabled_if_not_supplied() {
		$status_before_request = $this->gateway->is_enabled();

		$request = new WP_REST_Request();

		$this->controller->update_settings( $request );

		$this->assertEquals( $status_before_request, $this->gateway->is_enabled() );
	}

	public function test_update_settings_returns_error_on_non_bool_is_wcpay_enabled_value() {
		$request = new WP_REST_Request( 'POST', self::$settings_route );
		$request->set_param( 'is_wcpay_enabled', 'foo' );

		$response = rest_do_request( $request );

		$this->assertEquals( 400, $response->get_status() );
	}

	public function test_upe_update_settings_saves_enabled_payment_methods() {
			$this->mock_upe_payment_gateway->update_option( 'upe_enabled_payment_method_ids', [ Payment_Method::CARD ] );

			$request = new WP_REST_Request();
			$request->set_param( 'enabled_payment_method_ids', [ Payment_Method::CARD, Payment_Method::GIROPAY ] );

			$this->upe_controller->update_settings( $request );

			$this->assertEquals( [ Payment_Method::CARD, Payment_Method::GIROPAY ], $this->mock_upe_payment_gateway->get_option( 'upe_enabled_payment_method_ids' ) );
	}

	public function test_upe_split_update_settings_saves_enabled_payment_methods() {
			$this->mock_upe_split_payment_gateway->update_option( 'upe_enabled_payment_method_ids', [ Payment_Method::CARD ] );

			$request = new WP_REST_Request();
			$request->set_param( 'enabled_payment_method_ids', [ Payment_Method::CARD, Payment_Method::GIROPAY ] );

			$this->upe_split_controller->update_settings( $request );

			$this->assertEquals( [ Payment_Method::CARD, Payment_Method::GIROPAY ], $this->mock_upe_split_payment_gateway->get_option( 'upe_enabled_payment_method_ids' ) );
	}

	public function test_update_settings_validation_fails_if_invalid_gateway_id_supplied() {
		$request = new WP_REST_Request( 'POST', self::$settings_route );
		$request->set_param( 'enabled_payment_method_ids', [ 'foo', 'baz' ] );

		$response = rest_do_request( $request );
		$this->assertEquals( 400, $response->get_status() );
	}

	public function test_update_settings_fails_if_user_cannot_manage_woocommerce() {
		$cb = $this->create_can_manage_woocommerce_cap_override( false );
		add_filter( 'user_has_cap', $cb );
		$response = rest_do_request( new WP_REST_Request( 'POST', self::$settings_route ) );
		$this->assertEquals( 403, $response->get_status() );
		remove_filter( 'user_has_cap', $cb );

		$cb = $this->create_can_manage_woocommerce_cap_override( true );
		add_filter( 'user_has_cap', $cb );
		$response = rest_do_request( new WP_REST_Request( 'POST', self::$settings_route ) );
		$this->assertEquals( 200, $response->get_status() );
		remove_filter( 'user_has_cap', $cb );
	}

	public function test_update_settings_enables_manual_capture() {
		$request = new WP_REST_Request();
		$request->set_param( 'is_manual_capture_enabled', true );

		$this->controller->update_settings( $request );

		$this->assertEquals( 'yes', $this->gateway->get_option( 'manual_capture' ) );
	}

	public function test_update_settings_disables_manual_capture() {
		$request = new WP_REST_Request();
		$request->set_param( 'is_manual_capture_enabled', false );

		$this->controller->update_settings( $request );

		$this->assertEquals( 'no', $this->gateway->get_option( 'manual_capture' ) );
	}

	public function test_update_settings_does_not_toggle_is_manual_capture_enabled_if_not_supplied() {
		$status_before_request = $this->gateway->get_option( 'manual_capture' );

		$request = new WP_REST_Request();

		$this->controller->update_settings( $request );

		$this->assertEquals( $status_before_request, $this->gateway->get_option( 'manual_capture' ) );
	}

	public function test_update_settings_returns_error_on_non_bool_is_manual_capture_enabled_value() {
		$request = new WP_REST_Request( 'POST', self::$settings_route );
		$request->set_param( 'is_manual_capture_enabled', 'foo' );

		$response = rest_do_request( $request );

		$this->assertEquals( 400, $response->get_status() );
	}

	public function test_update_settings_saves_debug_log() {
		$this->assertEquals( 'no', $this->gateway->get_option( 'enable_logging' ) );

		$request = new WP_REST_Request();
		$request->set_param( 'is_debug_log_enabled', true );

		$this->controller->update_settings( $request );

		$this->assertEquals( 'yes', $this->gateway->get_option( 'enable_logging' ) );
	}

	public function test_update_settings_does_not_save_debug_log_when_dev_mode_enabled() {
		WC_Payments::mode()->dev();
		$this->assertEquals( 'no', $this->gateway->get_option( 'enable_logging' ) );

		$request = new WP_REST_Request();
		$request->set_param( 'is_debug_log_enabled', true );

		$this->controller->update_settings( $request );

		$this->assertEquals( 'no', $this->gateway->get_option( 'enable_logging' ) );
		WC_Payments::mode()->live();
	}

	public function test_update_settings_saves_test_mode() {
		$this->assertEquals( 'no', $this->gateway->get_option( 'test_mode' ) );

		$request = new WP_REST_Request();
		$request->set_param( 'is_test_mode_enabled', true );

		$this->controller->update_settings( $request );

		$this->assertEquals( 'yes', $this->gateway->get_option( 'test_mode' ) );
	}

	public function test_update_settings_does_not_save_test_mode_when_dev_mode_enabled() {
		WC_Payments::mode()->dev();
		$this->assertEquals( 'no', $this->gateway->get_option( 'test_mode' ) );
		$this->assertEquals( true, WC_Payments::mode()->is_test() );

		$request = new WP_REST_Request();
		$request->set_param( 'is_test_mode_enabled', true );

		$this->controller->update_settings( $request );

		$this->assertEquals( 'no', $this->gateway->get_option( 'test_mode' ) );
		$this->assertEquals( true, WC_Payments::mode()->is_test() );

		WC_Payments::mode()->live();
	}

	public function test_update_settings_saves_account() {
		$this->mock_wcpay_account->expects( $this->once() )
			->method( 'update_stripe_account' )
			->with(
				$this->equalTo(
					[
						'statement_descriptor'            => 'test statement descriptor',
						'business_name'                   => 'test business_name',
						'business_url'                    => 'test business_url',
						'business_support_address'        => 'test business_support_address',
						'business_support_email'          => 'test business_support_email',
						'business_support_phone'          => 'test business_support_phone',
						'branding_logo'                   => 'test branding_logo',
						'branding_icon'                   => 'test branding_icon',
						'branding_primary_color'          => 'test branding_primary_color',
						'branding_secondary_color'        => 'test branding_secondary_color',
						'deposit_schedule_interval'       => 'test deposit_schedule_interval',
						'deposit_schedule_weekly_anchor'  => 'test deposit_schedule_weekly_anchor',
						'deposit_schedule_monthly_anchor' => 'test deposit_schedule_monthly_anchor',
					]
				)
			);

		$request = new WP_REST_Request();
		$request->set_param( 'account_statement_descriptor', 'test statement descriptor' );
		$request->set_param( 'account_business_name', 'test business_name' );
		$request->set_param( 'account_business_url', 'test business_url' );
		$request->set_param( 'account_business_support_address', 'test business_support_address' );
		$request->set_param( 'account_business_support_email', 'test business_support_email' );
		$request->set_param( 'account_business_support_phone', 'test business_support_phone' );
		$request->set_param( 'account_branding_logo', 'test branding_logo' );
		$request->set_param( 'account_branding_icon', 'test branding_icon' );
		$request->set_param( 'account_branding_primary_color', 'test branding_primary_color' );
		$request->set_param( 'account_branding_secondary_color', 'test branding_secondary_color' );
		$request->set_param( 'deposit_schedule_interval', 'test deposit_schedule_interval' );
		$request->set_param( 'deposit_schedule_weekly_anchor', 'test deposit_schedule_weekly_anchor' );
		$request->set_param( 'deposit_schedule_monthly_anchor', 'test deposit_schedule_monthly_anchor' );

		$this->controller->update_settings( $request );
	}

	public function test_update_settings_saves_payment_request_button_theme() {
		$this->assertEquals( 'dark', $this->gateway->get_option( 'payment_request_button_theme' ) );

		$request = new WP_REST_Request();
		$request->set_param( 'payment_request_button_theme', 'light' );

		$this->controller->update_settings( $request );

		$this->assertEquals( 'light', $this->gateway->get_option( 'payment_request_button_theme' ) );
	}

	public function test_update_settings_saves_payment_request_button_size() {
		$this->assertEquals( 'default', $this->gateway->get_option( 'payment_request_button_size' ) );

		$request = new WP_REST_Request();
		$request->set_param( 'payment_request_button_size', 'medium' );

		$this->controller->update_settings( $request );

		$this->assertEquals( 'medium', $this->gateway->get_option( 'payment_request_button_size' ) );
	}

	public function test_update_settings_saves_payment_request_button_type() {
		$this->assertEquals( 'buy', $this->gateway->get_option( 'payment_request_button_type' ) );

		$request = new WP_REST_Request();
		$request->set_param( 'payment_request_button_type', 'book' );

		$this->controller->update_settings( $request );

		$this->assertEquals( 'book', $this->gateway->get_option( 'payment_request_button_type' ) );
	}

	public function test_update_settings_does_not_save_account_if_not_supplied() {
		$request = new WP_REST_Request();

		$this->mock_wcpay_account->expects( $this->never() )
			->method( 'update_stripe_account' )
			->with( $this->anything() );

		$this->controller->update_settings( $request );
	}

	public function test_update_settings_enables_saved_cards() {
		$request = new WP_REST_Request();
		$request->set_param( 'is_saved_cards_enabled', true );

		$this->controller->update_settings( $request );

		$this->assertEquals( 'yes', $this->gateway->get_option( 'saved_cards' ) );
	}

	public function test_update_settings_disables_saved_cards() {
		$request = new WP_REST_Request();
		$request->set_param( 'is_saved_cards_enabled', false );

		$this->controller->update_settings( $request );

		$this->assertEquals( 'no', $this->gateway->get_option( 'saved_cards' ) );
	}

	public function test_enable_woopay_converts_upe_flag() {
		update_option( WC_Payments_Features::UPE_FLAG_NAME, '1' );
		update_option( WC_Payments_Features::UPE_SPLIT_FLAG_NAME, '0' );
		update_option( WC_Payments_Features::UPE_DEFERRED_INTENT_FLAG_NAME, '0' );
		$this->gateway->update_option( 'platform_checkout', 'no' );

		$request = new WP_REST_Request();
		$request->set_param( 'is_woopay_enabled', true );

		$this->controller->update_settings( $request );

		$this->assertEquals( '0', get_option( WC_Payments_Features::UPE_FLAG_NAME ) );
		$this->assertEquals( '0', get_option( WC_Payments_Features::UPE_SPLIT_FLAG_NAME ) );
		$this->assertEquals( '1', get_option( WC_Payments_Features::UPE_DEFERRED_INTENT_FLAG_NAME ) );
	}

	public function deposit_schedules_data_provider() {
		return [
			[
				[ 'deposit_schedule_interval' => 'daily' ],
				[ 'deposit_schedule_interval' => 'daily' ],
				'manual',
			],
			[
				[
					'deposit_schedule_interval'      => 'weekly',
					'deposit_schedule_weekly_anchor' => 'tuesday',
				],
				[
					'deposit_schedule_interval'      => 'weekly',
					'deposit_schedule_weekly_anchor' => 'tuesday',
				],
			],
			[
				// If only the weekly anchor is sent through as an update, we should re-send through the previously set interval.
				[ 'deposit_schedule_weekly_anchor' => 'tuesday' ],
				[
					'deposit_schedule_interval'      => 'weekly',
					'deposit_schedule_weekly_anchor' => 'tuesday',
				],
				'weekly',
			],
			[
				[
					'deposit_schedule_interval'       => 'monthly',
					'deposit_schedule_monthly_anchor' => '3',
				],
				[
					'deposit_schedule_interval'       => 'monthly',
					'deposit_schedule_monthly_anchor' => '3',
				],
			],
			[
				// If only the monthly anchor is sent through as an update, we should re-send through the previously set interval.
				[ 'deposit_schedule_monthly_anchor' => '6' ],
				[
					'deposit_schedule_interval'       => 'monthly',
					'deposit_schedule_monthly_anchor' => '6',
				],
				'monthly',
			],
			[
				[ 'deposit_schedule_interval' => 'manual' ],
				[ 'deposit_schedule_interval' => 'manual' ],
			],
			[
				// we don't expect to send an update request if the settings match the current settings.
				[ 'deposit_schedule_interval' => 'daily' ],
				null,
				'daily',
			],
		];
	}

	/**
	 * @dataProvider deposit_schedules_data_provider
	 */
	public function test_update_account_deposit_schedule( $request_params, $expected_updates, $initial_period = '' ) {

		$this->mock_wcpay_account->method( 'get_deposit_schedule_interval' )->willReturn( $initial_period );
		$this->mock_wcpay_account->method( 'is_stripe_connected' )->willReturn( true );

		$request = new WP_REST_Request();
		foreach ( $request_params as $key => $value ) {
			$request->set_param( $key, $value );
		}

		$this->mock_wcpay_account->expects( null === $expected_updates ? $this->never() : $this->once() )
			->method( 'update_stripe_account' )
			->with( $expected_updates );

		$this->controller->update_settings( $request );
	}


	/**
	 * @param bool $can_manage_woocommerce
	 *
	 * @return Closure
	 */
	private function create_can_manage_woocommerce_cap_override( bool $can_manage_woocommerce ) {
		return function ( $allcaps ) use ( $can_manage_woocommerce ) {
			$allcaps['manage_woocommerce'] = $can_manage_woocommerce;

			return $allcaps;
		};
	}

	/**
	 * Deregister WooCommerce Blocks REST routes to prevent _doing_it_wrong() notices
	 * after calls to rest_do_request().
	 */
	public function deregister_wc_blocks_rest_api() {
		try {
			/* For WooCommerce Blocks >= 2.6.0: */
			$wc_blocks_rest_api = Package::container()->get( RestApi::class );
			remove_action( 'rest_api_init', [ $wc_blocks_rest_api, 'register_rest_routes' ] );
		} catch ( Exception $e ) {
			/* For WooCommerce Blocks < 2.6.0: */
			remove_action( 'rest_api_init', [ RestApi::class, 'register_rest_routes' ] );
		}
	}

	public function test_upe_get_settings_card_eligible_flag() {
		$this->mock_localization_service->method( 'get_country_locale_data' )->willReturn(
			[
				'currency_code' => 'usd',
			]
		);
		$response = $this->upe_controller->get_settings();

		$this->assertArrayHasKey( 'is_card_present_eligible', $response->get_data() );
		$this->assertTrue( $response->get_data()['is_card_present_eligible'] );
	}

	public function test_upe_split_get_settings_card_eligible_flag() {
		$this->mock_localization_service->method( 'get_country_locale_data' )->willReturn(
			[
				'currency_code' => 'usd',
			]
		);
		$response = $this->upe_split_controller->get_settings();

		$this->assertArrayHasKey( 'is_card_present_eligible', $response->get_data() );
		$this->assertTrue( $response->get_data()['is_card_present_eligible'] );
	}

	public function test_upe_get_settings_domestic_currency(): void {
		$mock_domestic_currency = 'usd';
		$this->mock_localization_service->method( 'get_country_locale_data' )->willReturn(
			[
				'currency_code' => $mock_domestic_currency,
			]
		);
		$this->mock_wcpay_account
			->expects( $this->never() )
			->method( 'get_account_default_currency' );

		$response = $this->upe_controller->get_settings();

		$this->assertArrayHasKey( 'account_domestic_currency', $response->get_data() );
		$this->assertSame( $mock_domestic_currency, $response->get_data()['account_domestic_currency'] );
	}

	public function test_upe_get_settings_domestic_currency_fallbacks_to_default_currency(): void {
		$mock_domestic_currency = 'usd';
		$this->mock_localization_service->method( 'get_country_locale_data' )->willReturn( [] );
		$this->mock_wcpay_account
			->expects( $this->once() )
			->method( 'get_account_default_currency' )
			->willReturn( $mock_domestic_currency );
		$response = $this->upe_controller->get_settings();

		$this->assertArrayHasKey( 'account_domestic_currency', $response->get_data() );
		$this->assertSame( $mock_domestic_currency, $response->get_data()['account_domestic_currency'] );
	}

	public function test_upe_split_get_settings_domestic_currency(): void {
		$mock_domestic_currency = 'usd';
		$this->mock_localization_service->method( 'get_country_locale_data' )->willReturn(
			[
				'currency_code' => $mock_domestic_currency,
			]
		);
		$this->mock_wcpay_account
			->expects( $this->never() )
			->method( 'get_account_default_currency' );
		$response = $this->upe_split_controller->get_settings();

		$this->assertArrayHasKey( 'account_domestic_currency', $response->get_data() );
		$this->assertSame( $mock_domestic_currency, $response->get_data()['account_domestic_currency'] );
	}

	public function test_upe_split_get_settings_domestic_currency_fallbacks_to_default_currency(): void {
		$mock_domestic_currency = 'usd';
		$this->mock_localization_service->method( 'get_country_locale_data' )->willReturn( [] );
		$this->mock_wcpay_account
			->expects( $this->once() )
			->method( 'get_account_default_currency' )
			->willReturn( $mock_domestic_currency );
		$response = $this->upe_split_controller->get_settings();

		$this->assertArrayHasKey( 'account_domestic_currency', $response->get_data() );
		$this->assertSame( $mock_domestic_currency, $response->get_data()['account_domestic_currency'] );
	}

	/**
	 * Tests account business support address validator
	 *
	 * @dataProvider account_business_support_address_validation_provider
	 */
	public function test_validate_business_support_address( $value, $request, $param, $expected ) {
		$return = $this->controller->validate_business_support_address( $value, $request, $param );
		$this->assertEquals( $return, $expected );
	}

	/**
	 * Provider for test_validate_business_support_address.
	 * @return array[] test method params.
	 */
	public function account_business_support_address_validation_provider() {
		$request = new WP_REST_Request();
		return [
			[
				[
					'city'    => 'test city',
					'country' => 'US',
				],
				$request,
				'account_business_support_address',
				true,
			],
			[
				[
					'invalid_param' => 'value',
				],
				$request,
				'account_business_support_address',
				new WP_Error( 'rest_invalid_pattern', 'Error: Invalid address format!' ),
			],
		];
	}

	/**
	 * Tests account business support email validator
	 *
	 * @dataProvider account_business_support_email_validation_provider
	 */
	public function test_validate_business_support_email( $value, $request, $param, $expected ) {
		$return = $this->controller->validate_business_support_email_address( $value, $request, $param );
		$this->assertEquals( $return, $expected );
	}

	/**
	 * Provider for test_validate_business_support_email.
	 * @return array[] test method params.
	 */
	public function account_business_support_email_validation_provider() {
		$request = new WP_REST_Request();
		return [
			[
				'test@test.com',
				$request,
				'account_business_support_email',
				true,
			],
			[
				'', // Empty value should trigger error.
				$request,
				'account_business_support_email',
				true,
			],
			[
				'test@test',
				$request,
				'account_business_support_email',
				new WP_Error( 'rest_invalid_pattern', 'Error: Invalid email address: test@test' ),
			],
		];
	}

	/**
	 * Tests account business support phone validator
	 *
	 * @dataProvider account_business_support_phone_validation_provider
	 */
	public function test_validate_business_support_phone( $value, $request, $param, $expected ) {
		$return = $this->controller->validate_business_support_phone( $value, $request, $param );
		$this->assertEquals( $return, $expected );
	}

	/**
	 * Provider for test_validate_business_support_phone.
	 * @return array[] test method params.
	 */
	public function account_business_support_phone_validation_provider() {
		$request = new WP_REST_Request();
		return [
			[
				'123-123456',
				$request,
				'account_business_support_phone',
				true,
			],
			[
				'', // Empty value should be allowed.
				$request,
				'account_business_support_phone',
				true,
			],
			[
				'123test',
				$request,
				'account_business_support_phone',
				new WP_Error( 'rest_invalid_pattern', 'Error: Invalid phone number: 123test' ),
			],
		];
	}
}
