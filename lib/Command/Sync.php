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

class Sync extends Command {
	public function __construct(
		private SyncService $sync,
		private IUserManager $users,
	) {
		parent::__construct();
	}

	protected function configure(): void {
		$this->setName('googletwowaysync:sync')
			->setDescription('켠 구글 캘린더·연락처를 양방향 동기화합니다')
			->addArgument('user', InputArgument::REQUIRED, 'Nextcloud 사용자 ID')
			->addOption('preview', 'p', InputOption::VALUE_NONE, '아무것도 쓰지 않고 할 일만 보여줌')
			->addOption('legacy-token', null, InputOption::VALUE_NONE, '미리보기에 Google Synchronization의 읽기 전용 토큰을 씀')
			->addOption('collection', 'c', InputOption::VALUE_REQUIRED, '이 항목 id만 (꺼져 있어도)')
			->addOption('allow-mass-delete', null, InputOption::VALUE_NONE, '한 번에 많이 지우는 것을 허용')
			->addOption('details', 'd', InputOption::VALUE_NONE, '항목별 내용을 모두 출력');
	}

	protected function execute(InputInterface $input, OutputInterface $output): int {
		$userId = (string)$input->getArgument('user');
		if ($this->users->get($userId) === null) {
			$output->writeln('<error>없는 사용자입니다.</error>');
			return 1;
		}
		$preview = (bool)$input->getOption('preview');
		$legacy = (bool)$input->getOption('legacy-token');
		if ($legacy && !$preview) {
			$output->writeln('<error>--legacy-token은 --preview와 함께만 씁니다.</error>');
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
			'캘린더' => array_values(array_filter($result['reports'], static fn (array $r) => ($r['kind'] ?? '') === 'calendar')),
			'연락처' => array_values(array_filter($result['reports'], static fn (array $r) => ($r['kind'] ?? '') === 'contacts')),
		];
		$known = array_merge($groups['캘린더'], $groups['연락처']);
		$groups['기타'] = array_values(array_filter($result['reports'], static fn (array $r) => !in_array($r, $known, true)));
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
					$output->writeln('  … --details로 전체 보기');
				}
			}
		}
		$output->writeln(($preview ? '미리보기, ' : '') . '구글 API 요청 ' . $result['requests'] . '번');
		return $failed ? 2 : 0;
	}
}
