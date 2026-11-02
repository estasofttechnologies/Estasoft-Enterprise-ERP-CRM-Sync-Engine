/**
 * The Estasoft E mark, navy and white. Drawn inline so no image is fetched.
 *
 * @param props      Size in pixels.
 * @param props.size
 * @return The mark.
 */
export function BrandMark( { size = 24 }: { size?: number } ) {
	return (
		<svg
			className="estasoft-sync-mark"
			width={ size }
			height={ size }
			viewBox="0 0 375 375"
			aria-hidden="true"
			focusable="false"
		>
			<rect
				width="375"
				height="375"
				rx="84"
				fill="var(--estasoft-sync-primary)"
			/>
			<path
				fill="#fff"
				d="M 81.65 71.86 L 293.38 71.86 L 293.38 111.17 L 120.92 111.17 L 120.92 176.57 C 131.74 170.88 143.2 168.01 155.31 168.01 L 278.84 168.01 L 278.84 206.94 L 155.31 206.94 C 145.81 206.94 137.69 210.32 130.97 216.99 C 124.25 223.66 120.92 231.91 120.92 241.58 L 120.92 263.78 L 293.38 263.78 L 293.38 303.09 L 81.65 303.09 Z"
			/>
		</svg>
	);
}
