<?php

declare(strict_types=1);

namespace OCA\GoogleTwoWaySync\Service;

/**
 * Decides whether a Nextcloud calendar name or color may follow Google.
 *
 * The "applied" values are what this app last wrote to Nextcloud. Google may
 * overwrite the local value only while it still equals the applied one, so a
 * rename or recolor in Nextcloud is kept. Pure: no Nextcloud calls, so it is
 * unit tested on its own. The same file lives in naver-synchronization.
 */
final class Appearance {
	/**
	 * Colors must already be normalized with normalizeColor(). An empty
	 * desired color means the remote has none and the local color is left alone.
	 *
	 * @return array{name: ?string, color: ?string, appliedName: string, appliedColor: ?string, changed: bool}
	 *   name/color are the values to write to Nextcloud (null = leave it),
	 *   changed tells whether the applied values must be stored.
	 */
	public static function decide(
		string $ncName,
		string $ncColor,
		?string $appliedName,
		?string $appliedColor,
		string $desiredName,
		string $desiredColor,
		bool $forceName = false,
		bool $forceColor = false,
	): array {
		$changed = false;
		// With nothing applied yet, assume the remote value was. Seeding with
		// the local value made a local rename look untouched and overwrote it.
		if ($appliedName === null) {
			$appliedName = $desiredName;
			$changed = true;
		}
		if ($appliedColor === null) {
			$appliedColor = $desiredColor !== '' ? $desiredColor : $ncColor;
			$changed = true;
		}

		$name = null;
		if ($forceName || ($ncName === $appliedName && $ncName !== $desiredName)) {
			$name = $desiredName;
		}
		if (($name !== null || $ncName === $desiredName) && $appliedName !== $desiredName) {
			$appliedName = $desiredName;
			$changed = true;
		}

		$color = null;
		if ($desiredColor !== '') {
			if ($forceColor || ($ncColor === $appliedColor && $ncColor !== $desiredColor)) {
				$color = $desiredColor;
			}
			if (($color !== null || $ncColor === $desiredColor) && $appliedColor !== $desiredColor) {
				$appliedColor = $desiredColor;
				$changed = true;
			}
		}

		return [
			'name' => $name,
			'color' => $color,
			'appliedName' => $appliedName,
			'appliedColor' => $appliedColor,
			'changed' => $changed,
		];
	}

	/**
	 * @return array{nameCustomized: bool, colorCustomized: bool}
	 */
	public static function customized(string $ncName, string $ncColor, string $desiredName, string $desiredColor): array {
		return [
			'nameCustomized' => $ncName !== $desiredName,
			'colorCustomized' => $desiredColor !== '' && $ncColor !== $desiredColor,
		];
	}

	/**
	 * Lowercase #rrggbb. #rgb is expanded and an alpha byte is dropped; other
	 * input is returned trimmed and lowercased.
	 */
	public static function normalizeColor(?string $color): string {
		$color = strtolower(trim((string)$color));
		if (preg_match('/^#([0-9a-f]{6})([0-9a-f]{2})?$/', $color, $m) === 1) {
			return '#' . $m[1];
		}
		if (preg_match('/^#([0-9a-f]{3})$/', $color, $m) === 1) {
			$h = $m[1];
			return '#' . $h[0] . $h[0] . $h[1] . $h[1] . $h[2] . $h[2];
		}
		return $color;
	}
}
