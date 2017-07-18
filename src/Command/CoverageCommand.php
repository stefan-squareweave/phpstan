<?php declare(strict_types = 1);

namespace PHPStan\Command;

use Nette\Configurator;
use Nette\DI\Config\Loader;
use Nette\DI\Extensions\ExtensionsExtension;
use Nette\DI\Extensions\PhpExtension;
use Nette\DI\Helpers;
use PhpParser\Node\Stmt\Catch_;
use PHPStan\File\FileHelper;
use PHPStan\Type\TypeCombinator;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\StyleInterface;

class CoverageCommand extends \Symfony\Component\Console\Command\Command
{

	const NAME = 'coverage';

	const DEFAULT_LEVEL = 0;

	protected function configure()
	{
		$this->setName(self::NAME)
			->setDescription('Produces a report of which parts of a file are type checked')
			->setDefinition([
				new InputArgument('paths', InputArgument::REQUIRED | InputArgument::IS_ARRAY, 'Paths with source code to run analysis on'),
				new InputOption('configuration', 'c', InputOption::VALUE_REQUIRED, 'Path to project configuration file'),
				new InputOption('autoload-file', 'a', InputOption::VALUE_OPTIONAL, 'Project\'s additional autoload file path'),
			]);
	}


	public function getAliases(): array
	{
		return [];
	}

	protected function execute(InputInterface $input, OutputInterface $output): int
	{
		$consoleStyle = new ErrorsConsoleStyle($input, $output);

		$currentWorkingDirectory = getcwd();
		$fileHelper = new FileHelper($currentWorkingDirectory);

		$autoloadFile = $input->getOption('autoload-file');
		if ($autoloadFile !== null && is_file($autoloadFile)) {
			$autoloadFile = $fileHelper->normalizePath($autoloadFile);
			if (is_file($autoloadFile)) {
				require_once $autoloadFile;
			}
		}

		$rootDir = $fileHelper->normalizePath(__DIR__ . '/../..');
		$confDir = $rootDir . '/conf';

		$parameters = [
			'rootDir' => $rootDir,
			'currentWorkingDirectory' => $currentWorkingDirectory,
			'cliArgumentsVariablesRegistered' => ini_get('register_argc_argv') === '1',
		];

		$projectConfigFile = $input->getOption('configuration');
		$configFiles = [$confDir . '/config.neon'];

		if ($projectConfigFile !== null) {
			if (!is_file($projectConfigFile)) {
				$output->writeln(sprintf('Project config file at path %s does not exist.', $projectConfigFile));
				return 1;
			}

			$configFiles[] = $projectConfigFile;

			$loader = new Loader();
			$projectConfig = $loader->load($projectConfigFile, null);
			if (isset($projectConfig['parameters']['tmpDir'])) {
				$tmpDir = Helpers::expand($projectConfig['parameters']['tmpDir'], $parameters);
			}
		}

		if (!isset($tmpDir)) {
			$tmpDir = sys_get_temp_dir() . '/phpstan';
			if (!@mkdir($tmpDir, 0777, true) && !is_dir($tmpDir)) {
				$consoleStyle->error(sprintf('Cannot create a temp directory %s', $tmpDir));
				return 1;
			}
		}

		$configurator = new Configurator();
		$configurator->defaultExtensions = [
			'php' => PhpExtension::class,
			'extensions' => ExtensionsExtension::class,
		];
		$configurator->setDebugMode(true);
		$configurator->setTempDirectory($tmpDir);

		foreach ($configFiles as $configFile) {
			$configurator->addConfig($configFile);
		}

		$parameters['tmpDir'] = $tmpDir;

		$configurator->addParameters($parameters);
		$container = $configurator->createContainer();
		$memoryLimitFile = $container->parameters['memoryLimitFile'];
		if (file_exists($memoryLimitFile)) {
			$consoleStyle->note(sprintf(
				"PHPStan crashed in the previous run probably because of excessive memory consumption.\nIt consumed around %s of memory.\n\nTo avoid this issue, increase the memory_limit directive in your php.ini file here:\n%s\n\nIf you can't or don't want to change the system-wide memory limit, run PHPStan like this:\n%s",
				file_get_contents($memoryLimitFile),
				php_ini_loaded_file(),
				sprintf('php -d memory_limit=XX %s', implode(' ', $_SERVER['argv']))
			));
			unlink($memoryLimitFile);
		}
		if (PHP_VERSION_ID >= 70100 && !property_exists(Catch_::class, 'types')) {
			$consoleStyle->note(
				'You\'re running PHP >= 7.1, but you still have PHP-Parser version 2.x. This will lead to parse errors in case you use PHP 7.1 syntax like nullable parameters, iterable and void typehints, union exception types, or class constant visibility. Update to PHP-Parser 3.x to dismiss this message.'
			);
		}

		foreach ($container->parameters['autoload_files'] as $autoloadFile) {
			require_once $fileHelper->normalizePath($autoloadFile);
		}

		if (count($container->parameters['autoload_directories']) > 0) {
			$robotLoader = new \Nette\Loaders\RobotLoader();

			$robotLoader->acceptFiles = '*.' . implode(', *.', $container->parameters['fileExtensions']);

			$robotLoader->setTempDirectory($tmpDir);
			foreach ($container->parameters['autoload_directories'] as $directory) {
				$robotLoader->addDirectory($fileHelper->normalizePath($directory));
			}

			$robotLoader->register();
		}

		TypeCombinator::setUnionTypesEnabled($container->parameters['checkUnionTypes']);

		/** @var \PHPStan\Command\CoverageApplication $application */
		$application = $container->getByType(CoverageApplication::class);
		return $this->handleReturn(
			$application->coverage(
				$input->getArgument('paths'),
				$consoleStyle
			),
			$memoryLimitFile
		);
	}

	private function handleReturn(int $code, string $memoryLimitFile): int
	{
		unlink($memoryLimitFile);
		return $code;
	}

	private function setUpSignalHandler(StyleInterface $consoleStyle, string $memoryLimitFile)
	{
		if (function_exists('pcntl_signal')) {
			pcntl_signal(SIGINT, function () use ($consoleStyle, $memoryLimitFile) {
				if (file_exists($memoryLimitFile)) {
					unlink($memoryLimitFile);
				}
				$consoleStyle->newLine();
				exit(1);
			});
		}
	}

}