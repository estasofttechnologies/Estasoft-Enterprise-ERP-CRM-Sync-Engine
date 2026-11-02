// Generated from the design board Z04. Keep in step with includes/Domain/ErrorCode.php and docs/error-catalog.md.
export type ErrorClass = 'retry' | 'needs_person' | 'handled' | 'info';

export interface ErrorInfo {
	code: string;
	class: ErrorClass;
	message: string;
	remedy: string;
	retries: boolean;
}

export const ERROR_CATALOG: Record< string, ErrorInfo > = {
	'SYNC-AUTH-001': {
		code: 'SYNC-AUTH-001',
		class: 'needs_person',
		message:
			'{system} refused the sign-in. Check the user and the API key.',
		remedy: 'Edit the credentials and test again. The connection pauses and orders wait.',
		retries: false,
	},
	'SYNC-AUTH-002': {
		code: 'SYNC-AUTH-002',
		class: 'needs_person',
		message: 'The sign-in to {system} has expired. Reconnect.',
		remedy: 'Press Reconnect',
		retries: false,
	},
	'SYNC-NET-001': {
		code: 'SYNC-NET-001',
		class: 'retry',
		message:
			'Couldn\u2019t reach {system}, or it didn\u2019t answer within 30 seconds.',
		remedy: 'Nothing, or check the connection',
		retries: true,
	},
	'SYNC-NET-002': {
		code: 'SYNC-NET-002',
		class: 'retry',
		message: '{system} answered with a server error ({status}).',
		remedy: 'Nothing',
		retries: true,
	},
	'SYNC-RATE-001': {
		code: 'SYNC-RATE-001',
		class: 'retry',
		message: '{system} asked us to slow down. Waiting {n} seconds.',
		remedy: 'Nothing',
		retries: true,
	},
	'SYNC-MAP-001': {
		code: 'SYNC-MAP-001',
		class: 'needs_person',
		message: 'Product {sku} was not found in {system}.',
		remedy: 'Create it in the system or map the SKU, then retry the group',
		retries: false,
	},
	'SYNC-MAP-002': {
		code: 'SYNC-MAP-002',
		class: 'needs_person',
		message: 'Tax code not mapped for {country} {rate}.',
		remedy: 'Choose a code in Tax mapping, then retry',
		retries: false,
	},
	'SYNC-MAP-003': {
		code: 'SYNC-MAP-003',
		class: 'needs_person',
		message: 'Customer email is missing on order #{order}.',
		remedy: 'Add the email or skip the order',
		retries: false,
	},
	'SYNC-VAL-001': {
		code: 'SYNC-VAL-001',
		class: 'needs_person',
		message: 'Buyer tax number must be 15 digits for Saudi Arabia.',
		remedy: 'Correct the number on the order, then retry',
		retries: false,
	},
	'SYNC-VAL-002': {
		code: 'SYNC-VAL-002',
		class: 'needs_person',
		message: '{system} rejected a field: {field}. {reason}',
		remedy: 'Fix the field or the mapping, then retry',
		retries: false,
	},
	'SYNC-DUP-001': {
		code: 'SYNC-DUP-001',
		class: 'handled',
		message: '{system} already has this order. Linked to {ref}.',
		remedy: 'Nothing',
		retries: false,
	},
	'SYNC-STATE-001': {
		code: 'SYNC-STATE-001',
		class: 'handled',
		message:
			'The order changed while it waited. The latest version was sent.',
		remedy: 'Nothing',
		retries: false,
	},
	'SYNC-CFG-001': {
		code: 'SYNC-CFG-001',
		class: 'needs_person',
		message: 'There is no live mapping version for {type}.',
		remedy: 'Publish a mapping version',
		retries: false,
	},
	'SYNC-CFG-002': {
		code: 'SYNC-CFG-002',
		class: 'needs_person',
		message: 'The encryption key is missing from wp-config.php.',
		remedy: 'Add the line shown and reload',
		retries: false,
	},
	'SYNC-PAUSE-001': {
		code: 'SYNC-PAUSE-001',
		class: 'info',
		message: 'Syncing is paused. Items wait in the queue.',
		remedy: 'Resume, or check the connection',
		retries: false,
	},
	'SYNC-RECON-001': {
		code: 'SYNC-RECON-001',
		class: 'info',
		message: 'The nightly check found {n} differences.',
		remedy: 'Open Reconciliation',
		retries: false,
	},
	'SYNC-COMP-001': {
		code: 'SYNC-COMP-001',
		class: 'needs_person',
		message: 'The tax authority rejected invoice {invoice}: {reason}.',
		remedy: 'Fix the cause in the system, report again',
		retries: false,
	},
	'SYNC-LOCK-001': {
		code: 'SYNC-LOCK-001',
		class: 'handled',
		message:
			'The background task stopped while sending. The item was queued again.',
		remedy: 'Nothing',
		retries: true,
	},
	'SYNC-STK-001': {
		code: 'SYNC-STK-001',
		class: 'info',
		message: 'Stock for {sku} is lower than units already sold.',
		remedy: 'Accept the system quantity or hold',
		retries: false,
	},
	'SYNC-INB-001': {
		code: 'SYNC-INB-001',
		class: 'info',
		message:
			'An inbound message was refused: the signature or time was wrong.',
		remedy: 'Check the secret',
		retries: false,
	},
	'SYNC-AUTH-003': {
		code: 'SYNC-AUTH-003',
		class: 'needs_person',
		message: 'The sign-in to {system} was cancelled or not completed.',
		remedy: 'Press Sign in and approve',
		retries: false,
	},
	'SYNC-AUTH-004': {
		code: 'SYNC-AUTH-004',
		class: 'needs_person',
		message: '{system} does not allow this user to {action}.',
		remedy: 'Give the user the missing access in {system}, then test again',
		retries: false,
	},
	'SYNC-CFG-003': {
		code: 'SYNC-CFG-003',
		class: 'needs_person',
		message: '{system} has no database called {name}.',
		remedy: 'Check the name on the sign-in page',
		retries: false,
	},
	'SYNC-NET-003': {
		code: 'SYNC-NET-003',
		class: 'needs_person',
		message: 'The security certificate of {system} could not be checked.',
		remedy: 'Ask your host to fix the certificate',
		retries: false,
	},
	'SYNC-PRV-001': {
		code: 'SYNC-PRV-001',
		class: 'info',
		message: 'The preview could not build this order: {reason}.',
		remedy: 'Fix the rule or the order, then preview again',
		retries: false,
	},
	'SYNC-AUTH-005': {
		code: 'SYNC-AUTH-005',
		class: 'needs_person',
		message: 'The key or secret for {system} ran out on {date}.',
		remedy: 'Make a new key in {system} and paste it under Connections',
		retries: false,
	},
	'SYNC-AUTH-006': {
		code: 'SYNC-AUTH-006',
		class: 'needs_person',
		message: 'Your {system} plan does not include outside access.',
		remedy: 'Change the plan, or switch the API on, then test again',
		retries: false,
	},
	'SYNC-AUTH-007': {
		code: 'SYNC-AUTH-007',
		class: 'needs_person',
		message: '{system} does not know this app yet.',
		remedy: 'Finish the app setup inside {system}, then test again',
		retries: false,
	},
	'SYNC-EXP-001': {
		code: 'SYNC-EXP-001',
		class: 'needs_person',
		message: 'The export file could not be written to the uploads folder.',
		remedy: 'Ask your host to make the uploads folder writable, then create the export again',
		retries: false,
	},
	'SYNC-EXP-002': {
		code: 'SYNC-EXP-002',
		class: 'needs_person',
		message: '{n} orders were left out of the export: {reason}.',
		remedy: 'Map the missing ledger or code, then export again',
		retries: false,
	},
	'SYNC-CFG-004': {
		code: 'SYNC-CFG-004',
		class: 'needs_person',
		message: '{system} connects with a key, not with a sign-in page.',
		remedy: 'Use the address and key form for this connection',
		retries: false,
	},
	'SYNC-REST-001': {
		code: 'SYNC-REST-001',
		class: 'info',
		message: 'Something went wrong on the server. The details are in the log.',
		remedy: 'Try again in a moment. If it keeps happening, copy the support report and ask for help',
		retries: false,
	},
	'SYNC-REST-002': {
		code: 'SYNC-REST-002',
		class: 'needs_person',
		message: 'The test email could not be sent. WordPress did not accept the message.',
		remedy: 'Check the email settings of this site, then send the test again',
		retries: false,
	},
};
