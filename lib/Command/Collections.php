<?php

declare(strict_types=1);

namespace OCA\GoogleTwoWaySync\Command;

use OCA\GoogleTwoWaySync\L10n\T;

use OCA\GoogleTwoWaySync\Db\CollectionMapper;
use OCA\GoogleTwoWaySync\Service\SyncService;
use OCP\IUserManager;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Helper\Table;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

class Collections extends Command {
	public function __construct(
		private SyncService $sync,
		private CollectionMapper $collections,
		private IUserManager $users,
	) {
		parent::__construct();
	}

	protected function configure(): void {
		$this->setName('googletwowaysync:collections')
			->setDescription(T::t('List Google calendars and contacts to sync, and turn them on or off'))
			->addArgument('user', InputArgument::REQUIRED, T::t('Nextcloud user id'))
			->addOption('refresh', null, InputOption::VALUE_NONE, T::t('Read the calendar list from Google again'))
			->addOption('enable-legacy', null, InputOption::VALUE_NONE, T::t('Turn on the calendars and address book that Google Synchronization is importing'))
			->addOption('enable', null, InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY, T::t('Item id to turn on'))
			->addOption('disable', null, InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY, T::t('Item id to turn off'));
	}

	protected function execute(InputInterface $input, OutputInterface $output): int {
		$userId = (string)$input->getArgument('user');
		if ($this->users->get($userId) === null) {
			$output->writeln('<error>' . T::t('Unknown user.') . '</error>');
			return 1;
		}
		try {
			if ($input->getOption('refresh')) {
				$this->sync->refreshCollections($userId);
			}
			if ($input->getOption('enable-legacy')) {
				$names = $this->sync->enableLegacy($userId);
				$output->writeln(T::t('Enabled: %s', [$names === [] ? T::t('none') : implode(', ', $names)]));
			}
			foreach (['enable' => 1, 'disable' => 0] as $option => $value) {
				foreach ((array)$input->getOption($option) as $id) {
					$col = $this->collections->findForUser($userId, (int)$id);
					if ($col === null) {
						$output->writeln('<error>' . T::t('Unknown item: %s', [$id]) . '</error>');
						return 1;
					}
					$col->setEnabled($value);
					$this->collections->update($col);
				}
			}
		} catch (\Throwable $e) {
			$output->writeln('<error>' . $e->getMessage() . '</error>');
			return 1;
		}

		$state = $this->sync->state($userId);
		$output->writeln(T::t('Google connection: %s', [$state['connected'] ? T::t('yes') : T::t('no')]) . ($state['legacyToken'] ? ' ' . T::t('(Google Synchronization read token present)') : ''));
		$output->writeln('Google Synchronization: ' . ($state['legacyAppEnabled'] ? T::t('on') : T::t('off')));
		$this->renderTable($output, T::t('Calendars'), array_values(array_filter(
			$state['collections'],
			static fn (array $c) => $c['kind'] === 'calendar',
		)), true);
		$this->renderTable($output, T::t('Contacts'), array_values(array_filter(
			$state['collections'],
			static fn (array $c) => $c['kind'] === 'contacts',
		)), true);
		$this->renderTable($output, T::t('Tasks'), array_values(array_filter(
			$state['collections'],
			static fn (array $c) => $c['kind'] === 'tasks',
		)), true);
		return 0;
	}

	/**
	 * @param list<array<string, mixed>> $rows
	 */
	private function renderTable(OutputInterface $output, string $title, array $rows, bool $showDirection): void {
		$output->writeln('');
		$output->writeln($title);
		$table = new Table($output);
		$headers = ['id', T::t('Name')];
		if ($showDirection) {
			$headers[] = T::t('Direction');
		}
		$headers[] = T::t('On');
		$headers[] = T::t('Legacy job');
		$headers[] = T::t('Last result');
		$table->setHeaders($headers);
		foreach ($rows as $c) {
			$row = [$c['id'], $c['name']];
			if ($showDirection) {
				$row[] = $c['writable'] ? T::t('Two-way') : T::t('Read only');
			}
			$row[] = $c['enabled'] ? 'O' : '';
			$row[] = $c['legacyJob'] ? T::t('Running') : '';
			$row[] = trim((string)$c['lastSummary'] . ($c['lastError'] ? T::t(' / error: %s', [strtok((string)$c['lastError'], "\n")]) : ''));
			$table->addRow($row);
		}
		if ($rows === []) {
			$output->writeln(T::t('none'));
			return;
		}
		$table->render();
	}
}
