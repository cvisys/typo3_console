<?php
namespace Helhum\Typo3Console\Command;

/*
 * This file is part of the TYPO3 Console project.
 *
 * It is free software; you can redistribute it and/or modify it under
 * the terms of the GNU General Public License, either version 2
 * of the License, or any later version.
 *
 * For the full copyright and license information, please read
 * LICENSE file that was distributed with this source code.
 *
 */

use Helhum\Typo3Console\Extension\ExtensionConstraintCheck;
use Helhum\Typo3Console\Install\Upgrade\UpgradeHandling;
use Helhum\Typo3Console\Install\Upgrade\UpgradeWizardListRenderer;
use Helhum\Typo3Console\Install\Upgrade\UpgradeWizardResultRenderer;
use Helhum\Typo3Console\Mvc\Controller\CommandController;
use TYPO3\CMS\Core\Package\Exception\UnknownPackageException;
use TYPO3\CMS\Core\Package\PackageManager;

class UpgradeCommandController extends CommandController
{
    /**
     * @var PackageManager
     */
    private $packageManager;

    /**
     * @var UpgradeHandling
     */
    private $upgradeHandling;

    /**
     * @param PackageManager $packageManager
     * @param UpgradeHandling|null $upgradeHandling
     */
    public function __construct(
        PackageManager $packageManager,
        UpgradeHandling $upgradeHandling = null
    ) {
        $this->packageManager = $packageManager;
        $this->upgradeHandling = $upgradeHandling ?: new UpgradeHandling();
    }

    /**
     * Check TYPO3 version constraints of extensions
     *
     * This command is especially useful **before** switching sources to a new TYPO3 version.
     * I checks the version constraints of all third party extensions against a given TYPO3 version.
     * It therefore relies on the constraints to be correct.
     *
     * @param array $extensionKeys Extension keys to check. Separate multiple extension keys with comma.
     * @param string $typo3Version TYPO3 version to check against. Defaults to current TYPO3 version.
     */
    public function checkExtensionConstraintsCommand(array $extensionKeys = [], $typo3Version = TYPO3_version)
    {
        $this->packageManager->scanAvailablePackages();
        if (empty($extensionKeys)) {
            $packagesToCheck = $this->packageManager->getActivePackages();
        } else {
            $packagesToCheck = [];
            foreach ($extensionKeys as $extensionKey) {
                try {
                    $packagesToCheck[] = $this->packageManager->getPackage($extensionKey);
                } catch (UnknownPackageException $e) {
                    $this->outputLine('<warning>Extension "%s" is not found in the system</warning>', [$extensionKey]);
                }
            }
        }
        $extensionConstraintCheck = new ExtensionConstraintCheck();
        $checkFailed = false;
        foreach ($packagesToCheck as $package) {
            if (strpos($package->getPackagePath(), 'typo3conf/ext') === false) {
                continue;
            }
            $constraintMessage = $extensionConstraintCheck->matchConstraints($package, $typo3Version);
            if (!empty($constraintMessage)) {
                $this->outputLine('<error>%s</error>', [$constraintMessage]);
                $checkFailed = true;
            }
        }
        if (!$checkFailed) {
            $this->outputLine('<info>All third party extensions claim to be compatible with TYPO3 version %s</info>', [$typo3Version]);
        } else {
            $this->quit(1);
        }
    }

    /**
     * List upgrade wizards
     *
     * @param bool $verbose If set, a more verbose description for each wizard is shown, if not set only the title is shown
     * @param bool $all If set, all wizards will be listed, even the once marked as ready or done
     */
    public function listCommand($verbose = false, $all = false)
    {
        $wizards = $this->upgradeHandling->executeInSubProcess('listWizards');

        $listRenderer = new UpgradeWizardListRenderer();
        $this->outputLine('<comment>Wizards scheduled for execution:</comment>');
        $listRenderer->render($wizards['scheduled'], $this->output, $verbose);

        if ($all) {
            $this->outputLine(PHP_EOL . '<comment>Wizards marked as done:</comment>');
            $listRenderer->render($wizards['done'], $this->output, $verbose);
        }
    }

    /**
     * Execute a single upgrade wizard
     *
     * @param string $identifier Identifier of the wizard that should be executed
     * @param array $arguments Arguments for the wizard prefixed with the identifier, e.g. <code>compatibility7Extension[install]=0</code>
     * @param bool $force Force execution, even if the wizard has been marked as done
     */
    public function wizardCommand($identifier, array $arguments = [], $force = false)
    {
        $result = $this->upgradeHandling->executeInSubProcess('executeWizard', [$identifier, $arguments, $force]);
        (new UpgradeWizardResultRenderer())->render([$identifier => $result], $this->output);
    }

    /**
     * Execute all upgrade wizards that are scheduled for execution
     *
     * @param array $arguments Arguments for the wizard prefixed with the identifier, e.g. <code>compatibility7Extension[install]=0</code>; multiple arguments separated with comma
     * @param bool $verbose If set, output of the wizards will be shown, including all SQL Queries that were executed
     */
    public function allCommand(array $arguments = [], $verbose = false)
    {
        $this->outputLine(PHP_EOL . '<i>Initiating TYPO3 upgrade</i>' . PHP_EOL);

        $results = $this->upgradeHandling->executeAll($arguments, $this->output);

        $this->outputLine(PHP_EOL . PHP_EOL . '<i>Successfully upgraded TYPO3 to version %s</i>', [TYPO3_version]);

        if ($verbose) {
            $this->outputLine();
            $this->outputLine('<comment>Upgrade report:</comment>');
            (new UpgradeWizardResultRenderer())->render($results, $this->output);
        }
    }

    /**
     * This is where the hard work happens in a fully bootstrapped TYPO3
     * It will be called as sub process
     *
     * @param string $command
     * @param string $arguments Serialized arguments
     * @internal
     */
    public function subProcessCommand($command, $arguments)
    {
        $arguments = unserialize($arguments);
        $result = call_user_func_array([$this->upgradeHandling, $command], $arguments);
        $this->output(serialize($result));
    }
}
