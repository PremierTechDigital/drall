<?php

namespace Drall\Commands;

use Drall\Drall;
use Drall\Models\Queue\Queue;
use Drall\Models\Queue\File;
use Drall\Models\Queue\Item;
use Drall\Models\RawCommand;
use Drall\Traits\RunnerAwareTrait;
use Drall\Runners\PassthruRunner;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * A command to execute a drush command on multiple sites.
 */
abstract class BaseExecCommand extends BaseCommand {

  use RunnerAwareTrait;

  /**
   * To be treated as the $argv array.
   *
   * @var array
   */
  protected array $argv;

  public function __construct(string $name = NULL) {
    parent::__construct($name);
    $this->argv = $GLOBALS['argv'];
    $this->setRunner(new PassthruRunner());
  }

  /**
   * Sets an array to be treated as $argv, mostly for testing.
   *
   * The $argv array contains:
   *   - Script name as the first parameter, i.e. drall.
   *   - The Drall command as the second parameter, e.g. exec.
   *   - Options for the Drall command, e.g. --drall-group=bluish.
   *   - The Drush command and its arguments, e.g. pmu devel
   *   - Options for the Drush command, e.g. --fields=site.
   *
   * @code
   * $command->setArgv([
   *   '/opt/drall/bin/drall',
   *   'exec',
   *   '--drall-group=bluish',
   *   'core:status',
   *   '--fields=site',
   * ]);
   * @endcode
   *
   * @param array $argv
   *   An array matching the $argv array format.
   *
   * @return self
   *   The command.
   */
  public function setArgv(array $argv): self {
    $this->argv = $argv;
    return $this;
  }

  protected function configure() {
    parent::configure();

    $this->addArgument(
      'cmd',
      InputArgument::REQUIRED | InputArgument::IS_ARRAY,
      'A drush command.'
    );

    $this->addOption(
      'drall-workers',
      NULL,
      InputOption::VALUE_OPTIONAL,
      'Number of commands to execute in parallel.',
      1,
    );

    $this->ignoreValidationErrors();
  }

  protected function doExecute(
    RawCommand $command,
    InputInterface $input,
    OutputInterface $output
  ): int {
    $siteGroup = $this->getDrallGroup($input);
    $placeholder = $this->getPlaceholderName($command);

    // Get all values for the placeholder.
    if ($placeholder === 'uri') {
      // @todo Should the keys of the $sites array be used instead?
      // @todo Can sites exist with sites/GROUP/SITE/settings.php?
      //   If yes, then does --uri=GROUP/SITE work correctly?
      $values = $this->siteDetector()->getSiteDirNames($siteGroup);
    }
    else {
      $values = $this->siteDetector()->getSiteAliasNames($siteGroup);
    }

    if (empty($values)) {
      $this->logger->warning('No Drupal sites found.');
      return 0;
    }

    // Prepare queue items for each value.
    $qData = new Queue(Drall::VERSION, $command, $placeholder);
    foreach ($values as $itemId) {
      $qData->push(new Item($itemId));
    }

    // If multiple workers are required.
    $w = $input->getOption('drall-workers');

    if ($w > 10) {
      $this->logger->warning('Limiting workers to 10, which is the maximum.');
      $w = 10;
    }

    if ($w > 1) {
      $this->logger->info("Executing with $w workers.");

      $qFile = new File(sys_get_temp_dir() . '/' . uniqid() . '.drallq.json');
      $qFile->write($qData);
      $this->logger->debug("Created queue: {$qFile->getPath()}");

      // Execute one command to launch all workers.
      // @example (cmd1 &) && (cmd2 &), and so on.
      $workerCommands = [];
      for ($i = 1; $i <= $w; $i++) {
        $workerCommands[] = "({$this->argv[0]} exec:queue '{$qFile->getPath()}' --drall-debug &)";
      }

      // If all workers are launched at the same time, they will compete to
      // acquire locks on the .drall.json and the output. Thus, we launch them
      // after 0.19s intervals.
      $all = implode(' && ', $workerCommands);
      $this->runner->execute($all);

      return 0;
    }

    $errorCodes = [];
    while ($item = $qData->next()) {
      // @todo Only show the full command in verbose mode.
      // @todo Show progress, i.e. current and total.
      $output->writeln("Current site: {$item->getId()}");
      $shellCommand = $command->with([$placeholder => $item->getId()]);
      $this->logger->debug("Running: $shellCommand");
      $exitCode = $this->runner->execute($shellCommand);

      if ($exitCode !== 0) {
        $errorCodes[$item->getId()] = $exitCode;
      }
    }

    if (empty($errorCodes)) {
      return 0;
    }

    // @todo Display summary of errors in verbose mode.
    return 1;
  }

  private function getPlaceholderName(RawCommand $command): ?string {
    $hasUri = $command->hasPlaceholder('uri');
    $hasSite = $command->hasPlaceholder('site');

    if ($hasUri && $hasSite) {
      $this->logger->error('The command cannot contain both @@uri and @@site placeholders.');
      return NULL;
    }

    if (!$hasUri && !$hasSite) {
      $this->logger->error('The command has no placeholders and it can be run without Drall.');
      return NULL;
    }

    if ($hasUri) {
      return 'uri';
    }

    return 'site';
  }

}
