/** @format */

/**
 * External dependencies
 */
import { render, screen, within } from '@testing-library/react';

/**
 * Internal dependencies
 */
import ExpressCheckoutSettings from '..';
import PaymentRequestButtonPreview from '../payment-request-button-preview';

jest.mock( '../../../data', () => ( {
	useSettings: jest.fn().mockReturnValue( {} ),
	usePaymentRequestEnabledSettings: jest
		.fn()
		.mockReturnValue( [ true, jest.fn() ] ),
	usePaymentRequestLocations: jest
		.fn()
		.mockReturnValue( [ [ true, true, true ], jest.fn() ] ),
	useWooPayEnabledSettings: jest.fn().mockReturnValue( [ true, jest.fn() ] ),
	useWooPayCustomMessage: jest.fn().mockReturnValue( [ 'test', jest.fn() ] ),
	useWooPayStoreLogo: jest.fn().mockReturnValue( [ 'test', jest.fn() ] ),
	usePaymentRequestButtonType: jest.fn().mockReturnValue( [ 'buy' ] ),
	usePaymentRequestButtonSize: jest.fn().mockReturnValue( [ 'default' ] ),
	usePaymentRequestButtonTheme: jest.fn().mockReturnValue( [ 'dark' ] ),
	useWooPayLocations: jest
		.fn()
		.mockReturnValue( [ [ true, true, true ], jest.fn() ] ),
} ) );

jest.mock( '@wordpress/data', () => ( {
	useDispatch: jest.fn( () => ( { createErrorNotice: jest.fn() } ) ),
} ) );

jest.mock( '../payment-request-button-preview' );
PaymentRequestButtonPreview.mockImplementation( () => '<></>' );

jest.mock( '@stripe/react-stripe-js', () => ( {
	Elements: jest.fn().mockReturnValue( null ),
} ) );
jest.mock( '@stripe/stripe-js', () => ( {
	loadStripe: jest.fn().mockReturnValue( null ),
} ) );

jest.mock( 'payment-request/utils', () => ( {
	getPaymentRequestData: jest.fn().mockReturnValue( {
		publishableKey: '123',
		accountId: '0001',
		locale: 'en',
	} ),
} ) );

describe( 'ExpressCheckoutSettings', () => {
	beforeEach( () => {
		global.wcpaySettings = {
			restUrl: 'http://example.com/wp-json/',
			featureFlags: {
				woopayExpressCheckout: true,
			},
		};
	} );

	test( 'renders banner at the top', () => {
		render( <ExpressCheckoutSettings methodId="payment_request" /> );

		const banner = screen.queryByAltText( 'WooPayments logo' );
		expect( banner ).toBeInTheDocument();
	} );

	test( 'renders error message for invalid method IDs', () => {
		render( <ExpressCheckoutSettings methodId="foo" /> );

		const errorMessage = screen.queryByText(
			'Invalid express checkout method ID specified.'
		);
		expect( errorMessage ).toBeInTheDocument();
	} );

	test( 'renders payment request breadcrumbs', () => {
		render( <ExpressCheckoutSettings methodId="payment_request" /> );

		const linkToPayments = screen.getByRole( 'link', {
			name: 'WooPayments',
		} );
		const breadcrumbs = linkToPayments.closest( 'h2' );

		const methodName = within( breadcrumbs ).getByText(
			'Apple Pay / Google Pay'
		);
		expect( breadcrumbs ).toContainElement( methodName );
	} );

	test( 'renders payment request title and description', () => {
		render( <ExpressCheckoutSettings methodId="payment_request" /> );

		const heading = screen.queryByRole( 'heading', {
			name: 'Settings',
		} );
		expect( heading ).toBeInTheDocument();
	} );

	test( 'renders payment request enable setting and confirm its checkbox label', () => {
		render( <ExpressCheckoutSettings methodId="payment_request" /> );

		const label = screen.getByRole( 'checkbox', {
			name: 'Enable Apple Pay / Google Pay',
		} );
		expect( label ).toBeInTheDocument();
	} );

	test( 'renders payment request general setting and confirm its first heading', () => {
		render( <ExpressCheckoutSettings methodId="payment_request" /> );

		expect(
			screen.queryByRole( 'heading', {
				name: 'Enable Apple Pay and Google Pay on selected pages',
			} )
		).toBeInTheDocument();
	} );

	test( 'renders woopay breadcrumbs', () => {
		render( <ExpressCheckoutSettings methodId="woopay" /> );

		const linkToPayments = screen.getByRole( 'link', {
			name: 'WooPayments',
		} );
		const breadcrumbs = linkToPayments.closest( 'h2' );

		const methodName = within( breadcrumbs ).getByText( 'WooPay' );
		expect( breadcrumbs ).toContainElement( methodName );
	} );

	test( 'renders woopay settings and confirm its checkbox label', () => {
		render( <ExpressCheckoutSettings methodId="woopay" /> );

		const label = screen.getByRole( 'checkbox', {
			name: 'Enable WooPay',
		} );
		expect( label ).toBeInTheDocument();
	} );

	test( 'renders WooPay express button appearance settings if feature flag is enabled and confirm its first heading', () => {
		render( <ExpressCheckoutSettings methodId="woopay" /> );

		expect(
			screen.queryByRole( 'heading', {
				name: 'Call to action',
			} )
		).toBeInTheDocument();
	} );

	test( 'does not render WooPay express button appearance settings if feature flag is disabled', () => {
		global.wcpaySettings.featureFlags.woopayExpressCheckout = false;

		render( <ExpressCheckoutSettings methodId="woopay" /> );

		expect(
			screen.queryByRole( 'heading', {
				name: 'Call to action',
			} )
		).not.toBeInTheDocument();
	} );
} );
