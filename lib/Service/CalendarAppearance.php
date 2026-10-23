<?php

declare(strict_types=1);

namespace OCA\GoogleTwoWaySync\Service;

use OCA\DAV\CalDAV\CalDavBackend;
use OCA\GoogleTwoWaySync\Db\Collection;
use OCA\GoogleTwoWaySync\Db\CollectionMapper;
use Sabre\DAV\PropPatch;

/**
 * Applies Appearance decisions to a Nextcloud calendar. Shared by event
 * calendars and task list calendars; they differ only in the desired values.
 */
class CalendarAppearance {
	private const NAME = '{DAV:}displayname';
	private const COLOR = '{http://apple.com/ns/ical/}calendar-color';

	public function __construct(
		private CalDavBackend $caldav,
		private CollectionMapper $collections,
	) {
	}

	/**
	 * The calendar when it exists, belongs to the collection's user and is not
	 * in the trash.
	 *
	 * @return array<string, mixed>|null
	 */
	public function linked(Collection $col, int $ncId): ?array {
		if ($ncId <= 0) {
			return null;
		}
		$calendar = $this->caldav->getCalendarById($ncId);
		if (!is_array($calendar) || self::isInTrash($calendar)
			|| ($calendar['principaluri'] ?? '') !== 'principals/users/' . $col->getUserId()) {
			return null;
		}
		return $calendar;
	}

	/**
	 * @param array<string, mixed> $calendar
	 */
	public function apply(Collection $col, array $calendar, string $desiredName, string $desiredColor, bool $forceName = false, bool $forceColor = false): void {
		if (self::isInTrash($calendar)) {
			return;
		}
		$d = Appearance::decide(
			(string)($calendar[self::NAME] ?? ''),
			Appearance::normalizeColor($calendar[self::COLOR] ?? null),
			$col->getAppliedName(),
			$col->getAppliedColor() !== null ? Appearance::normalizeColor($col->getAppliedColor()) : null,
			$desiredName,
			$desiredColor,
			$forceName,
			$forceColor,
		);
		$patch = [];
		if ($d['name'] !== null) {
			$patch[self::NAME] = $d['name'];
		}
		if ($d['color'] !== null) {
			$patch[self::COLOR] = $d['color'];
		}
		if ($patch !== []) {
			$propPatch = new PropPatch($patch);
			$this->caldav->updateCalendar((int)$calendar['id'], $propPatch);
			$propPatch->commit();
		}
		if ($d['changed'] || $patch !== []) {
			$col->setAppliedName($d['appliedName']);
			$col->setAppliedColor($d['appliedColor']);
			$this->collections->update($col);
		}
	}

	/**
	 * @param array<string, mixed>|null $calendar
	 * @return array{nameCustomized: bool, colorCustomized: bool}
	 */
	public function flags(?array $calendar, string $desiredName, string $desiredColor): array {
		if ($calendar === null) {
			return ['nameCustomized' => false, 'colorCustomized' => false];
		}
		return Appearance::customized(
			(string)($calendar[self::NAME] ?? ''),
			Appearance::normalizeColor($calendar[self::COLOR] ?? null),
			$desiredName,
			$desiredColor,
		);
	}

	/**
	 * @param array<string, mixed> $calendar
	 */
	public static function isInTrash(array $calendar): bool {
		return CalendarTrash::isInTrash($calendar);
	}
}
