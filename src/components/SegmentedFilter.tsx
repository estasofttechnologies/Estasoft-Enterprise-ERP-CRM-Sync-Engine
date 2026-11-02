export interface SegmentOption< V extends string > {
	value: V;
	label: string;
	count?: number;
}

export interface SegmentedFilterProps< V extends string > {
	label: string;
	value: V;
	options: SegmentOption< V >[];
	onChange: ( value: V ) => void;
}

/**
 * One choice at a time, with counts. Toggle buttons with `aria-pressed`.
 *
 * @param props          Options and handler.
 * @param props.label
 * @param props.value
 * @param props.options
 * @param props.onChange
 * @return The group.
 */
export function SegmentedFilter< V extends string >( {
	label,
	value,
	options,
	onChange,
}: SegmentedFilterProps< V > ) {
	return (
		<div
			className="estasoft-sync-segments"
			role="group"
			aria-label={ label }
		>
			{ options.map( ( option ) => (
				<button
					key={ option.value }
					type="button"
					className="estasoft-sync-segment"
					aria-pressed={ value === option.value }
					onClick={ () => onChange( option.value ) }
				>
					{ option.label }
					{ option.count !== undefined ? (
						<span className="estasoft-sync-segment__count">
							{ option.count }
						</span>
					) : null }
				</button>
			) ) }
		</div>
	);
}
