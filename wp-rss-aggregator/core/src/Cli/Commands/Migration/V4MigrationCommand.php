<?php

declare(strict_types=1);

namespace RebelCode\Aggregator\Core\Cli\Commands\Migration;

use WP_CLI;
use RebelCode\Aggregator\Core\V4\V4Migrator;
use RebelCode\Aggregator\Core\Cli\BaseCommand;

/**
 * Handles V4 migration.
 */
class V4MigrationCommand extends BaseCommand
{
    /**
     * The V4 migrator instance.
     *
     * @var V4Migrator
     */
    protected $migrator;

    /**
     * Constructor.
     *
     * @param V4Migrator $migrator The V4 migrator instance.
     */
    public function __construct(V4Migrator $migrator)
    {
        // Ensure WP_CLI is available
        if (!class_exists('WP_CLI')) {
            // This should ideally not happen if the command is invoked via WP-CLI.
            // However, it's a good safeguard.
            error_log('WP_CLI class not found. This command must be run via WP-CLI.'); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
            throw new \RuntimeException('WP_CLI not available.');
        }
        parent::__construct(new \RebelCode\Aggregator\Core\Cli\WpCliIo());
        $this->migrator = $migrator;
    }

    /**
     * Runs the full V4 migration process from the V4 plugin structure to the V5 structure.
     *
     * This comprehensive command executes all necessary steps to migrate data from the old
     * plugin version. It is designed to be run once during the upgrade process.
     *
     * The migration follows a specific sequence:
     * 1. **Load Objects**: Ensures Custom Post Types (CPTs) and taxonomies required for V5 are registered.
     * 2. **Migrate Settings**: Converts global plugin settings to the new V5 format.
     * 3. **Migrate Sources**: Transforms V4 feed sources (wprss_feed CPT) into the V5 source structure.
     * 4. **Migrate Blacklist**: Converts V4 blacklist entries (wprss_blacklist CPT) to the V5 reject list.
     * 5. **Migrate Templates**: Migrates V4 display templates (wprss_feed_template CPT) to V5 display objects.
     * 6. **Migrate Items**: Updates metadata for imported feed items to align with V5 conventions.
     * 7. **Deactivate Add-ons**: Deactivates known V4 add-ons, as their functionality is typically integrated
     *    or replaced in V5. This step is skipped if `--dry-run` is used.
     *
     * ## OPTIONS
     *
     * [--yes]
     * : Bypass the initial confirmation prompt. Use this flag to run the command
     *   non-interactively, for example, in automated scripts. Without this flag,
     *   you will be asked to confirm before the migration begins.
     *
     * [--dry-run]
     * : Simulate the entire migration process without making any actual changes to the
     *   database or filesystem. This is useful for testing the migration flow and
     *   identifying potential issues beforehand. When `--dry-run` is active:
     *     - No data will be written or altered.
     *     - V4 add-on deactivation will be skipped.
     *     - The command will output logs as if it were performing the migration.
     *
     * ## EXAMPLES
     *
     *     # Run the full migration, with a confirmation prompt
     *     wp rss v4_migration run_all
     *
     *     # Run the full migration, automatically confirming the initial prompt
     *     wp rss v4_migration run_all --yes
     *
     *     # Perform a dry run of the migration to see what would happen
     *     wp rss v4_migration run_all --dry-run
     *
     *     # Perform a dry run, automatically confirming the initial prompt (though less common for dry runs)
     *     wp rss v4_migration run_all --yes --dry-run
     *
     * @subcommand run_all
     * @param array $args Positional arguments.
     * @param array $assoc_args Associative arguments.
     */
    public function run_all($args, $assoc_args) // phpcs:ignore VariableAnalysis.CodeAnalysis.VariableAnalysis.UnusedVariable
    {
        if (!WP_CLI\Utils\get_flag_value($assoc_args, 'yes', false)) {
            if (!$this->confirm('Are you sure you want to run the full V4 migration? This will deactivate V4 add-ons and migrate data.')) {
                WP_CLI::log('Migration aborted by user.');
                return;
            }
        }

        $is_dry_run = WP_CLI\Utils\get_flag_value($assoc_args, 'dry-run', false);

        if ($is_dry_run) {
            WP_CLI::line(WP_CLI::colorize('%YStarting V4 migration (Dry Run)...%n'));
        } else {
            WP_CLI::line(WP_CLI::colorize('%YStarting V4 migration...%n'));
        }

        try {
            $this->migrator->loadObjects();
            WP_CLI::log('Ensured CPTs and taxonomies are registered.');

            $sub_command_assoc_args = ['yes' => true];
            if ($is_dry_run) {
                $sub_command_assoc_args['dry-run'] = true;
            }

            // Sequentially run individual migration steps
            $this->run_settings([], $sub_command_assoc_args);
            $this->run_sources([], $sub_command_assoc_args);
            $this->run_blacklist([], $sub_command_assoc_args);
            $this->run_templates([], $sub_command_assoc_args);
            $this->run_items([], $sub_command_assoc_args);

            if ($is_dry_run) {
                WP_CLI::log('Dry run: Skipped deactivation of V4 add-ons.');
            } else {
                $deactivated = $this->migrator->deactivateAddons();
                if (!empty($deactivated)) {
                    WP_CLI::log('Deactivated V4 add-ons: ' . implode(', ', $deactivated));
                } else {
                    WP_CLI::log('No V4 add-ons were active or needed deactivation.');
                }
            }

            WP_CLI::success($is_dry_run ? 'V4 migration (Dry Run) completed.' : 'V4 migration completed.');
        } catch (\Exception $e) {
            $this->printCliException($e);
            WP_CLI::error($is_dry_run ? 'V4 migration (Dry Run) failed due to an unexpected error.' : 'V4 migration failed due to an unexpected error.');
        }
    }

    /**
     * Migrates V4 settings.
     *
     * ## OPTIONS
     *
     * [--yes]
     * : Answer yes to the confirmation prompt.
     * [--dry-run]
     * : Perform a dry run without actually changing data.
     *
     * ## EXAMPLES
     *
     *     wp rss v4_migration run_settings
     *     wp rss v4_migration run_settings --yes
     *     wp rss v4_migration run_settings --dry-run
     *
     * @subcommand run_settings
     * @param array $args Positional arguments.
     * @param array $assoc_args Associative arguments.
     */
    public function run_settings($args, $assoc_args) // phpcs:ignore VariableAnalysis.CodeAnalysis.VariableAnalysis.UnusedVariable
    {
        if (!WP_CLI\Utils\get_flag_value($assoc_args, 'yes', false)) {
            if (!$this->confirm('Are you sure you want to migrate V4 settings?')) {
                WP_CLI::log('Settings migration aborted by user.');
                return;
            }
        }

        $is_dry_run = WP_CLI\Utils\get_flag_value($assoc_args, 'dry-run', false);

        try {
            if ($is_dry_run) {
                WP_CLI::line(WP_CLI::colorize('%CMigrating settings (Dry Run)...%n'));
                $this->migrator->settings->migrate(true);
                WP_CLI::success('Settings migration (Dry Run) completed. No actual changes were made.');
            } else {
                WP_CLI::line(WP_CLI::colorize('%CMigrating settings...%n'));
                $this->migrator->settings->migrate(false);
                WP_CLI::success('Settings migrated successfully.');
            }
        } catch (\Exception $e) {
            $this->printCliException($e);
            $message = $is_dry_run
                ? 'Settings migration (Dry Run) failed due to an unexpected error.'
                : 'Settings migration failed due to an unexpected error.';
            WP_CLI::error($message);
        }
    }

    /**
     * Migrates V4 sources.
     *
     * ## OPTIONS
     *
     * [--yes]
     * : Answer yes to the confirmation prompt.
     *
     * ## EXAMPLES
     *
     *     wp rss v4_migration run_sources
     *     wp rss v4_migration run_sources --yes
     *
     * @subcommand run_sources
     * @param array $args Positional arguments.
     * @param array $assoc_args Associative arguments.
     */
    public function run_sources($args, $assoc_args) // phpcs:ignore VariableAnalysis.CodeAnalysis.VariableAnalysis.UnusedVariable
    {
        if (!WP_CLI\Utils\get_flag_value($assoc_args, 'yes', false)) {
            if (!$this->confirm('Are you sure you want to migrate V4 sources?')) {
                WP_CLI::log('Sources migration aborted by user.');
                return;
            }
        }

        WP_CLI::line(WP_CLI::colorize('%CMigrating sources...%n'));
        try {
            $results = $this->migrator->sources->migrateAll(WP_CLI\Utils\get_flag_value($assoc_args, 'dry-run', false));
            $successCount = 0;
            $failureCount = 0;
            foreach ($results as $result) {
                if ($result->isOk()) {
                    $successCount++;
                } else {
                    $failureCount++;
                    $error = $result->getErr();
                    $errorMessage = $error instanceof \Throwable ? $error->getMessage() : (string) $error;
                    WP_CLI::warning(sprintf('Failed to migrate a source: %s', $errorMessage));
                }
            }

            if ($failureCount > 0) {
                WP_CLI::warning(sprintf('%d source(s) migrated successfully, %d failed.', $successCount, $failureCount));
            } else {
                WP_CLI::success(sprintf('All %d source(s) migrated successfully.', $successCount));
            }
        } catch (\Exception $e) {
            $this->printCliException($e);
            WP_CLI::error('Sources migration failed due to an unexpected error.');
        }
    }

    /**
     * Migrates V4 blacklist.
     *
     * ## OPTIONS
     *
     * [--yes]
     * : Answer yes to the confirmation prompt.
     * [--dry-run]
     * : Perform a dry run without actually changing data.
     *
     * ## EXAMPLES
     *
     *     wp rss v4_migration run_blacklist
     *     wp rss v4_migration run_blacklist --yes
     *     wp rss v4_migration run_blacklist --dry-run
     *
     * @subcommand run_blacklist
     * @param array $args Positional arguments.
     * @param array $assoc_args Associative arguments.
     */
    public function run_blacklist($args, $assoc_args) // phpcs:ignore VariableAnalysis.CodeAnalysis.VariableAnalysis.UnusedVariable
    {
        if (!WP_CLI\Utils\get_flag_value($assoc_args, 'yes', false)) {
            if (!$this->confirm('Are you sure you want to migrate V4 blacklist?')) {
                WP_CLI::log('Blacklist migration aborted by user.');
                return;
            }
        }

        WP_CLI::line(WP_CLI::colorize('%CMigrating blacklist...%n'));
        try {
            $results = $this->migrator->blacklist->migrateAll(WP_CLI\Utils\get_flag_value($assoc_args, 'dry-run', false));
            $successCount = 0;
            $failureCount = 0;
            foreach ($results as $result) {
                if ($result->isOk()) {
                    $successCount++;
                } else {
                    $failureCount++;
                    $error = $result->getErr();
                    $errorMessage = $error instanceof \Throwable ? $error->getMessage() : (string) $error;
                    WP_CLI::warning(sprintf('Failed to migrate a blacklist item: %s', $errorMessage));
                }
            }

            if ($failureCount > 0) {
                WP_CLI::warning(sprintf('%d blacklist item(s) migrated successfully, %d failed.', $successCount, $failureCount));
            } else {
                WP_CLI::success(sprintf('All %d blacklist item(s) migrated successfully.', $successCount));
            }
        } catch (\Exception $e) {
            $this->printCliException($e);
            WP_CLI::error('Blacklist migration failed due to an unexpected error.');
        }
    }

    /**
     * Migrates V4 templates.
     *
     * ## OPTIONS
     *
     * [--yes]
     * : Answer yes to the confirmation prompt.
     * [--dry-run]
     * : Perform a dry run without actually changing data.
     *
     * ## EXAMPLES
     *
     *     wp rss v4_migration run_templates
     *     wp rss v4_migration run_templates --yes
     *     wp rss v4_migration run_templates --dry-run
     *
     * @subcommand run_templates
     * @param array $args Positional arguments.
     * @param array $assoc_args Associative arguments.
     */
    public function run_templates($args, $assoc_args) // phpcs:ignore VariableAnalysis.CodeAnalysis.VariableAnalysis.UnusedVariable
    {
        if (!WP_CLI\Utils\get_flag_value($assoc_args, 'yes', false)) {
            if (!$this->confirm('Are you sure you want to migrate V4 templates?')) {
                WP_CLI::log('Templates migration aborted by user.');
                return;
            }
        }

        WP_CLI::line(WP_CLI::colorize('%CMigrating templates...%n'));
        try {
            $results = $this->migrator->templates->migrateAll(WP_CLI\Utils\get_flag_value($assoc_args, 'dry-run', false));
            $successCount = 0;
            $failureCount = 0;
            foreach ($results as $result) {
                if ($result->isOk()) {
                    $successCount++;
                } else {
                    $failureCount++;
                    $error = $result->getErr();
                    $errorMessage = $error instanceof \Throwable ? $error->getMessage() : (string) $error;
                    WP_CLI::warning(sprintf('Failed to migrate a template: %s', $errorMessage));
                }
            }
            if ($failureCount > 0) {
                WP_CLI::warning(sprintf('%d template(s) migrated successfully, %d failed.', $successCount, $failureCount));
            } else {
                WP_CLI::success(sprintf('All %d template(s) migrated successfully.', $successCount));
            }
        } catch (\Exception $e) {
            $this->printCliException($e);
            WP_CLI::error('Templates migration failed due to an unexpected error.');
        }
    }

    /**
     * Migrates V4 items.
     *
     * ## OPTIONS
     *
     * [--yes]
     * : Answer yes to the confirmation prompt.
     * [--dry-run]
     * : Perform a dry run without actually changing data.
     *
     * ## EXAMPLES
     *
     *     wp rss v4_migration run_items
     *     wp rss v4_migration run_items --yes
     *     wp rss v4_migration run_items --dry-run
     *
     * @subcommand run_items
     * @param array $args Positional arguments.
     * @param array $assoc_args Associative arguments.
     */
    public function run_items($args, $assoc_args) // phpcs:ignore VariableAnalysis.CodeAnalysis.VariableAnalysis.UnusedVariable
    {
        if (!WP_CLI\Utils\get_flag_value($assoc_args, 'yes', false)) {
            if (!$this->confirm('Are you sure you want to migrate V4 items?')) {
                WP_CLI::log('Items migration aborted by user.');
                return;
            }
        }

        WP_CLI::line(WP_CLI::colorize('%CMigrating items...%n'));
        try {
            // The V4ItemMigrator->migrateAll() returns a generator of migrated post IDs or potentially errors.
            // It handles its own logging for success/failure counts internally.
            $itemCount = 0;
            $migrator = $this->migrator->items->migrateAll(WP_CLI\Utils\get_flag_value($assoc_args, 'dry-run', false));
            foreach ($migrator as $_postId) {
                $itemCount++;
                if ($itemCount % 100 === 0) { // Log progress periodically
                    WP_CLI::log(sprintf('Processed %d items...', $itemCount));
                }
            }
            // V4ItemMigrator logs its own final success/warning.
            // We can add a general success message here if needed, but it might be redundant.
            WP_CLI::log(sprintf('Finished processing all items. %d items were iterated over.', $itemCount));
            WP_CLI::success('Items migration process completed.');
        } catch (\Exception $e) {
            $this->printCliException($e);
            WP_CLI::error('Items migration failed due to an unexpected error.');
        }
    }

    /**
     * Uninstalls V4 data.
     *
     * This command will remove V4 specific options, posts, and terms.
     * This action is destructive and cannot be undone.
     *
     * ## OPTIONS
     *
     * [--yes]
     * : Answer yes to the confirmation prompt.
     *
     * ## EXAMPLES
     *
     *     wp rss v4_migration uninstall
     *     wp rss v4_migration uninstall --yes
     *
     * @subcommand uninstall
     * @param array $args Positional arguments.
     * @param array $assoc_args Associative arguments.
     */
    public function uninstall($args, $assoc_args) // phpcs:ignore VariableAnalysis.CodeAnalysis.VariableAnalysis.UnusedVariable
    {
        if (!WP_CLI\Utils\get_flag_value($assoc_args, 'yes', false)) {
            if (!$this->confirm(WP_CLI::colorize('%RAre you absolutely sure you want to uninstall all V4 data? This action cannot be undone.%n'))) {
                WP_CLI::log('Uninstall aborted by user.');
                return;
            }
        }

        WP_CLI::line(WP_CLI::colorize('%YUninstalling V4 data...%n'));
        try {
            $this->migrator->uninstall();
            WP_CLI::success('V4 data uninstalled successfully.');
        } catch (\Exception $e) {
            $this->printCliException($e);
            WP_CLI::error('V4 data uninstall failed due to an unexpected error.');
        }
    }

    /**
     * Rolls back the V5 migration.
     *
     * This command attempts to revert the `wprss_enable_v5` option to '0',
     * effectively re-enabling V4 functionality if V4 code is still present.
     * It does not re-migrate or restore data modified by the V5 migration.
     *
     * ## OPTIONS
     *
     * [--yes]
     * : Answer yes to the confirmation prompt.
     *
     * ## EXAMPLES
     *
     *     wp rss v4_migration rollback
     *     wp rss v4_migration rollback --yes
     *
     * @subcommand rollback
     * @param array $args Positional arguments.
     * @param array $assoc_args Associative arguments.
     */
    public function rollback($args, $assoc_args) // phpcs:ignore VariableAnalysis.CodeAnalysis.VariableAnalysis.UnusedVariable
    {
        if (!WP_CLI\Utils\get_flag_value($assoc_args, 'yes', false)) {
            if (!$this->confirm('Are you sure you want to rollback the V5 migration? This will attempt to re-enable V4 functionality.')) {
                WP_CLI::log('Rollback aborted by user.');
                return;
            }
        }

        WP_CLI::line(WP_CLI::colorize('%YRolling back V5 migration...%n'));
        try {
            $this->migrator->rollback();
            WP_CLI::success('V5 migration rolled back. The `wprss_enable_v5` option has been set to "0".');
            WP_CLI::log('Please ensure V4 plugin files are in place if you intend to use V4 functionality.');
        } catch (\Exception $e) {
            $this->printCliException($e);
            WP_CLI::error('V5 migration rollback failed due to an unexpected error.');
        }
    }
}
