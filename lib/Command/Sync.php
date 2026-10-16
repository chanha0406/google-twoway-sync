<?php

declare(strict_types=1);

namespace OCA\GoogleTwoWaySync\Command;

use OCA\GoogleTwoWaySync\L10n\T;

use OCA\GoogleTwoWaySync\Service\SyncService;
use OCP\IUserManager;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

class Sync extends Command {
	public function __construct(
		private SyncService $sync,
		private IUserManager $users,
	) {
		parent::__construct();
	}

	protected function configure(): void {
		$this->setName('googletwowaysync:sync')
			->setDescription(T::t('Sync enabled Google calendars and contacts in both directions'))
			->addArgument('user', InputArgument::REQUIRED, T::t('Nextcloud user id'))
			->addOption('preview', 'p', InputOption::VALUE_NONE, T::t('Show what would change without writing anything'))
			->addOption('legacy-token', null, InputOption::VALUE_NONE, T::t('Use the Google Synchronization read-only token for the preview'))
			->addOption('collection', 'c', InputOption::VALUE_REQUIRED, T::t('Only this item id (even if it is off)'))
			->addOption('allow-mass-delete', null, InputOption::VALUE_NONE, T::t('Allow deleting many items at once'))
			->addOption('details', 'd', InputOption::VALUE_NONE, T::t('Print every item'));
	}

	protected function execute(InputInterface $input, OutputInterface $output): int {
		$userId = (string)$input->getArgument('user');
		if ($this->users->get($userId) === null) {
			$output->writeln('<error>' . T::t('Unknown user.') . '</error>');
			return 1;
		}
		$preview = (bool)$input->getOption('preview');
		$legacy = (bool)$input->getOption('legacy-token');
		if ($legacy && !$preview) {
			$output->writeln('<error>' . T::t('--legacy-token can only be used together with --preview.') . '</error>');
			return 1;
		}
		$collection = $input->getOption('collection');
		try {
			$result = $this->sync->syncUser(
				$userId,
				$preview,
				$legacy,
				(bool)$input->getOption('allow-mass-delete'),
				$collection !== null ? (int)$collection : null,
			);
		} catch (\Throwable $e) {
			$output->writeln('<error>' . $e->getMessage() . '</error>');
			return 1;
		}
		$failed = false;
		$groups = [
			T::t('Calendars') => array_values(array_filter($result['reports'], static fn (array $r) => ($r['kind'] ?? '') === 'calendar')),
			T::t('Contacts') => array_values(array_filter($result['reports'], static fn (array $r) => ($r['kind'] ?? '') === 'contacts')),
		];
		$known = array_merge($groups[T::t('Calendars')], $groups[T::t('Contacts')]);
		$groups[T::t('Other')] = array_values(array_filter($result['reports'], static fn (array $r) => !in_array($r, $known, true)));
		foreach ($groups as $title => $reports) {
			if ($reports === []) {
				continue;
			}
			$output->writeln('<comment>' . $title . '</comment>');
			foreach ($reports as $report) {
				$output->writeln('<info>' . $report['name'] . '</info>: ' . $report['summary']);
				foreach ($report['errors'] as $error) {
					$failed = true;
					$output->writeln('  <error>' . $error . '</error>');
				}
				$lines = $report['lines'];
				if (!$input->getOption('details')) {
					$lines = array_slice($lines, 0, 10);
				}
				foreach ($lines as $line) {
					$output->writeln('  ' . $line);
				}
				if (!$input->getOption('details') && count($report['lines']) > 10) {
					$output->writeln('  ' . T::t('… pass --details to see everything'));
				}
			}
		}
		$output->writeln(($preview ? T::t('Preview, ') : '') . T::t('Google API requests: %s', [$result['requests']]));
		return $failed ? 2 : 0;
	}
}
