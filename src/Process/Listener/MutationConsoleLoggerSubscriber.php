<?php

declare(strict_types=1);

namespace Infection\Process\Listener;

use Infection\Differ\DiffColorizer;
use Infection\EventDispatcher\EventSubscriberInterface;
use Infection\Events\MutationTestingFinished;
use Infection\Events\MutationTestingStarted;
use Infection\Events\MutantProcessFinished;
use Infection\Mutant\MetricsCalculator;
use Infection\Process\MutantProcess;
use Infection\TestFramework\AbstractTestFrameworkAdapter;
use Symfony\Component\Console\Helper\ProgressBar;
use Symfony\Component\Console\Output\OutputInterface;

class MutationConsoleLoggerSubscriber implements EventSubscriberInterface
{
    const PAD_LENGTH = 8;
    const DOTS_PER_ROW = 50;

    /**
     * @var OutputInterface
     */
    private $output;

    /**
     * @var ProgressBar
     */
    private $progressBar;

    /**
     * @var MutantProcess[]
     */
    private $mutantProcesses = [];

    /**
     * @var MetricsCalculator
     */
    private $metricsCalculator;

    /**
     * @var bool
     */
    private $showMutations;
    /**
     * @var DiffColorizer
     */
    private $diffColorizer;

    /**
     * @var int
     */
    private $mutationCount = 0;

    private $callsCount = 0;

    public function __construct(OutputInterface $output, ProgressBar $progressBar, MetricsCalculator $metricsCalculator, DiffColorizer $diffColorizer, bool $showMutations)
    {
        $this->output = $output;
        $this->progressBar = $progressBar;
        $this->metricsCalculator = $metricsCalculator;
        $this->showMutations = $showMutations;
        $this->diffColorizer = $diffColorizer;

        $this->mutationCount = 0;
    }

    public function getSubscribedEvents()
    {
        return [
            MutationTestingStarted::class => [$this, 'onMutationTestingStarted'],
            MutationTestingFinished::class => [$this, 'onMutationTestingFinished'],
            MutantProcessFinished::class => [$this, 'onMutantProcessFinished'],
        ];
    }

    public function onMutationTestingStarted(MutationTestingStarted $event)
    {
        $this->callsCount = 0;
        $this->mutationCount = $event->getMutationCount();

        $this->output->writeln([
            '<killed>.</killed>: killed, '
            . '<escaped>M</escaped>: escaped, '
            . '<uncovered>S</uncovered>: uncovered, '
            . '<with-error>E</with-error>: fatal error, '
            . '<timeout>T</timeout>: timed out',
            ''
        ]);

//        $this->progressBar->start($this->mutationCount);
    }

    public function onMutantProcessFinished(MutantProcessFinished $event)
    {
//        $this->progressBar->advance();

        $this->callsCount++;
        $this->mutantProcesses[] = $event->getMutantProcess();

        $this->metricsCalculator->collect($event->getMutantProcess());

        $this->logDot($event->getMutantProcess());
    }

    private function logDot(MutantProcess $mutantProcess)
    {
        switch ($mutantProcess->getResultCode()) {
            case MutantProcess::CODE_KILLED:
                $this->output->write('<killed>.</killed>');
                break;
            case MutantProcess::CODE_NOT_COVERED:
                $this->output->write('<uncovered>S</uncovered>');
                break;
            case MutantProcess::CODE_ESCAPED:
                $this->output->write('<escaped>M</escaped>');
                break;
            case MutantProcess::CODE_TIMED_OUT:
                $this->output->write('<timeout>T</timeout>');
                break;
        }

        $remainder = $this->callsCount % self::DOTS_PER_ROW;
        $endOfRow = 0 === $remainder;
        $lastDot = $this->mutationCount === $this->callsCount;

        if ($lastDot && !$endOfRow) {
            $this->output->write(str_repeat(' ', self::DOTS_PER_ROW - $remainder));
        }

        if ($lastDot || $endOfRow) {
            $length = strlen((string) $this->mutationCount);
            $format = sprintf('   (%%%dd / %%%dd)', $length, $length);

            $this->output->write(sprintf($format, $this->callsCount, $this->mutationCount));

            if ($this->callsCount !== $this->mutationCount) {
                $this->output->writeln('');
            }
        }
    }

    public function onMutationTestingFinished(MutationTestingFinished $event)
    {
//        $this->progressBar->finish();
        // TODO [doc] write test -> run mutation for just this file. Should be 100%, 100%, 100%,

        $processes = $this->metricsCalculator->getEscapedMutantProcesses();

        if ($this->showMutations) {
            $this->showMutations($processes);
        }

        $this->showMetrics();
    }

    private function showMutations(array $processes)
    {
        foreach ($processes as $index => $mutantProcess) {
            $this->output->writeln([
                '',
                sprintf('%d) %s', $index + 1, get_class($mutantProcess->getMutant()->getMutation()->getMutator()))
            ]);
            $this->output->writeln($mutantProcess->getMutant()->getMutation()->getOriginalFilePath());
            $this->output->writeln($this->diffColorizer->colorize($mutantProcess->getMutant()->getDiff()));
        }
    }

    private function showMetrics()
    {
        $this->output->writeln(['', '']);
        $this->output->writeln('<options=bold>' . $this->metricsCalculator->getTotalMutantsCount() . '</options=bold> mutations were generated:');
        $this->output->writeln('<options=bold>' . $this->getPadded($this->metricsCalculator->getKilledCount()) . '</options=bold> mutants were killed');
        $this->output->writeln('<options=bold>' . $this->getPadded($this->metricsCalculator->getNotCoveredByTestsCount()) . '</options=bold> mutants were not covered by tests');
        $this->output->writeln('<options=bold>' . $this->getPadded($this->metricsCalculator->getEscapedCount()) . '</options=bold> covered mutants were not detected');
//        $this->output->writeln($this->getPadded($errorCount) . ' fatal errors were encountered'); // TODO
        $this->output->writeln('<options=bold>' . $this->getPadded($this->metricsCalculator->getTimedOutCount()) . '</options=bold> time outs were encountered');

        $this->output->writeln(['', 'Metrics:']);
        $this->output->writeln($this->addIndentation('Mutation Score Indicator (MSI): <options=bold>' . $this->metricsCalculator->getMutationScoreIndicator() . '%</options=bold>'));
        $this->output->writeln($this->addIndentation('Mutation Code Coverage: <options=bold>' . $this->metricsCalculator->getCoverageRate() . '%</options=bold>'));
        $this->output->writeln($this->addIndentation('Covered Code MSI: <options=bold>' . $this->metricsCalculator->getCoveredCodeMutationScoreIndicator() . '%</options=bold>'));

        $this->output->writeln('');
        $this->output->writeln('Please note that some mutants will inevitably be harmless (i.e. false positives).');
    }

    private function getPadded($subject, int $padLength = self::PAD_LENGTH): string
    {
        return str_pad((string) $subject, $padLength, ' ', STR_PAD_LEFT);
    }

    private function addIndentation(string $string): string
    {
        return str_repeat(' ', self::PAD_LENGTH + 1) . $string;
    }
}