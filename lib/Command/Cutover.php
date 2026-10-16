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

class Cutover extends Command {
	public function __construct(
		private SyncService $sync,
		private IUserManager $users,
	) {
		parent::__construct();
	}

	protected function configure(): void {
		$this->setName('googletwowaysync:cutover')
			->setDescription(T::t('Remove Google Synchronization import jobs for enabled items and switch them to this app'))
			->addArgument('user', InputArgument::REQUIRED, T::t('Nextcloud user id'))
			->addOption('collection', 'c', InputOption::VALUE_REQUIRED, T::t('Only this item id'));
	}

	protected function execute(InputInterface $input, OutputInterface $output): int {
		$userId = (string)$input->getArgument('user');
		if ($this->users->get($userId) === null) {
			$output->writeln('<error>' . T::t('Unknown user.') . '</error>');
			return 1;
		}
		$collection = $input->getOption('collection');
		$done = $this->sync->cutover($userId, $collection !== null ? (int)$collection : null);
		$output->writeln(T::t('Removed legacy jobs from: %s', [$done === [] ? T::t('none') : implode(', ', $done)]));
		return 0;
	}
}
