<?php
/**
 * Error catalog (30 codes), one place for the code, class, message template and what the person does.
 *
 * Generated from the design board Z04 and docs/error-catalog.md. Keep both in step.
 *
 * @package Estasoft\SyncEngine
 */

declare(strict_types=1);

namespace Estasoft\SyncEngine\Domain;

defined( 'ABSPATH' ) || exit;

/**
 * Every message the plugin shows, with a stable code. Placeholders such as {system} are filled in at run time.
 */
enum ErrorCode: string {
	case Auth001 = 'SYNC-AUTH-001';
	case Auth002 = 'SYNC-AUTH-002';
	case Net001 = 'SYNC-NET-001';
	case Net002 = 'SYNC-NET-002';
	case Rate001 = 'SYNC-RATE-001';
	case Map001 = 'SYNC-MAP-001';
	case Map002 = 'SYNC-MAP-002';
	case Map003 = 'SYNC-MAP-003';
	case Val001 = 'SYNC-VAL-001';
	case Val002 = 'SYNC-VAL-002';
	case Dup001 = 'SYNC-DUP-001';
	case State001 = 'SYNC-STATE-001';
	case Cfg001 = 'SYNC-CFG-001';
	case Cfg002 = 'SYNC-CFG-002';
	case Pause001 = 'SYNC-PAUSE-001';
	case Recon001 = 'SYNC-RECON-001';
	case Comp001 = 'SYNC-COMP-001';
	case Lock001 = 'SYNC-LOCK-001';
	case Stk001 = 'SYNC-STK-001';
	case Inb001 = 'SYNC-INB-001';
	case Auth003 = 'SYNC-AUTH-003';
	case Auth004 = 'SYNC-AUTH-004';
	case Cfg003 = 'SYNC-CFG-003';
	case Net003 = 'SYNC-NET-003';
	case Prv001 = 'SYNC-PRV-001';
	case Auth005 = 'SYNC-AUTH-005';
	case Auth006 = 'SYNC-AUTH-006';
	case Auth007 = 'SYNC-AUTH-007';
	case Exp001 = 'SYNC-EXP-001';
	case Exp002 = 'SYNC-EXP-002';
	case Cfg004 = 'SYNC-CFG-004';
	case Rest001 = 'SYNC-REST-001';
	case Rest002 = 'SYNC-REST-002';

	/**
	 * What the plugin does with this error.
	 */
	public function errorClass(): ErrorClass {
		return match ( $this ) {
			self::Net001, self::Net002, self::Rate001 => ErrorClass::Retry,
			self::Auth001, self::Auth002, self::Map001, self::Map002, self::Map003, self::Val001, self::Val002, self::Cfg001, self::Cfg002, self::Comp001, self::Auth003, self::Auth004, self::Cfg003, self::Net003, self::Auth005, self::Auth006, self::Auth007, self::Exp001, self::Exp002, self::Cfg004, self::Rest002 => ErrorClass::NeedsPerson,
			self::Dup001, self::State001, self::Lock001 => ErrorClass::Handled,
			self::Pause001, self::Recon001, self::Stk001, self::Inb001, self::Prv001, self::Rest001 => ErrorClass::Info,
		};
	}

	/**
	 * Message template with {placeholders}. Translate at the point of use.
	 *
	 * @return string
	 */
	public function template(): string {
		return match ( $this ) {
			self::Auth001 => '{system} refused the sign-in. Check the user and the API key.',
			self::Auth002 => 'The sign-in to {system} has expired. Reconnect.',
			self::Net001 => 'Couldn’t reach {system}, or it didn’t answer within 30 seconds.',
			self::Net002 => '{system} answered with a server error ({status}).',
			self::Rate001 => '{system} asked us to slow down. Waiting {n} seconds.',
			self::Map001 => 'Product {sku} was not found in {system}.',
			self::Map002 => 'Tax code not mapped for {country} {rate}.',
			self::Map003 => 'Customer email is missing on order #{order}.',
			self::Val001 => 'Buyer tax number must be 15 digits for Saudi Arabia.',
			self::Val002 => '{system} rejected a field: {field}. {reason}',
			self::Dup001 => '{system} already has this order. Linked to {ref}.',
			self::State001 => 'The order changed while it waited. The latest version was sent.',
			self::Cfg001 => 'There is no live mapping version for {type}.',
			self::Cfg002 => 'The encryption key is missing from wp-config.php.',
			self::Pause001 => 'Syncing is paused. Items wait in the queue.',
			self::Recon001 => 'The nightly check found {n} differences.',
			self::Comp001 => 'The tax authority rejected invoice {invoice}: {reason}.',
			self::Lock001 => 'The background task stopped while sending. The item was queued again.',
			self::Stk001 => 'Stock for {sku} is lower than units already sold.',
			self::Inb001 => 'An inbound message was refused: the signature or time was wrong.',
			self::Auth003 => 'The sign-in to {system} was cancelled or not completed.',
			self::Auth004 => '{system} does not allow this user to {action}.',
			self::Cfg003 => '{system} has no database called {name}.',
			self::Net003 => 'The security certificate of {system} could not be checked.',
			self::Prv001 => 'The preview could not build this order: {reason}.',
			self::Auth005 => 'The key or secret for {system} ran out on {date}.',
			self::Auth006 => 'Your {system} plan does not include outside access.',
			self::Auth007 => '{system} does not know this app yet.',
			self::Exp001 => 'The export file could not be written to the uploads folder.',
			self::Exp002 => '{n} orders were left out of the export: {reason}.',
			self::Cfg004 => '{system} connects with a key, not with a sign-in page.',
			self::Rest001 => 'Something went wrong on the server. The details are in the log.',
			self::Rest002 => 'The test email could not be sent. WordPress did not accept the message.',
		};
	}

	/**
	 * What the person does about it (plain English).
	 */
	public function remedy(): string {
		return match ( $this ) {
			self::Auth001 => 'Edit the credentials and test again. The connection pauses and orders wait.',
			self::Auth002 => 'Press Reconnect',
			self::Net001 => 'Nothing, or check the connection',
			self::Net002 => 'Nothing',
			self::Rate001 => 'Nothing',
			self::Map001 => 'Create it in the system or map the SKU, then retry the group',
			self::Map002 => 'Choose a code in Tax mapping, then retry',
			self::Map003 => 'Add the email or skip the order',
			self::Val001 => 'Correct the number on the order, then retry',
			self::Val002 => 'Fix the field or the mapping, then retry',
			self::Dup001 => 'Nothing',
			self::State001 => 'Nothing',
			self::Cfg001 => 'Publish a mapping version',
			self::Cfg002 => 'Add the line shown and reload',
			self::Pause001 => 'Resume, or check the connection',
			self::Recon001 => 'Open Reconciliation',
			self::Comp001 => 'Fix the cause in the system, report again',
			self::Lock001 => 'Nothing',
			self::Stk001 => 'Accept the system quantity or hold',
			self::Inb001 => 'Check the secret',
			self::Auth003 => 'Press Sign in and approve',
			self::Auth004 => 'Give the user the missing access in {system}, then test again',
			self::Cfg003 => 'Check the name on the sign-in page',
			self::Net003 => 'Ask your host to fix the certificate',
			self::Prv001 => 'Fix the rule or the order, then preview again',
			self::Auth005 => 'Make a new key in {system} and paste it under Connections',
			self::Auth006 => 'Change the plan, or switch the API on, then test again',
			self::Auth007 => 'Finish the app setup inside {system}, then test again',
			self::Exp001 => 'Ask your host to make the uploads folder writable, then create the export again',
			self::Exp002 => 'Map the missing ledger or code, then export again',
			self::Cfg004 => 'Use the address and key form for this connection',
			self::Rest001 => 'Try again in a moment. If it keeps happening, copy the support report and ask for help',
			self::Rest002 => 'Check the email settings of this site, then send the test again',
		};
	}

	/**
	 * Whether the plugin retries by itself.
	 */
	public function retriesAutomatically(): bool {
		return match ( $this ) {
			self::Net001, self::Net002, self::Rate001, self::Lock001 => true,
			default => false,
		};
	}

	/**
	 * Whether this error is raised before any request is sent to the system.
	 */
	public function raisedBeforeSending(): bool {
		return match ( $this ) {
			self::Map002, self::Map003, self::Val001, self::Cfg001 => true,
			default => false,
		};
	}
}
