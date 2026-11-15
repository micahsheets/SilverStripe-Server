<?php

namespace App\Task;

use App\Model\RouteAuditLog;
use SilverStripe\Dev\BuildTask;
use SilverStripe\PolyExecution\PolyOutput;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;

class PruneAuditLogsTask extends BuildTask
{
    protected static string $commandName = 'PruneAuditLogsTask';

    protected string $title = 'Prune Old Audit Logs';

    protected static string $description = 'Deletes RouteAuditLog entries older than PANEL_AUDIT_RETENTION_DAYS (default: 30 days).';

    protected function execute(InputInterface $input, PolyOutput $output): int
    {
        $daysOpt = $input->getOption('days');
        $days = ($daysOpt !== null && is_numeric($daysOpt)) ? (int) $daysOpt : null;

        $deleted = RouteAuditLog::pruneOldLogs($days);
        $output->writeln("Pruned {$deleted} expired audit log record(s).");

        return Command::SUCCESS;
    }

    public function getOptions(): array
    {
        return [
            new InputOption('days', null, InputOption::VALUE_OPTIONAL, 'Retention period in days'),
        ];
    }
}
