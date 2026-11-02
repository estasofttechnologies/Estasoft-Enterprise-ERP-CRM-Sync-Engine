import { useEffect, useMemo, useRef } from '@wordpress/element';
import { __ } from '@wordpress/i18n';
import { api } from '../api/endpoints';
import type { BootData } from '../api/types';
import { FormatContext } from '../components/FormatContext';
import { useResource } from '../hooks/useResource';
import { AppBar } from './AppBar';
import { DisplaySettingsContext } from './DisplaySettingsContext';
import { AppContext } from './AppContext';
import { ErrorBoundary } from './ErrorBoundary';
import type { AppNotice } from './NoticeProvider';
import { NoticeArea, NoticeProvider } from './NoticeProvider';
import { Routes } from './Routes';
import { useRoute } from './router';
import { sectionOf } from './sections';
import { StatusProvider, useStatus } from './StatusProvider';

function bootNotices( boot: BootData ): AppNotice[] {
	return boot.notices.map( ( notice ) => ( {
		id: `boot-${ notice.id }`,
		kind: notice.kind,
		text: notice.text,
		actionLabel: notice.action_label,
		actionRoute: notice.action_route,
		dismissible: notice.dismissible,
	} ) );
}

function Frame( { boot, wrap }: { boot: BootData; wrap: boolean } ) {
	const route = useRoute();
	const { status } = useStatus();
	const settings = useResource(
		( signal ) => api.settings.get( signal ),
		[],
		{ enabled: boot.capabilities.configure }
	);
	const main = useRef< HTMLElement >( null );
	const first = useRef( true );

	// After a route change, move focus to the page heading so keyboard and screen reader users start at the top.
	useEffect( () => {
		if ( first.current ) {
			first.current = false;
			return;
		}
		const heading = main.current?.querySelector< HTMLElement >(
			'[data-page-heading]'
		);
		( heading ?? main.current )?.focus( { preventScroll: false } );
	}, [ route.path ] );

	const format = useMemo(
		() => ( {
			locale: boot.locale,
			timezone:
				settings.data?.display_time === 'utc'
					? 'UTC'
					: ( status?.store_timezone ?? boot.storeTimezone ),
			currency: status?.currency ?? boot.currency,
		} ),
		[
			boot.locale,
			boot.storeTimezone,
			boot.currency,
			settings.data?.display_time,
			status?.store_timezone,
			status?.currency,
		]
	);

	const section = sectionOf( route.segments );
	return (
		<DisplaySettingsContext.Provider
			value={ { refresh: () => void settings.reload() } }
		>
			<FormatContext.Provider value={ format }>
				<div
					className={ `estasoft-sync${ wrap ? ' wrap' : '' }` }
					data-color-scheme={ boot.wpAdminColorScheme }
				>
					<AppBar
						current={ section }
						status={ status }
						setup={ route.segments[ 0 ] === 'setup' }
					/>
					<div className="estasoft-sync-body">
						<NoticeArea />
						<main
							id="estasoft-sync-main"
							className="estasoft-sync-main"
							ref={ main }
							tabIndex={ -1 }
							aria-label={ __(
								'ESTASOFT SYNC',
								'estasoft-erp-crm-sync-engine'
							) }
						>
							<ErrorBoundary resetKey={ route.path }>
								<Routes route={ route } />
							</ErrorBoundary>
						</main>
					</div>
				</div>
			</FormatContext.Provider>
		</DisplaySettingsContext.Provider>
	);
}

/**
 * The admin app: providers for the boot data, notices and the polled status, then the frame
 * (app bar, message area, the screen for the current route).
 *
 * @param props      Boot data from the page, and whether to add the WordPress `wrap` class.
 * @param props.boot
 * @param props.wrap
 * @return The app.
 */
export function App( {
	boot,
	wrap = true,
}: {
	boot: BootData;
	wrap?: boolean;
} ) {
	const value = useMemo(
		() => ( {
			boot,
			canManage: boot.capabilities.manage,
			canConfigure: boot.capabilities.configure,
		} ),
		[ boot ]
	);
	return (
		<AppContext.Provider value={ value }>
			<NoticeProvider initial={ bootNotices( boot ) }>
				<StatusProvider>
					<Frame boot={ boot } wrap={ wrap } />
				</StatusProvider>
			</NoticeProvider>
		</AppContext.Provider>
	);
}
