<?php

declare(strict_types=1);

namespace OCA\GoogleTwoWaySync\Command;

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
			->setDescription('동기화할 구글 캘린더·연락처 목록을 보고 켜거나 끕니다')
			->addArgument('user', InputArgument::REQUIRED, 'Nextcloud 사용자 ID')
			->addOption('refresh', null, InputOption::VALUE_NONE, '구글에서 캘린더 목록을 다시 읽음')
			->addOption('enable-legacy', null, InputOption::VALUE_NONE, 'Google Synchronization이 지금 가져오는 캘린더·주소록을 켬')
			->addOption('enable', null, InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY, '켤 항목 id')
			->addOption('disable', null, InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY, '끌 항목 id');
	}

	protected function execute(InputInterface $input, OutputInterface $output): int {
		$userId = (string)$input->getArgument('user');
		if ($this->users->get($userId) === null) {
			$output->writeln('<error>없는 사용자입니다.</error>');
			return 1;
		}
		try {
			if ($input->getOption('refresh')) {
				$this->sync->refreshCollections($userId);
			}
			if ($input->getOption('enable-legacy')) {
				$names = $this->sync->enableLegacy($userId);
				$output->writeln('켬: ' . ($names === [] ? '없음' : implode(', ', $names)));
			}
			foreach (['enable' => 1, 'disable' => 0] as $option => $value) {
				foreach ((array)$input->getOption($option) as $id) {
					$col = $this->collections->findForUser($userId, (int)$id);
					if ($col === null) {
						$output->writeln('<error>없는 항목: ' . $id . '</error>');
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
		$output->writeln('구글 연결: ' . ($state['connected'] ? '됨' : '안 됨') . ($state['legacyToken'] ? ' (Google Synchronization 읽기 토큰 있음)' : ''));
		$output->writeln('Google Synchronization: ' . ($state['legacyAppEnabled'] ? '켜짐' : '꺼짐'));
		$this->renderTable($output, '캘린더', array_values(array_filter(
			$state['collections'],
			static fn (array $c) => $c['kind'] === 'calendar',
		)), true);
		$this->renderTable($output, '연락처', array_values(array_filter(
			$state['collections'],
			static fn (array $c) => $c['kind'] === 'contacts',
		)), true);
		$this->renderTable($output, '할 일', array_values(array_filter(
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
		$headers = ['id', '이름'];
		if ($showDirection) {
			$headers[] = '방향';
		}
		$headers[] = '켬';
		$headers[] = '기존 작업';
		$headers[] = '마지막 결과';
		$table->setHeaders($headers);
		foreach ($rows as $c) {
			$row = [$c['id'], $c['name']];
			if ($showDirection) {
				$row[] = $c['writable'] ? '양방향' : '읽기 전용';
			}
			$row[] = $c['enabled'] ? 'O' : '';
			$row[] = $c['legacyJob'] ? '실행 중' : '';
			$row[] = trim((string)$c['lastSummary'] . ($c['lastError'] ? ' / 오류: ' . strtok((string)$c['lastError'], "\n") : ''));
			$table->addRow($row);
		}
		if ($rows === []) {
			$output->writeln('없음');
			return;
		}
		$table->render();
	}
}
