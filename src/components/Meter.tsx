/**
 * A horizontal bar with a visible number. The bar is decorative; the number carries the value.
 *
 * @param props       Label, value, maximum and tone.
 * @param props.label
 * @param props.value
 * @param props.max
 * @param props.tone
 * @return The row.
 */
export function Meter( {
	label,
	value,
	max,
	tone = 'primary',
}: {
	label: string;
	value: number;
	max: number;
	tone?: 'primary' | 'queued' | 'retrying' | 'failed';
} ) {
	const width =
		max > 0
			? Math.max( value > 0 ? 2 : 0, Math.round( ( value / max ) * 100 ) )
			: 0;
	return (
		<div className={ `estasoft-sync-meter estasoft-sync-meter--${ tone }` }>
			<span>{ label }</span>
			<span className="estasoft-sync-meter__bar" aria-hidden="true">
				<span style={ { inlineSize: `${ width }%` } } />
			</span>
			<span className="estasoft-sync-meter__value">{ value }</span>
		</div>
	);
}

/**
 * A progress bar for a task with a known length.
 *
 * @param props       Label and fraction from 0 to 1.
 * @param props.label
 * @param props.value
 * @return The progress element.
 */
export function ProgressBar( {
	label,
	value,
}: {
	label: string;
	value: number;
} ) {
	const percent = Math.round( Math.min( 1, Math.max( 0, value ) ) * 100 );
	return (
		<div className="estasoft-sync-progress">
			<progress value={ percent } max={ 100 } aria-label={ label } />
			<span className="estasoft-sync-muted" aria-hidden="true">
				{ percent }%
			</span>
		</div>
	);
}
