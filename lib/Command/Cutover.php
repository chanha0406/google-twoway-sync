<?php

declare(strict_types=1);

namespace OCA\GoogleTwoWaySync\Command;

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
			->setDescription('켠 항목의 Google Synchronization 가져오기 작업을 지우고 이 앱으로 넘깁니다')
			->addArgument('user', InputArgument::REQUIRED, 'Nextcloud 사용자 ID')
			->addOption('collection', 'c', InputOption::VALUE_REQUIRED, '이 항목 id만');
	}

	protected function execute(InputInterface $input, OutputInterface $output): int {
		$userId = (string)$input->getArgument('user');
		if ($this->users->get($userId) === null) {
			$output->writeln('<error>없는 사용자입니다.</error>');
			return 1;
		}
		$collection = $input->getOption('collection');
		$done = $this->sync->cutover($userId, $collection !== null ? (int)$collection : null);
		$output->writeln('기존 작업을 지운 항목: ' . ($done === [] ? '없음' : implode(', ', $done)));
		return 0;
	}
}
