import { __, sprintf } from '@wordpress/i18n';

export interface ImportGuide {
	title: string;
	steps: string[];
	/** One warning that matters for this format. */
	warning?: string;
}

/**
 * How to import a file in the program that reads it. The text is fixed per format and follows the
 * design (board D06) and the connection-methods spec; menu names are the ones the vendors document
 * or that community pages report, so the guide says where to look and does not promise a layout.
 *
 * @param connector Connector id of the export connection.
 * @param system    System name for sentences.
 * @return The guide.
 */
export function importGuideFor(
	connector: string,
	system: string
): ImportGuide {
	const id = connector.toLowerCase();
	if ( id.includes( 'tally' ) ) {
		return {
			title: __(
				'Import in TallyPrime',
				'estasoft-enterprise-erpcrm-sync-engine'
			),
			steps: [
				__(
					'Download the file above.',
					'estasoft-enterprise-erpcrm-sync-engine'
				),
				__(
					'Open your company in TallyPrime.',
					'estasoft-enterprise-erpcrm-sync-engine'
				),
				__(
					'Press Alt+O (Import), choose Transactions, type the path of the file, and press Enter. Tally asks whether to back up first.',
					'estasoft-enterprise-erpcrm-sync-engine'
				),
				__(
					'If Tally lists errors, read its exceptions report. A message that a master was not found means a ledger or stock item is missing in Tally; create it and import again.',
					'estasoft-enterprise-erpcrm-sync-engine'
				),
			],
			warning: __(
				'Import each file once. Tally has a setting that decides whether a voucher with the same ID is overwritten or created again, and the default can create it twice.',
				'estasoft-enterprise-erpcrm-sync-engine'
			),
		};
	}
	if ( id.includes( 'datev' ) ) {
		return {
			title: __(
				'Import in DATEV',
				'estasoft-enterprise-erpcrm-sync-engine'
			),
			steps: [
				__(
					'Download the file above.',
					'estasoft-enterprise-erpcrm-sync-engine'
				),
				__(
					'Give it to your tax adviser, or import it yourself in DATEV Rechnungswesen: Bestand, Importieren, Stapelverarbeitung.',
					'estasoft-enterprise-erpcrm-sync-engine'
				),
				__(
					'If DATEV refuses the file, check that the account numbers and the fiscal year match your DATEV setup.',
					'estasoft-enterprise-erpcrm-sync-engine'
				),
			],
			warning: __(
				'Never open a DATEV file in Excel and save it. Excel changes the first line and DATEV then refuses the file.',
				'estasoft-enterprise-erpcrm-sync-engine'
			),
		};
	}
	if ( id.includes( 'sage50' ) || id.includes( 'sage-50' ) ) {
		return {
			title: __(
				'Import in Sage 50',
				'estasoft-enterprise-erpcrm-sync-engine'
			),
			steps: [
				__(
					'Download the file above.',
					'estasoft-enterprise-erpcrm-sync-engine'
				),
				__(
					'In Sage 50 (UK), choose File, then Import. In Sage 50 US and Canada, choose File, then Select Import/Export.',
					'estasoft-enterprise-erpcrm-sync-engine'
				),
				__(
					'Choose the file and follow the import steps.',
					'estasoft-enterprise-erpcrm-sync-engine'
				),
				__(
					'If Sage lists errors, a customer account, nominal code or tax code in the file does not exist yet. Create it in Sage 50 and import again.',
					'estasoft-enterprise-erpcrm-sync-engine'
				),
			],
			warning: __(
				'Import each file once. Importing the same file twice can create the entries twice.',
				'estasoft-enterprise-erpcrm-sync-engine'
			),
		};
	}
	return {
		title: sprintf(
			/* translators: %s: system name */
			__( 'Import in %s', 'estasoft-enterprise-erpcrm-sync-engine' ),
			system
		),
		steps: [
			__(
				'Download the file above.',
				'estasoft-enterprise-erpcrm-sync-engine'
			),
			sprintf(
				/* translators: %s: system name */
				__(
					'Open the import function of %s and choose the file.',
					'estasoft-enterprise-erpcrm-sync-engine'
				),
				system
			),
			__(
				'If the program lists errors, something named in the file does not exist there yet. Create it and import again.',
				'estasoft-enterprise-erpcrm-sync-engine'
			),
		],
		warning: __(
			'Import each file once. Importing the same file twice can create the entries twice.',
			'estasoft-enterprise-erpcrm-sync-engine'
		),
	};
}
